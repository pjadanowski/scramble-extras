<?php

namespace PawelJadanowski\ScrambleExtras;

use Dedoc\Scramble\Extensions\OperationExtension;
use Dedoc\Scramble\Infer\Services\FileNameResolver;
use Dedoc\Scramble\Support\Generator\Operation;
use Dedoc\Scramble\Support\Generator\Parameter;
use Dedoc\Scramble\Support\Generator\Schema;
use Dedoc\Scramble\Support\Generator\Types\IntegerType as OpenApiIntegerType;
use Dedoc\Scramble\Support\Generator\Types\StringType as OpenApiStringType;
use Dedoc\Scramble\Support\RouteInfo;
use Illuminate\Database\Eloquent\Model;
use PhpParser\NodeTraverser;
use ReflectionClass;
use ReflectionEnum;

/**
 * Walks a controller method's AST for spatie/laravel-query-builder usage and
 * adds matching query parameters to the OpenAPI operation.
 *
 * Extracts:
 *  - allowedFilters (string literals + AllowedFilter::exact/partial/scope/...)
 *  - allowedSorts, allowedIncludes, allowedFields
 *  - defaultSort (-> sort param default)
 *  - jsonPaginate (-> page[number]/page[size])
 *  - the model class from QueryBuilder::for(Model::class) (used to detect
 *    enum-cast columns and emit `enum` on exact filters).
 */
class QueryBuilderOperationExtension extends OperationExtension
{
    public function handle(Operation $operation, RouteInfo $routeInfo): void
    {
        $methodNode = $routeInfo->methodNode();
        if ($methodNode === null || $methodNode->stmts === null) {
            return;
        }

        $resolver = $this->makeNameResolver($routeInfo);

        $finder = new QueryBuilderUsageVisitor($resolver);
        $traverser = new NodeTraverser;
        $traverser->addVisitor($finder);
        $traverser->traverse($methodNode->stmts);

        if (! $finder->found) {
            return;
        }

        // A parameter the route already documents (a #[QueryParameter]
        // attribute, a Data property) is more specific than the generic one
        // derived here, so it wins instead of being listed twice.
        $params = array_values(array_filter(
            $this->buildParameters($finder),
            fn (Parameter $new) => ! $this->isDocumented($operation, $new),
        ));

        if ($params !== []) {
            $operation->addParameters($params);
        }
    }

    protected function isDocumented(Operation $operation, Parameter $parameter): bool
    {
        foreach ($operation->parameters as $existing) {
            if ($existing instanceof Parameter && $existing->name === $parameter->name && $existing->in === $parameter->in) {
                return true;
            }
        }

        return false;
    }

    protected function makeNameResolver(RouteInfo $routeInfo): ?FileNameResolver
    {
        $reflection = $routeInfo->reflectionMethod();
        if (! $reflection) {
            return null;
        }

        $file = $reflection->getDeclaringClass()->getFileName();

        return $file ? FileNameResolver::createForFile($file) : null;
    }

    /**
     * @return Parameter[]
     */
    protected function buildParameters(QueryBuilderUsageVisitor $finder): array
    {
        $parameters = [];
        $modelEnumCasts = $finder->modelClass ? $this->resolveEnumCasts($finder->modelClass) : [];

        foreach ($finder->filters as $filter) {
            $parameters[] = $this->buildFilterParameter($filter, $modelEnumCasts);
        }

        if ($finder->sorts !== []) {
            $description = 'Sort the result. Prefix with `-` for descending order. Multiple values can be passed, separated via `,`. Allowed: `'
                .implode('`, `', $finder->sorts).'`';

            $sortType = new OpenApiStringType;
            $param = (new Parameter('sort', 'query'))
                ->description($description)
                ->setSchema(Schema::fromType($sortType));

            if ($finder->defaultSort !== null) {
                $sortType->default($finder->defaultSort);
            }

            $parameters[] = $param;
        }

        $includes = $this->expandIncludes($finder->includeEntries);
        if ($includes !== []) {
            $description = 'Include related resources. Multiple values can be passed, separated via `,`. Allowed: `'
                .implode('`, `', $includes).'`';

            $parameters[] = (new Parameter('include', 'query'))
                ->description($description)
                ->setSchema(Schema::fromType(new OpenApiStringType));
        }

        $groupedFields = $this->groupFieldsByResource($finder->fields);
        foreach ($groupedFields as $resource => $fields) {
            $key = $resource === '_root' ? 'fields' : "fields[$resource]";
            $description = 'Sparse fieldset. Comma separated. Allowed: `'.implode('`, `', $fields).'`';

            $parameters[] = (new Parameter($key, 'query'))
                ->description($description)
                ->setSchema(Schema::fromType(new OpenApiStringType));
        }

        if ($finder->jsonPaginate) {
            $parameters[] = (new Parameter('page[number]', 'query'))
                ->description('Page number (starts at 1).')
                ->setSchema(Schema::fromType((new OpenApiIntegerType)->default(1)));

            $parameters[] = (new Parameter('page[size]', 'query'))
                ->description('Number of items per page.')
                ->setSchema(Schema::fromType(new OpenApiIntegerType));
        }

        return $parameters;
    }

