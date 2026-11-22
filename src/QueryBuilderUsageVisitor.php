<?php

namespace PawelJadanowski\ScrambleExtras;

use Dedoc\Scramble\Infer\Services\FileNameResolver;
use PhpParser\Node;
use Illuminate\Database\Eloquent\Model;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\Assign;
use PhpParser\Node\Expr\ClassConstFetch;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use PhpParser\Node\Scalar\String_;
use PhpParser\NodeVisitorAbstract;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\AllowedInclude;
use Spatie\QueryBuilder\AllowedSort;
use Spatie\QueryBuilder\QueryBuilder;

/**
 * AST visitor that collects spatie/laravel-query-builder configuration from a
 * controller method body: the model passed to `QueryBuilder::for()`, plus the
 * allowed filters/sorts/includes/fields, `defaultSort`, and `jsonPaginate`.
 *
 * Doc-comment attributes (`@example`, `@format`, free-text description) on an
 * individual filter entry are picked up and attached to that filter.
 *
 * One visitor can walk several methods (see QueryBuilderOperationExtension):
 * it collects the methods worth following next - `$this->x()` /
 * `self::x()` / `static::x()` calls and the code of QueryBuilder subclasses
 * the method instantiates - in $pendingMethods. Lists assigned to a local
 * variable (`$filters = [...]`) are resolved within the method.
 */
class QueryBuilderUsageVisitor extends NodeVisitorAbstract
{
    public bool $found = false;

    public bool $jsonPaginate = false;

    public ?string $modelClass = null;

    public ?string $defaultSort = null;

    /** @var array<int, array{name: string, kind: string, column?: string, description?: string, example?: mixed, format?: string}> */
    public array $filters = [];

    /** @var array<int, string> */
    public array $sorts = [];

    /** @var array<int, string> */
    public array $includes = [];

    /** @var array<int, string> */
    public array $fields = [];

    /**
     * Every include as declared, with how it was declared - the query
     * builder expands a string and AllowedInclude::relationship() into more
     * includes (see QueryBuilderOperationExtension::expandIncludes()).
     *
     * @var array<int, array{name: string, kind: 'string'|'relationship'|'builder'}>
     */
    public array $includeEntries = [];

    /**
     * Methods to walk next, as [class, method].
     *
     * @var array<int, array{0: class-string, 1: string}>
     */
    public array $pendingMethods = [];

    /** The class whose method is being walked. */
    protected ?string $currentClass = null;

    /** @var array<string, Node\Expr> local variable => last assigned value */
    protected array $variables = [];

    public function __construct(protected ?FileNameResolver $nameResolver) {}

    /**
     * Walk a method of $class next: names resolve against that class's file.
     */
    public function enterClass(?string $class, ?FileNameResolver $nameResolver): void
    {
        $this->currentClass = $class;
        $this->nameResolver = $nameResolver;
    }

    public function beforeTraverse(array $nodes)
    {
        $this->variables = [];

        return null;
    }

    public function enterNode(Node $node)
    {
        if ($node instanceof Assign && $node->var instanceof Variable && is_string($node->var->name)) {
            $this->variables[$node->var->name] = $node->expr;
        }

        if ($node instanceof StaticCall && $this->isQueryBuilderFor($node)) {
            $this->found = true;
            $this->modelClass ??= $this->extractModelClass($node->args[0]->value ?? null);
        }

        if ($node instanceof StaticCall || $node instanceof New_) {
            $this->collectFollowUp($node);
        }

        if ($node instanceof MethodCall && $node->var instanceof Variable && $node->var->name === 'this'
            && $node->name instanceof Identifier && $this->currentClass !== null) {
            $this->pendingMethods[] = [$this->currentClass, $node->name->name];
        }

        if (! $node instanceof MethodCall) {
            return null;
        }

        $methodName = $node->name instanceof Identifier ? $node->name->name : null;
        if ($methodName === null) {
            return null;
        }

        switch ($methodName) {
            case 'allowedFilters':
                $this->filters = array_merge($this->filters, $this->extractFilterEntries($node));
                break;
            case 'allowedSorts':
                $this->sorts = array_merge($this->sorts, $this->extractBuilderNames($node, AllowedSort::class, 'AllowedSort'));
                break;
            case 'allowedIncludes':
                $this->includes = array_merge($this->includes, $this->extractIncludes($node));
                break;
            case 'allowedFields':
                $this->fields = array_merge($this->fields, $this->extractStringList($node));
                break;
            case 'defaultSort':
                $this->defaultSort = $this->extractFirstString($node);
                break;
            case 'jsonPaginate':
                $this->jsonPaginate = true;
                break;
        }

        return null;
    }

