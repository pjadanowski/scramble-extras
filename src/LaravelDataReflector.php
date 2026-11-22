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
use PHPStan\PhpDocParser\Ast\PhpDoc\PhpDocTextNode;
use PHPStan\PhpDocParser\Ast\Type\TypeNode;
use ReflectionClass;
use ReflectionNamedType;
use ReflectionProperty;
use ReflectionUnionType;
use Spatie\LaravelData\Attributes\Computed;
use Spatie\LaravelData\Attributes\DataCollectionOf;
use Spatie\LaravelData\Attributes\Hidden;
use Spatie\LaravelData\Optional;
use Spatie\LaravelData\Support\DataConfig;
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

    /**
     * Data classes whose input (request body) schema is being built, outermost
     * first. While it isn't empty, nested Data are inlined with their own
     * input projection instead of referencing their output component.
     *
     * @var array<int, string>
     */
    protected static array $inputStack = [];

    /**
     * Whether a nested Data class met right now should be inlined as input:
     * an input schema is being built, and the class isn't already being
     * built further up (a self-referencing Data would recurse forever; it
     * falls back to its output component).
     */
    public static function shouldInlineAsInput(string $dataClass): bool
    {
        return self::$inputStack !== [] && ! in_array($dataClass, self::$inputStack, true);
    }

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
        if ($input) {
            self::$inputStack[] = $dataClass;

            try {
                return $this->doBuildSchema($dataClass, true);
            } finally {
                array_pop(self::$inputStack);
            }
        }

        // An output component can be built from inside an input schema (a
        // self-referencing Data falls back to its component): its own nested
        // Data must reference output components, so suspend input mode.
        $suspendedInputStack = self::$inputStack;
        self::$inputStack = [];

        try {
            return $this->buildOutputSchema($dataClass);
        } finally {
            self::$inputStack = $suspendedInputStack;
        }
    }

    protected function buildOutputSchema(string $dataClass): OpenApiObjectType
    {
        $cache = $this->cache();

        if ($cache === null) {
            return $this->doBuildSchema($dataClass, false);
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

        $schema = $this->doBuildSchema($dataClass, false);

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

        $mappedNames = $this->mappedNames($dataClass, $input);

        foreach ($reflection->getProperties(ReflectionProperty::IS_PUBLIC) as $property) {
            if ($property->isStatic()) {
                continue;
            }

            $info = $this->describeProperty($property, $input, $mappedNames[$property->getName()] ?? $property->getName());
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
     * restores an older mtime counts too - invalidates the entry, as does a
     * change of config('data.name_mapping_strategy').
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

        return md5(serialize([$files, config('data.name_mapping_strategy')]));
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
     * Scramble's TypeTransformer caches results by type name, so the input
     * schema of a nested Data and its output reference would be served for
     * each other. While building an input schema, every object type is
     * tagged with an attribute, which makes the transformer skip its cache.
     */
    protected function bypassTransformCacheForInput(\Dedoc\Scramble\Support\Type\Type $type): \Dedoc\Scramble\Support\Type\Type
    {
        if (self::$inputStack === []) {
            return $type;
        }

        if ($type instanceof ScrambleObjectType) {
            $type->setAttribute('scramble-extras:input', true);

            if ($type instanceof \Dedoc\Scramble\Support\Type\Generic) {
                array_map($this->bypassTransformCacheForInput(...), $type->templateTypes);
            }
        } elseif ($type instanceof \Dedoc\Scramble\Support\Type\ArrayType) {
            $this->bypassTransformCacheForInput($type->value);
        } elseif ($type instanceof \Dedoc\Scramble\Support\Type\Union) {
            array_map($this->bypassTransformCacheForInput(...), $type->types);
        } elseif ($type instanceof \Dedoc\Scramble\Support\Type\KeyedArrayType) {
            foreach ($type->items as $item) {
                $this->bypassTransformCacheForInput($item->value);
            }
        }

        return $type;
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
    protected function describeProperty(ReflectionProperty $property, bool $input, string $name): ?array
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

            $typeName = $t->getName();
            if (is_a($typeName, Optional::class, true)) {
                $isOptional = true;
                continue;
            }
            if (is_a($typeName, Lazy::class, true)) {
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
            $openApiType = $this->resolveType($effectiveTypes, $this->extractDocType($property));
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
    protected function resolveType(array $types, ?TypeNode $docType): OpenApiType
    {
        if ($docType !== null) {
            $resolved = $this->resolveDocType($docType);
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

    protected function resolveDocType(TypeNode $docType): OpenApiType
    {
        $scrambleType = PhpDocTypeHelper::toType($docType);
        $this->collectClasses($scrambleType);

        return $this->openApiTransformer->transform($this->bypassTransformCacheForInput($scrambleType));
    }

    protected function resolveClassType(string $className): OpenApiType
    {
        if (! class_exists($className) && ! enum_exists($className)) {
            return new OpenApiUnknownType;
        }

        $this->referencedClasses[] = $className;

        return $this->openApiTransformer->transform(
            $this->bypassTransformCacheForInput(new ScrambleObjectType($className)),
        );
    }

    /**
     * The property's PHPDoc type: its own `@var`, or for a promoted property
     * the constructor's `@param`. Parsed with a real PHPDoc parser (generics
     * with spaces, multi-line array shapes), with class names resolved
     * against the file of the class that declares the property.
     */
    protected function extractDocType(ReflectionProperty $property): ?TypeNode
    {
        $declaringClass = $property->getDeclaringClass();
        $nameResolver = $this->nameResolverFor($declaringClass);

        if ($doc = $property->getDocComment()) {
            $varTags = PhpDoc::parse($doc, $nameResolver)->getVarTagValues();
            if ($varTags !== []) {
                return $varTags[0]->type;
            }
        }

        if (! $property->isPromoted() || ! ($constructorDoc = $declaringClass->getConstructor()?->getDocComment())) {
            return null;
        }

        foreach (PhpDoc::parse($constructorDoc, $nameResolver)->getParamTagValues() as $paramTag) {
            if ($paramTag->parameterName === '$'.$property->getName()) {
                return $paramTag->type;
            }
        }

        return null;
    }

    protected function nameResolverFor(ReflectionClass $class): ?FileNameResolver
    {
        $file = $class->getFileName();

        return $file ? FileNameResolver::createForFile($file) : null;
    }

    protected function extractDescription(ReflectionProperty $property): string
    {
        $doc = $property->getDocComment();
        if (! $doc) {
            return '';
        }

        // Only the free text before the tags: a multi-line tag (an array
        // shape, a long @example) continues on lines that don't start with `@`.
        $text = [];
        foreach (PhpDoc::parse($doc)->children as $child) {
            if (! $child instanceof PhpDocTextNode) {
                break;
            }
            $text[] = trim((string) $child);
        }

        return trim(preg_replace('/\s+/', ' ', implode(' ', $text)) ?? '');
    }

    /**
     * The names spatie/laravel-data itself reads (input) or writes (output)
     * for each property, taken from its own metadata so every mapping source
     * is honoured: MapName(input, output), MapInputName, MapOutputName on the
     * property or the class, and config('data.name_mapping_strategy').
     *
     * @return array<string, string> property name => mapped name
     */
    protected function mappedNames(string $dataClass, bool $input): array
    {
        $names = [];

        foreach (app(DataConfig::class)->getDataClass($dataClass)->properties as $property) {
            $mapped = $input ? $property->inputMappedName : $property->outputMappedName;
            $names[$property->name] = $mapped ?? $property->name;
        }

        return $names;
    }
}