    /**
     * @param  array{name: string, kind: string, column?: string, description?: string, example?: mixed, format?: string}  $filter
     * @param  array<string, array<int, scalar>>  $modelEnumCasts
     */
    protected function buildFilterParameter(array $filter, array $modelEnumCasts): Parameter
    {
        $type = new OpenApiStringType;

        if (isset($filter['format'])) {
            $type->format($filter['format']);
        }

        // exact filter on an enum-cast column → emit enum schema. An aliased
        // filter (`exact('state', 'status')`) is cast by its internal column.
        $column = $filter['column'] ?? $filter['name'];
        if ($filter['kind'] === 'exact' && isset($modelEnumCasts[$column])) {
            $type->enum($modelEnumCasts[$column]);
        }

        $description = $this->describeFilter($filter);

        $parameter = (new Parameter("filter[{$filter['name']}]", 'query'))
            ->description($description)
            ->setSchema(Schema::fromType($type));

        if (isset($filter['example'])) {
            $parameter->example($filter['example']);
        }

        return $parameter;
    }

    /**
     * @param  array{name: string, kind: string, description?: string}  $filter
     */
    protected function describeFilter(array $filter): string
    {
        $base = trim($filter['description'] ?? '');

        $kindNote = match ($filter['kind']) {
            'exact' => 'Exact match.',
            'partial' => 'Partial (LIKE %value%) match.',
            'beginsWithStrict' => 'Begins-with match.',
            'endsWithStrict' => 'Ends-with match.',
            'belongsTo' => 'Filters related belongsTo records.',
            'scope' => 'Applies a model scope.',
            'callback' => 'Applies a custom callback filter.',
            'trashed' => 'Filters soft-deleted records.',
            'operator' => 'Operator filter.',
            'custom' => 'Custom filter class.',
            default => '',
        };

        return trim($base.($base !== '' && $kindNote !== '' ? ' ' : '').$kindNote);
    }

    /**
     * @return array<string, array<int, scalar>>
     */
    protected function resolveEnumCasts(string $modelClass): array
    {
        if (! class_exists($modelClass) || ! is_subclass_of($modelClass, Model::class)) {
            return [];
        }

        try {
            $instance = (new ReflectionClass($modelClass))->newInstanceWithoutConstructor();
            if (! $instance instanceof Model) {
                return [];
            }
            $casts = $instance->getCasts();

            // Laravel 11+ declares casts in a casts() method, merged into
            // $casts by the model constructor - which isn't run here.
            if (method_exists($instance, 'casts')) {
                $castsMethod = new \ReflectionMethod($instance, 'casts');
                $casts = array_merge($casts, (array) $castsMethod->invoke($instance));
            }
        } catch (\Throwable) {
            return [];
        }

        $result = [];
        foreach ($casts as $column => $castTarget) {
            if (! is_string($castTarget) || ! enum_exists($castTarget)) {
                continue;
            }

            try {
                $reflection = new ReflectionEnum($castTarget);
            } catch (\ReflectionException) {
                continue;
            }

            if (! $reflection->isBacked()) {
                continue;
            }

            $result[$column] = array_map(
                fn (\BackedEnum $case) => $case->value,
                $castTarget::cases(),
            );
        }

        return $result;
    }

    /**
     * The include names the query builder actually allows for the declared
     * includes (same rules in spatie/laravel-query-builder 6 and 7):
     *  - a string ending in the count/exists suffix allows only itself;
     *  - any other string allows every segment of its path (`a.b` allows `a`
     *    and `a.b`) plus `{segment}Count` / `{segment}Exists` for the
     *    top-level segment;
     *  - AllowedInclude::relationship() expands the same way in v6, and
     *    allows only its own name in v7;
     *  - other builders (count, exists, callback, custom) allow their name.
     *
     * @param  array<int, array{name: string, kind: string}>  $entries
     * @return array<int, string>
     */
    protected function expandIncludes(array $entries): array
    {
        $countSuffix = config('query-builder.suffixes.count') ?? config('query-builder.count_suffix') ?? 'Count';
        $existsSuffix = config('query-builder.suffixes.exists') ?? config('query-builder.exists_suffix') ?? 'Exists';

        $includes = [];

        foreach ($entries as ['name' => $name, 'kind' => $kind]) {
            $expands = match ($kind) {
                'string' => ! str_ends_with($name, $countSuffix) && ! str_ends_with($name, $existsSuffix),
                'relationship' => $this->relationshipBuilderExpands(),
                default => false,
            };

            if (! $expands) {
                $includes[] = $name;

                continue;
            }

            $path = [];
            foreach (explode('.', $name) as $segment) {
                $path[] = $segment;
                $includes[] = implode('.', $path);

                if (count($path) === 1) {
                    $includes[] = $segment.$countSuffix;
                    $includes[] = $segment.$existsSuffix;
                }
            }
        }

        return array_values(array_unique($includes));
    }

    /**
     * In v6, AllowedInclude::relationship() returns a collection of the
     * relationship plus its count/exists includes; in v7 a single include.
     */
    protected function relationshipBuilderExpands(): bool
    {
        if (! class_exists(\Spatie\QueryBuilder\AllowedInclude::class)) {
            return false;
        }

        $returnType = (new \ReflectionMethod(\Spatie\QueryBuilder\AllowedInclude::class, 'relationship'))->getReturnType();

        return $returnType instanceof \ReflectionNamedType && is_a($returnType->getName(), \Illuminate\Support\Collection::class, true);
    }

    /**
     * @param  array<int, string>  $fields
     * @return array<string, array<int, string>>
     */
    protected function groupFieldsByResource(array $fields): array
    {
        $groups = [];
        foreach ($fields as $field) {
            if (str_contains($field, '.')) {
                [$resource, $rest] = explode('.', $field, 2);
                $groups[$resource][] = $rest;
            } else {
                $groups['_root'][] = $field;
            }
        }

        return $groups;
    }
}
