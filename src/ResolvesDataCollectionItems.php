<?php

namespace PawelJadanowski\ScrambleExtras;

use Dedoc\Scramble\Support\Generator\Types\ObjectType as OpenApiObjectType;
use Dedoc\Scramble\Support\Generator\Types\Type as OpenApiType;
use Dedoc\Scramble\Support\Type\Generic;
use Dedoc\Scramble\Support\Type\ObjectType as ScrambleObjectType;
use Dedoc\Scramble\Support\Type\Type;

/**
 * Item type of a laravel-data collection generic, shared by the
 * (Paginated|CursorPaginated)DataCollection extensions.
 *
 * The item type is always the last template parameter: spatie/laravel-data
 * declares these collections as `<TKey of array-key, TValue>` (matching
 * Illuminate\Support\Collection), so a spec-correct two-argument generic
 * like `PaginatedDataCollection<int, ProductData>` carries the Data class at
 * index 1, not 0. Flow-inferred generics (no docblock, resolved from a
 * `Data::collect()` call) only ever carry the single value type, at index 0
 * - which is also "last" - so this covers both shapes.
 */
trait ResolvesDataCollectionItems
{
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

        $itemType = new ScrambleObjectType($itemClass);

        // Inside a request body, the collection's item carries the input tag
        // (see LaravelDataReflector::INPUT_ATTRIBUTE); keep it on the item.
        if ($type->getAttribute(LaravelDataReflector::INPUT_ATTRIBUTE) === true) {
            $itemType->setAttribute(LaravelDataReflector::INPUT_ATTRIBUTE, true);
        }

        return $this->openApiTransformer->transform($itemType);
    }
}
