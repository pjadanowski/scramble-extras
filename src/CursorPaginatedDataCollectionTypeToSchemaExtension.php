<?php

namespace PawelJadanowski\ScrambleExtras;

use Dedoc\Scramble\Extensions\TypeToSchemaExtension;
use Dedoc\Scramble\Support\Generator\Response;
use Dedoc\Scramble\Support\Generator\Schema;
use Dedoc\Scramble\Support\Generator\Types\ArrayType as OpenApiArrayType;
use Dedoc\Scramble\Support\Generator\Types\IntegerType as OpenApiIntegerType;
use Dedoc\Scramble\Support\Generator\Types\ObjectType as OpenApiObjectType;
use Dedoc\Scramble\Support\Generator\Types\StringType as OpenApiStringType;
use Dedoc\Scramble\Support\Generator\Types\Type as OpenApiType;
use Dedoc\Scramble\Support\Type\Generic;
use Dedoc\Scramble\Support\Type\Type;
use Spatie\LaravelData\CursorPaginatedDataCollection;

/**
 * Renders `Spatie\LaravelData\CursorPaginatedDataCollection<T>` as the
 * envelope spatie/laravel-data returns for it: `data`, an always-empty
 * `links` list, and `meta` with the cursors and page URLs (mirrors
 * TransformedDataCollectableResolver::resolveCursorPaginatorLinksAndMeta()).
 */
class CursorPaginatedDataCollectionTypeToSchemaExtension extends TypeToSchemaExtension
{
    use ResolvesDataCollectionItems;

    public function shouldHandle(Type $type): bool
    {
        return $type instanceof Generic
            && is_a($type->name, CursorPaginatedDataCollection::class, true)
            && $this->getItemClass($type) !== null;
    }

    public function toSchema(Type $type): OpenApiType
    {
        $itemSchema = $this->getItemSchema($type);

        $meta = new OpenApiObjectType;
        $metaProperties = [
            'path' => (new OpenApiStringType)->format('uri'),
            'per_page' => new OpenApiIntegerType,
            'next_cursor' => (new OpenApiStringType)->nullable(true),
            'next_page_url' => (new OpenApiStringType)->format('uri')->nullable(true),
            'prev_cursor' => (new OpenApiStringType)->nullable(true),
            'prev_page_url' => (new OpenApiStringType)->format('uri')->nullable(true),
        ];
        foreach ($metaProperties as $name => $propertyType) {
            $meta->addProperty($name, $propertyType);
        }
        $meta->setRequired(array_keys($metaProperties));

        $object = new OpenApiObjectType;
        $object
            ->addProperty('data', (new OpenApiArrayType)->setItems($itemSchema))
            ->addProperty('links', (new OpenApiArrayType)->setDescription('Always empty for cursor pagination.'))
            ->addProperty('meta', $meta)
            ->setRequired(['data', 'links', 'meta']);

        return $object;
    }

    public function toResponse(Type $type): ?Response
    {
        return Response::make(200)
            ->description('Cursor-paginated set of data')
            ->setContent('application/json', Schema::fromType($this->toSchema($type)));
    }
}
