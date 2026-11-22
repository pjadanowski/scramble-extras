<?php

namespace PawelJadanowski\ScrambleExtras;

use Dedoc\Scramble\Extensions\TypeToSchemaExtension;
use Dedoc\Scramble\Support\Generator\ClassBasedReference;
use Dedoc\Scramble\Support\Generator\Reference;
use Dedoc\Scramble\Support\Generator\Types\Type as OpenApiType;
use Dedoc\Scramble\Support\Type\ObjectType;
use Dedoc\Scramble\Support\Type\Type;
use Spatie\LaravelData\Contracts\BaseData;
use Spatie\LaravelData\PaginatedDataCollection;

class LaravelDataTypeToSchemaExtension extends TypeToSchemaExtension
{
    public function shouldHandle(Type $type): bool
    {
        if (! $type instanceof ObjectType) {
            return false;
        }

        if (! class_exists($type->name)) {
            return false;
        }

        if (is_a($type->name, PaginatedDataCollection::class, true)) {
            return false;
        }

        return is_a($type->name, BaseData::class, true);
    }

    public function toSchema(Type $type, ?OpenApiType $previousExtensionResult = null): OpenApiType
    {
        // Another extension (typically a user subclass) already produced a
        // schema for this type. Don't overwrite — keep its work intact.
        if ($previousExtensionResult !== null) {
            return $previousExtensionResult;
        }

        /** @var ObjectType $type */
        return $this->reflector()->buildSchema($type->name, input: false);
    }

    public function reference(ObjectType $type): Reference
    {
        return ClassBasedReference::create('schemas', $type->name, $this->components);
    }

    protected function reflector(): LaravelDataReflector
    {
        return new LaravelDataReflector($this->openApiTransformer, $this->components);
    }
}
