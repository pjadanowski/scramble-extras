<?php

namespace PawelJadanowski\ScrambleExtras;

use Dedoc\Scramble\Infer\Services\FileNameResolver;
use Dedoc\Scramble\PhpDoc\PhpDocTypeHelper;
use Dedoc\Scramble\Support\Generator\Components;
use Dedoc\Scramble\Support\Generator\Reference;
use Dedoc\Scramble\Support\Generator\Types\ArrayType as OpenApiArrayType;
use Dedoc\Scramble\Support\Generator\Types\BooleanType as OpenApiBooleanType;
use Dedoc\Scramble\Support\Generator\Types\IntegerType as OpenApiIntegerType;
use Dedoc\Scramble\Support\Generator\Types\NumberType as OpenApiNumberType;
use Dedoc\Scramble\Support\Generator\Types\ObjectType as OpenApiObjectType;
use Dedoc\Scramble\Support\Generator\Types\StringType as OpenApiStringType;
use Dedoc\Scramble\Support\Generator\Types\Type as OpenApiType;
use Dedoc\Scramble\Support\Generator\Types\UnknownType as OpenApiUnknownType;
use Dedoc\Scramble\Support\Generator\TypeTransformer;
use Dedoc\Scramble\Support\PhpDoc;
use Dedoc\Scramble\Support\Type\ObjectType as ScrambleObjectType;
use ReflectionClass;
use ReflectionNamedType;
use ReflectionProperty;
use ReflectionUnionType;
use Spatie\LaravelData\Attributes\Computed;
use Spatie\LaravelData\Attributes\DataCollectionOf;
use Spatie\LaravelData\Attributes\Hidden;
use Spatie\LaravelData\Optional;
use Spatie\LaravelData\Lazy;

/**
 * Inspects a spatie/laravel-data class and produces an OpenAPI ObjectType.
 *
 * Why: replicates the Pro extension's behavior — without depending on the paid
 * package — for both output (response) and input (request body) schemas.
 */
class LaravelDataReflector
{
    /**
     * Classes handed to the transformer while building the current schema;
     * the cache dependencies of that schema.
     *
     * @var array<int, string>
     */
    protected array $referencedClasses = [];

    public function __construct(
        protected TypeTransformer $openApiTransformer,
        protected Components $components,
    ) {}

    public function buildSchema(string $dataClass, bool $input = false): OpenApiObjectType
    {
        // Only output schemas are cached. They end up as reusable components,
        // which Scramble serializes as-is. Input schemas are inlined into the
        // request body, where Scramble re-assembles the object from its
        // `properties` - which a cached, array-backed schema doesn't have.
        // They are cheap to rebuild anyway.
        $cache = $input ? null : $this->cache();

        if ($cache === null) {
            return $this->doBuildSchema($dataClass, $input);
        }

        $key = $dataClass.'.out';
        $fingerprint = $this->fingerprint($dataClass);
        $entry = $cache->get($key);

        if (
            $entry !== null
            && ($entry['fingerprint'] ?? null) === $fingerprint
            && $this->depsStillResolve($entry['deps'] ?? [])
        ) {
            return new CachedSchemaType($entry['array']);
        }

        $schema = $this->doBuildSchema($dataClass, $input);

        $cache->put($key, [
            'fingerprint' => $fingerprint,
            'deps' => $this->extractDeps(),
            'array' => $schema->toArray(),
        ]);

        return $schema;
    }

    protected function doBuildSchema(string $dataClass, bool $input): OpenApiObjectType
    {
        $this->referencedClasses = [];

        $reflection = new ReflectionClass($dataClass);
        $object = new OpenApiObjectType;
        $required = [];

        $classMapper = $this->detectClassNameMapper($reflection);

        $nameResolver = $reflection->getFileName()
            ? FileNameResolver::createForFile($reflection->getFileName())
            : null;

        foreach ($reflection->getProperties(ReflectionProperty::IS_PUBLIC) as $property) {
            if ($property->isStatic()) {
                continue;
            }

            $info = $this->describeProperty($property, $input, $classMapper, $nameResolver);
            if ($info === null) {
                continue;
            }

            $object->addProperty($info['name'], $info['type']);

            if ($info['required']) {
                $required[] = $info['name'];
            }
        }

        $object->setRequired($required);

        return $object;
    }

    protected function cache(): ?SchemaCache
    {
        if (! app()->bound(SchemaCache::class)) {
            return null;
        }

        if (! config('scramble-extras.cache.enabled', true)) {
            return null;
        }

        return app(SchemaCache::class);
    }