    /**
     * `self::x()` / `static::x()` / `new static` continue in the current
     * class; `new
     * SomeQuery` / `SomeQuery::make()` on a QueryBuilder subclass mean that
     * subclass configures the query, so its constructor / that method are
     * walked. `parent::__construct(User::query())` in such a subclass names
     * the model.
     */
    protected function collectFollowUp(StaticCall|New_ $node): void
    {
        $method = $node instanceof New_
            ? '__construct'
            : ($node->name instanceof Identifier ? $node->name->name : null);

        if ($method === null || ! $node->class instanceof Node\Name) {
            return;
        }

        $keyword = strtolower($node->class->toString());

        if (in_array($keyword, ['self', 'static'], true)) {
            if ($this->currentClass !== null) {
                $this->pendingMethods[] = [$this->currentClass, $method];
            }

            return;
        }

        if ($keyword === 'parent') {
            if ($method === '__construct' && $this->currentClass !== null && is_a($this->currentClass, QueryBuilder::class, true)) {
                $this->found = true;
                $this->modelClass ??= $this->extractModelClass($node->args[0]->value ?? null);
            }

            return;
        }

        $class = $this->resolveClassName($node->class);

        if ($method !== 'for' && $class !== QueryBuilder::class && class_exists($class) && is_subclass_of($class, QueryBuilder::class)) {
            $this->found = true;
            $this->pendingMethods[] = [$class, $method];
        }
    }

    protected function isQueryBuilderFor(StaticCall $node): bool
    {
        $method = $node->name instanceof Identifier ? $node->name->name : null;
        if ($method !== 'for') {
            return false;
        }

        return $this->isClass($node->class, QueryBuilder::class, 'QueryBuilder');
    }

    /**
     * @return array<int, array{name: string, kind: string, description?: string, example?: mixed, format?: string}>
     */
    protected function extractFilterEntries(MethodCall $node): array
    {
        $result = [];

        foreach ($node->args as $arg) {
            $expr = $this->resolveVariable($arg->value);

            if ($expr instanceof Array_) {
                foreach ($expr->items as $item) {
                    if (! $item) {
                        continue;
                    }
                    $entry = $this->buildFilterEntry($this->resolveVariable($item->value), $item);
                    if ($entry !== null) {
                        $result[] = $entry;
                    }
                }
            } else {
                $entry = $this->buildFilterEntry($expr, $arg);
                if ($entry !== null) {
                    $result[] = $entry;
                }
            }
        }

        return $result;
    }

    /**
     * @return array{name: string, kind: string, description?: string, example?: mixed, format?: string}|null
     */
    protected function buildFilterEntry(?Node $expr, Node $hostForDoc): ?array
    {
        if ($expr instanceof String_) {
            return $this->withDocAttributes(['name' => $expr->value, 'kind' => 'partial'], $hostForDoc);
        }

        // AllowedFilter::exact('x')->default(...)->ignore(...): the filter is
        // defined by the static call at the root of the chain.
        $expr = $this->chainRoot($expr);

        if ($expr instanceof StaticCall && $this->isClass($expr->class, AllowedFilter::class, 'AllowedFilter')) {
            $kind = $expr->name instanceof Identifier ? $expr->name->name : null;
            $name = $this->extractFirstString($expr);

            if ($kind === null || $name === null) {
                return null;
            }

            $entry = ['name' => $name, 'kind' => $kind];

            // exact('name', 'internal_column'): the cast lives on the column.
            $internalName = $expr->args[1]->value ?? null;
            if ($internalName instanceof String_) {
                $entry['column'] = $internalName->value;
            }

            return $this->withDocAttributes($entry, $hostForDoc);
        }

        return null;
    }

    /**
     * Public names from a list of string literals and/or builder calls
     * (`AllowedSort::field('name', 'column')->defaultDirection(...)`).
     *
     * @return array<int, string>
     */
    protected function extractBuilderNames(MethodCall $node, string $builderClass, string $shortName): array
    {
        $names = [];

        foreach ($this->listItems($node) as $expr) {
            if ($expr instanceof String_) {
                $names[] = $expr->value;

                continue;
            }

            $root = $this->chainRoot($expr);
            if ($root instanceof StaticCall && $this->isClass($root->class, $builderClass, $shortName)) {
                $name = $this->extractFirstString($root);
                if ($name !== null) {
                    $names[] = $name;
                }
            }
        }

        return $names;
    }

    /**
     * Include names as declared; each is also recorded in $includeEntries
     * with how it was declared.
     *
     * @return array<int, string>
     */
    protected function extractIncludes(MethodCall $node): array
    {
        $includes = [];

        foreach ($this->listItems($node) as $expr) {
            if ($expr instanceof String_) {
                $includes[] = $expr->value;
                $this->includeEntries[] = ['name' => $expr->value, 'kind' => 'string'];

                continue;
            }

            $root = $this->chainRoot($expr);
            if (! $root instanceof StaticCall || ! $this->isClass($root->class, AllowedInclude::class, 'AllowedInclude')) {
                continue;
            }

            $name = $this->extractFirstString($root);
            if ($name === null) {
                continue;
            }

            $isRelationship = $root->name instanceof Identifier && $root->name->name === 'relationship';
            $includes[] = $name;
            $this->includeEntries[] = ['name' => $name, 'kind' => $isRelationship ? 'relationship' : 'builder'];
        }

        return $includes;
    }

