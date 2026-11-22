<?php

namespace PawelJadanowski\ScrambleExtras;

use Dedoc\Scramble\Extensions\TypeToSchemaExtension;
use Dedoc\Scramble\Support\Generator\Response;
use Dedoc\Scramble\Support\Generator\Schema;
use Dedoc\Scramble\Support\Generator\Types\ArrayType as OpenApiArrayType;
use Dedoc\Scramble\Support\Generator\Types\Type as OpenApiType;
use Dedoc\Scramble\Support\Type\Generic;
use Dedoc\Scramble\Support\Type\Type;
use Spatie\LaravelData\DataCollection;

/**
 * Renders `Spatie\LaravelData\DataCollection<T>` (the non-paginated wrapper)
 * as a plain `array<T>` JSON response.
 */
class DataCollectionTypeToSchemaExtension extends TypeToSchemaExtension
{
    use ResolvesDataCollectionItems;

    public function shouldHandle(Type $type): bool
    {
        return $type instanceof Generic
            && is_a($type->name, DataCollection::class, true)
            && $this->getItemClass($type) !== null;
    }

    public function toSchema(Type $type): OpenApiType
    {
        return (new OpenApiArrayType)->setItems($this->getItemSchema($type));
    }

    public function toResponse(Type $type): ?Response
    {
        return Response::make(200)
            ->setContent('application/json', Schema::fromType($this->toSchema($type)));
    }
}