    /**
     * Identifies the exact source a schema was built from: the Data class's
     * own file, its parent classes and traits (inherited properties), and
     * every class named in a class/property attribute (`#[Enum(X::class)]`
     * inlines enum values, `#[MapName(SomeMapper::class)]` decides names).
     * Any of those files changing - in either direction, so a deploy that
     * restores an older mtime counts too - invalidates the entry.
     */
    protected function fingerprint(string $class): string
    {
        $files = [];

        try {
            $reflection = new ReflectionClass($class);
        } catch (\ReflectionException) {
            return '';
        }

        $related = [];
        for ($r = $reflection; $r !== false; $r = $r->getParentClass()) {
            $related[] = $r;
            foreach ($this->traitsOf($r) as $trait) {
                $related[] = $trait;
            }
        }

        $attributes = $reflection->getAttributes();
        foreach ($reflection->getProperties(ReflectionProperty::IS_PUBLIC) as $property) {
            array_push($attributes, ...$property->getAttributes());
        }
        foreach ($attributes as $attribute) {
            foreach (\Illuminate\Support\Arr::flatten($attribute->getArguments()) as $argument) {
                if (is_string($argument) && (class_exists($argument) || enum_exists($argument))) {
                    $related[] = new ReflectionClass($argument);
                }
            }
        }

        foreach ($related as $r) {
            $file = $r->getFileName();
            if ($file !== false && is_file($file)) {
                $files[$file] = filemtime($file);
            }
        }

        ksort($files);

        return md5(serialize($files));
    }

    /**
     * @return array<int, ReflectionClass<object>>
     */
    protected function traitsOf(ReflectionClass $class): array
    {
        $traits = [];
        foreach ($class->getTraits() as $trait) {
            $traits[] = $trait;
            array_push($traits, ...$this->traitsOf($trait));
        }

        return $traits;
    }

    /**
     * A cached schema holds `$ref` names resolved in an earlier run. Scramble
     * picks component names in registration order (a short class name goes
     * to whichever class claims it first, others get their FQCN), so the same
     * class can get a different name in this run. Re-register every
     * dependency and only serve the entry when each still has the name the
     * cached `$ref` points to.
     *
     * @param  array<string, string>  $deps  component name => class
     */
    protected function depsStillResolve(array $deps): bool
    {
        foreach ($deps as $name => $class) {
            if (! is_string($class) || (! class_exists($class) && ! enum_exists($class))) {
                return false;
            }

            $reference = $this->openApiTransformer->transform(new ScrambleObjectType($class));

            if (! $reference instanceof Reference || $reference->getUniqueName() !== $name) {
                return false;
            }
        }

        return true;
    }

    /**
     * Maps every class handed to the transformer while building the schema
     * to the component name it is registered under in this run.
     *
     * @return array<string, string> component name => class
     */
    protected function extractDeps(): array
    {
        $deps = [];

        foreach (array_unique($this->referencedClasses) as $class) {
            $reference = $this->openApiTransformer->transform(new ScrambleObjectType($class));

            if ($reference instanceof Reference && $reference->referenceType === 'schemas') {
                $deps[$reference->getUniqueName()] = $class;
            }
        }

        return $deps;
    }

    /**
     * Records the classes inside a PHPDoc type (`array<int, FooData>`,
     * `Collection<int, StatusEnum>`, ...) as cache dependencies.
     */
    protected function collectClasses(\Dedoc\Scramble\Support\Type\Type $type): void
    {
        if ($type instanceof ScrambleObjectType) {
            if (class_exists($type->name) || enum_exists($type->name)) {
                $this->referencedClasses[] = $type->name;
            }

            if ($type instanceof \Dedoc\Scramble\Support\Type\Generic) {
                foreach ($type->templateTypes as $t) {
                    $this->collectClasses($t);
                }
            }

            return;
        }

        if ($type instanceof \Dedoc\Scramble\Support\Type\ArrayType) {
            $this->collectClasses($type->value);

            return;
        }

        if ($type instanceof \Dedoc\Scramble\Support\Type\Union) {
            foreach ($type->types as $t) {
                $this->collectClasses($t);
            }

            return;
        }

        if ($type instanceof \Dedoc\Scramble\Support\Type\KeyedArrayType) {
            foreach ($type->items as $item) {
                $this->collectClasses($item->value);
            }
        }
    }

