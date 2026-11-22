<?php

namespace PawelJadanowski\ScrambleExtras;

use Dedoc\Scramble\Extensions\TypeToSchemaExtension;
use Dedoc\Scramble\Support\Generator\ClassBasedReference;
use Dedoc\Scramble\Support\Generator\Reference;
use Dedoc\Scramble\Support\Generator\Response;
use Dedoc\Scramble\Support\Generator\Schema;
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
        return $this->reflector()->buildSchema(
            $type->name,
            input: LaravelDataReflector::shouldInlineAsInput($type),
        );
    }

    /**
     * Output schemas are reusable components. Inside a request body, nested
     * Data are inlined with their input projection instead (no reference).
     */
    public function reference(ObjectType $type): ?Reference
    {
        if (LaravelDataReflector::shouldInlineAsInput($type)) {
            return null;
        }

        return ClassBasedReference::create('schemas', $type->name, $this->components);
    }

    /**
     * Data is Responsable, and Scramble's Responsable handling documents it
     * through the inferred return type of toResponse(). That type is
     * JsonResponse<static> (LaravelDataResponseMethodReturnTypeExtension),
     * whose body is the Data again, so the two would hand each other the
     * same type forever. Answer the response here instead.
     */
    public function toResponse(Type $type): ?Response
    {
        /** @var ObjectType $type */
        $schema = $this->openApiTransformer->transform($type);

        return Response::make(200)
            ->setContent('application/json', Schema::fromType($schema))
            ->setDescription('`'.($schema instanceof Reference ? $schema->getUniqueName() : class_basename($type->name)).'`');
    }

    protected function reflector(): LaravelDataReflector
    {
        return new LaravelDataReflector($this->openApiTransformer, $this->components);
    }
}
