<?php

namespace PawelJadanowski\ScrambleExtras;

use Dedoc\Scramble\Extensions\TypeToSchemaExtension;
use Dedoc\Scramble\Support\Generator\Response;
use Dedoc\Scramble\Support\Generator\Schema;
use Dedoc\Scramble\Support\Generator\Types\ArrayType as OpenApiArrayType;
use Dedoc\Scramble\Support\Generator\Types\BooleanType as OpenApiBooleanType;
use Dedoc\Scramble\Support\Generator\Types\IntegerType as OpenApiIntegerType;
use Dedoc\Scramble\Support\Generator\Types\ObjectType as OpenApiObjectType;
use Dedoc\Scramble\Support\Generator\Types\StringType as OpenApiStringType;
use Dedoc\Scramble\Support\Generator\Types\Type as OpenApiType;
use Dedoc\Scramble\Support\Type\Generic;
use Dedoc\Scramble\Support\Type\ObjectType as ScrambleObjectType;
use Dedoc\Scramble\Support\Type\Type;
use Spatie\LaravelData\PaginatedDataCollection;

/**
 * Renders `Spatie\LaravelData\PaginatedDataCollection<T>` as the envelope
 * spatie/laravel-data returns for it: `data`, `links` (the paginator's link
 * list) and `meta` (page numbers and URLs). Note this is not the
 * JsonResource envelope: `links` is a list, and the page URLs live in `meta`.
 */
class PaginatedDataCollectionTypeToSchemaExtension extends TypeToSchemaExtension
{
    public function shouldHandle(Type $type): bool
    {
        if ($type instanceof Generic && is_a($type->name, PaginatedDataCollection::class, true)) {
            return $this->getItemClass($type) !== null;
        }

        return $type instanceof ScrambleObjectType
            && is_a($type->name, PaginatedDataCollection::class, true);
    }

    public function toSchema(Type $type): OpenApiType
    {
        $itemSchema = $this->getItemSchema($type);

        $object = new OpenApiObjectType;
        $object
            ->addProperty('data', (new OpenApiArrayType)->setItems($itemSchema))
            ->addProperty('links', $this->linksObject())
            ->addProperty('meta', $this->metaObject())
            ->setRequired(['data', 'links', 'meta']);

        return $object;
    }

    public function toResponse(Type $type): ?Response
    {
        $schema = $this->toSchema($type);

        return Response::make(200)
            ->description('Paginated set of data')
            ->setContent('application/json', Schema::fromType($schema));
    }

    /**
     * The item type is always the last template parameter: spatie/laravel-data
     * declares these collections as `<TKey of array-key, TValue>` (matching
     * Illuminate\Support\Collection), so a spec-correct two-argument generic
     * like `PaginatedDataCollection<int, ProductData>` carries the Data class
     * at index 1, not 0. Flow-inferred generics (no docblock, resolved from a
     * `Data::collect()` call) only ever carry the single value type, at index 0
     * — which is also "last" — so this covers both shapes.
     */
    protected function getItemClass(Type $type): ?string
    {
        if (! $type instanceof Generic || $type->templateTypes === []) {
            return null;
        }

        $inner = end($type->templateTypes);

        if ($inner instanceof ScrambleObjectType && class_exists($inner->name)) {
            return $inner->name;
        }

        return null;
    }

    protected function getItemSchema(Type $type): OpenApiType
    {
        $itemClass = $this->getItemClass($type);
        if ($itemClass === null) {
            return new OpenApiObjectType;
        }

        return $this->openApiTransformer->transform(new ScrambleObjectType($itemClass));
    }

    /**
     * The paginator's link collection, as returned by
     * LengthAwarePaginator::linkCollection(): a list of page links.
     */
    protected function linksObject(): OpenApiArrayType
    {
        $link = new OpenApiObjectType;
        $link
            ->addProperty('url', (new OpenApiStringType)->format('uri')->nullable(true))
            ->addProperty('label', new OpenApiStringType)
            // Added to linkCollection() in newer Laravel versions.
            ->addProperty('page', (new OpenApiIntegerType)->nullable(true))
            ->addProperty('active', new OpenApiBooleanType)
            ->setRequired(['url', 'label', 'active']);

        return (new OpenApiArrayType)->setItems($link);
    }

    /**
     * Mirrors TransformedDataCollectableResolver::resolveLengthAwarePaginatorLinksAndMeta().
     */
    protected function metaObject(): OpenApiObjectType
    {
        $url = fn () => (new OpenApiStringType)->format('uri');

        $properties = [
            'current_page' => new OpenApiIntegerType,
            'first_page_url' => $url(),
            'from' => (new OpenApiIntegerType)->nullable(true),
            'last_page' => new OpenApiIntegerType,
            'last_page_url' => $url(),
            'next_page_url' => $url()->nullable(true),
            'path' => $url(),
            'per_page' => new OpenApiIntegerType,
            'prev_page_url' => $url()->nullable(true),
            'to' => (new OpenApiIntegerType)->nullable(true),
            'total' => new OpenApiIntegerType,
        ];

        $object = new OpenApiObjectType;
        foreach ($properties as $name => $type) {
            $object->addProperty($name, $type);
        }

        return $object->setRequired(array_keys($properties));
    }
}