    /**
     * @return array{name: string, type: OpenApiType, required: bool}|null
     */
    protected function describeProperty(ReflectionProperty $property, bool $input, ?\Closure $classMapper, ?FileNameResolver $nameResolver): ?array
    {
        $isHidden = $this->hasAttribute($property, Hidden::class);
        $isComputed = $this->hasAttribute($property, Computed::class);

        // Hidden never appears in output. Computed never appears in input.
        if (! $input && $isHidden) {
            return null;
        }
        if ($input && $isComputed) {
            return null;
        }

        $reflectionType = $property->getType();
        $unionTypes = $reflectionType instanceof ReflectionUnionType
            ? $reflectionType->getTypes()
            : ($reflectionType ? [$reflectionType] : []);

        $isOptional = false;
        $isLazy = false;
        $effectiveTypes = [];

        foreach ($unionTypes as $t) {
            if (! $t instanceof ReflectionNamedType) {
                continue;
            }

            $name = $t->getName();
            if (is_subclass_of($name, Optional::class) || $name === Optional::class) {
                $isOptional = true;
                continue;
            }
            if (is_subclass_of($name, Lazy::class) || $name === Lazy::class) {
                $isLazy = true;
                continue;
            }
            $effectiveTypes[] = $t;
        }

        $isNullable = $reflectionType && $reflectionType->allowsNull();

        $collectionOf = $this->extractDataCollectionOf($property);
        if ($collectionOf !== null) {
            $openApiType = (new OpenApiArrayType)->setItems(
                $this->resolveClassType($collectionOf),
            );
        } else {
            $docVarType = $this->extractDocVarType($property);
            $openApiType = $this->resolveType($effectiveTypes, $docVarType, $nameResolver);
        }

        if ($isNullable) {
            $openApiType->nullable(true);
        }

        $applier = new SchemaAttributeApplier;
        $analysis = $applier->analyze($property, $openApiType);
        $openApiType = $analysis->type;
        $applier->applyExamplesFromPhpDoc($property, $openApiType);

        $description = $this->buildDescription(
            base: $this->extractDescription($property),
            notes: $analysis->notes,
        );
        if ($description !== '') {
            $openApiType->setDescription($description);
        }

        $name = $this->resolveMappedName($property, $input, $classMapper);

        if (! $input && $isComputed) {
            // Computed properties are produced server-side on every output, so always required.
            $required = true;
        } elseif ($analysis->forceRequired !== null) {
            $required = $analysis->forceRequired;
        } else {
            $required = ! $isOptional
                && ! $isLazy
                && ! $isNullable
                && ! $this->promotedHasDefault($property);
        }

        return [
            'name' => $name,
            'type' => $openApiType,
            'required' => $required,
        ];
    }

    /**
     * @param  string[]  $notes
     */
    protected function buildDescription(string $base, array $notes): string
    {
        $parts = [];
        if ($base !== '') {
            $parts[] = $base;
        }
        foreach ($notes as $note) {
            $parts[] = $note;
        }

        return trim(implode(' ', $parts));
    }

    protected function hasAttribute(ReflectionProperty $property, string $attributeClass): bool
    {
        return $property->getAttributes($attributeClass) !== [];
    }

    protected function extractDataCollectionOf(ReflectionProperty $property): ?string
    {
        $attrs = $property->getAttributes(DataCollectionOf::class);
        if ($attrs === []) {
            return null;
        }

        $args = $attrs[0]->getArguments();
        $class = $args[0] ?? ($args['class'] ?? null);

        return is_string($class) && class_exists($class) ? $class : null;
    }

    protected function promotedHasDefault(ReflectionProperty $property): bool
    {
        if (! $property->isPromoted()) {
            return $property->hasDefaultValue();
        }

        $constructor = $property->getDeclaringClass()->getConstructor();
        if (! $constructor) {
            return false;
        }

        foreach ($constructor->getParameters() as $param) {
            if ($param->getName() === $property->getName()) {
                return $param->isDefaultValueAvailable() || $param->isOptional();
            }
        }

        return false;
    }