    /**
     * The expressions passed to a variadic-or-array method like
     * allowedSorts('a', 'b') / allowedSorts(['a', 'b']).
     *
     * @return array<int, Node\Expr>
     */
    protected function listItems(MethodCall $node): array
    {
        $items = [];

        foreach ($node->args as $arg) {
            if (! $arg instanceof Node\Arg) {
                continue;
            }

            $value = $this->resolveVariable($arg->value);

            if ($value instanceof Array_) {
                foreach ($value->items as $item) {
                    if ($item) {
                        $items[] = $this->resolveVariable($item->value);
                    }
                }
            } else {
                $items[] = $value;
            }
        }

        return $items;
    }

    /**
     * The value last assigned to a local variable earlier in the method.
     */
    protected function resolveVariable(Node $expr): Node
    {
        if ($expr instanceof Variable && is_string($expr->name) && isset($this->variables[$expr->name])) {
            return $this->variables[$expr->name];
        }

        return $expr;
    }

    protected function chainRoot(?Node $expr): ?Node
    {
        while ($expr instanceof MethodCall) {
            $expr = $expr->var;
        }

        return $expr;
    }

    protected function isClass(Node $class, string $fqcn, string $shortName): bool
    {
        $resolved = $this->resolveClassName($class);

        return $resolved === $fqcn
            || $resolved === $shortName
            || ($resolved !== '' && class_exists($resolved) && is_a($resolved, $fqcn, true));
    }

    /**
     * @param  array{name: string, kind: string}  $entry
     * @return array{name: string, kind: string, description?: string, example?: mixed, format?: string}
     */
    protected function withDocAttributes(array $entry, Node $host): array
    {
        $doc = $host->getDocComment();
        if ($doc !== null) {
            $text = $doc->getText();
            if (preg_match('/@example\s+([^\n*]+)/', $text, $m)) {
                $entry['example'] = trim($m[1]);
            }
            if (preg_match('/@format\s+([^\n*]+)/', $text, $m)) {
                $entry['format'] = trim($m[1]);
            }
            $description = $this->cleanDocText($text);
            if ($description !== '') {
                $entry['description'] = $description;
            }
        }

        return $entry;
    }

    /**
     * @param  StaticCall|MethodCall  $call
     */
    protected function extractFirstString(Node $call): ?string
    {
        $arg = $call->args[0] ?? null;
        if ($arg && $arg->value instanceof String_) {
            return $arg->value->value;
        }

        return null;
    }

    /**
     * @return array<int, string>
     */
    protected function extractStringList(MethodCall $node): array
    {
        $result = [];

        foreach ($node->args as $arg) {
            $expr = $this->resolveVariable($arg->value);

            if ($expr instanceof Array_) {
                foreach ($expr->items as $item) {
                    if ($item && $item->value instanceof String_) {
                        $result[] = $item->value->value;
                    }
                }
            } elseif ($expr instanceof String_) {
                $result[] = $expr->value;
            }
        }

        return $result;
    }

    /**
     * The model of `QueryBuilder::for(User::class)`, `for(User::query())`
     * or `for(User::where(...))`.
     */
    protected function extractModelClass(?Node $node): ?string
    {
        if ($node instanceof Variable) {
            $node = $this->resolveVariable($node);
        }

        $class = $this->extractClassConst($node);
        if ($class !== null) {
            return $class;
        }

        // User::query() / User::where(...)->...: only a model is a model.
        $root = $this->chainRoot($node);
        if ($root instanceof StaticCall && $root->class instanceof Node\Name) {
            $class = $this->resolveClassName($root->class);

            return class_exists($class) && is_subclass_of($class, Model::class) ? $class : null;
        }

        return null;
    }

    protected function extractClassConst(?Node $node): ?string
    {
        if (! $node instanceof ClassConstFetch) {
            return null;
        }
        if (! ($node->name instanceof Identifier && $node->name->name === 'class')) {
            return null;
        }

        return $this->resolveClassName($node->class) ?: null;
    }

    protected function resolveClassName(Node|string|null $class): string
    {
        if ($class === null) {
            return '';
        }
        if (is_string($class)) {
            return ltrim($class, '\\');
        }
        if (! $class instanceof Node\Name) {
            return '';
        }

        $shortName = $class->toString();

        if ($this->nameResolver) {
            return ($this->nameResolver)($shortName);
        }

        return $shortName;
    }

    protected function cleanDocText(string $doc): string
    {
        $lines = preg_split('/\r?\n/', $doc) ?: [];
        $text = [];
        foreach ($lines as $line) {
            $line = trim($line, " \t/*");
            if ($line === '' || str_starts_with($line, '@')) {
                continue;
            }
            $text[] = $line;
        }

        return trim(implode(' ', $text));
    }
}