    /**
     * @param  ReflectionNamedType[]  $types
     */
    protected function resolveType(array $types, ?string $docVarType, ?FileNameResolver $nameResolver): OpenApiType
    {
        if ($docVarType !== null) {
            $resolved = $this->resolveDocType($docVarType, $nameResolver);
            if (! $resolved instanceof OpenApiUnknownType) {
                return $resolved;
            }
        }

        if (count($types) === 0) {
            return new OpenApiUnknownType;
        }

        $primary = $types[0];
        $name = $primary->getName();

        return match ($name) {
            'int' => new OpenApiIntegerType,
            'string' => new OpenApiStringType,
            'bool' => new OpenApiBooleanType,
            'float' => new OpenApiNumberType,
            'array' => new OpenApiArrayType,
            'mixed' => new OpenApiUnknownType,
            default => $this->resolveClassType($name),
        };
    }

    protected function resolveDocType(string $docType, ?FileNameResolver $nameResolver): OpenApiType
    {
        $phpDocNode = PhpDoc::parse("/** @var $docType */", $nameResolver);

        $varTags = $phpDocNode->getVarTagValues();
        if (empty($varTags)) {
            return new OpenApiUnknownType;
        }

        $scrambleType = PhpDocTypeHelper::toType($varTags[0]->type);
        $this->collectClasses($scrambleType);

        return $this->openApiTransformer->transform($scrambleType);
    }

    protected function resolveClassType(string $className): OpenApiType
    {
        if (! class_exists($className) && ! enum_exists($className)) {
            return new OpenApiUnknownType;
        }

        $this->referencedClasses[] = $className;

        return $this->openApiTransformer->transform(new ScrambleObjectType($className));
    }

    protected function extractDocVarType(ReflectionProperty $property): ?string
    {
        $doc = $property->getDocComment() ?: '';

        if ($doc !== '' && preg_match('/@var\s+([^\n*]+)/', $doc, $m)) {
            return trim($m[1]);
        }

        if ($property->isPromoted()) {
            $paramType = $this->findPromotedParamType($property);
            if ($paramType !== null) {
                return $paramType;
            }
        }

        return null;
    }

    protected function findPromotedParamType(ReflectionProperty $property): ?string
    {
        $constructor = $property->getDeclaringClass()->getConstructor();
        if (! $constructor) {
            return null;
        }

        $constructorDoc = $constructor->getDocComment() ?: '';
        if ($constructorDoc === '') {
            return null;
        }

        $name = $property->getName();
        if (preg_match('/@param\s+(\S+)\s+\$'.preg_quote($name, '/').'\b/', $constructorDoc, $m)) {
            return trim($m[1]);
        }

        return null;
    }

    protected function extractDescription(ReflectionProperty $property): string
    {
        $doc = $property->getDocComment();
        if (! $doc) {
            return '';
        }

        $lines = preg_split('/\r?\n/', $doc) ?: [];
        $description = [];
        foreach ($lines as $line) {
            $line = trim($line, " \t/*");
            if ($line === '' || str_starts_with($line, '@')) {
                continue;
            }
            $description[] = $line;
        }

        return trim(implode(' ', $description));
    }

    protected function detectClassNameMapper(ReflectionClass $reflection): ?\Closure
    {
        foreach ($reflection->getAttributes() as $attribute) {
            if ($attribute->getName() !== \Spatie\LaravelData\Attributes\MapName::class) {
                continue;
            }

            $args = $attribute->getArguments();
            $mapper = $args[0] ?? null;
            if (is_string($mapper) && class_exists($mapper)) {
                $instance = new $mapper;

                return static fn (string $name) => $instance->map($name);
            }
        }

        return null;
    }

    protected function resolveMappedName(ReflectionProperty $property, bool $input, ?\Closure $classMapper): string
    {
        $name = $property->getName();

        $perPropertyMapper = $this->getPerPropertyMapper($property, $input);
        if ($perPropertyMapper !== null) {
            return $perPropertyMapper($name);
        }

        return $classMapper ? $classMapper($name) : $name;
    }

    protected function getPerPropertyMapper(ReflectionProperty $property, bool $input): ?\Closure
    {
        $targetAttr = $input
            ? \Spatie\LaravelData\Attributes\MapInputName::class
            : \Spatie\LaravelData\Attributes\MapName::class;

        foreach ($property->getAttributes() as $attribute) {
            if ($attribute->getName() !== $targetAttr) {
                continue;
            }
            $args = $attribute->getArguments();
            $mapper = $args[0] ?? null;
            if (is_string($mapper) && class_exists($mapper)) {
                $instance = new $mapper;

                return static fn (string $n) => $instance->map($n);
            }
            if (is_string($mapper)) {
                return static fn () => $mapper;
            }
        }

        return null;
    }
}
