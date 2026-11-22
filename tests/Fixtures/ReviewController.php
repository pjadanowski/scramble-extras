<?php

namespace PawelJadanowski\ScrambleExtras\Tests\Fixtures;

use Spatie\LaravelData\CursorPaginatedDataCollection;
use Spatie\LaravelData\PaginatedDataCollection;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\AllowedInclude;
use Spatie\QueryBuilder\AllowedSort;
use Spatie\QueryBuilder\QueryBuilder;

/**
 * Routes for the regression tests added after the 0.5 code review.
 */
class ReviewController
{
    public function constraints(): ConstraintsData
    {
        return ConstraintsData::from([]);
    }

    public function storeMapped(MappedData $data): MappedData
    {
        return $data;
    }

    public function storePlain(PlainData $data): PlainData
    {
        return $data;
    }

    public function paginated()
    {
        return PlainData::collect([], PaginatedDataCollection::class);
    }

    public function cursorPaginated()
    {
        return PlainData::collect([], CursorPaginatedDataCollection::class);
    }

    public function search(SearchData $query): PlainData
    {
        return PlainData::from(['firstName' => $query->term]);
    }

    public function docTyped(): DocTypedData
    {
        return DocTypedData::from([]);
    }

    public function orders(): array
    {
        QueryBuilder::for(Order::class)
            ->allowedFilters([
                AllowedFilter::exact('status'),
                AllowedFilter::exact('state', 'status'),
                AllowedFilter::exact('chained')->default('x'),
                AllowedFilter::scope('placed_after')->ignore(null),
            ])
            ->allowedSorts([
                AllowedSort::field('name', 'customer_name'),
                AllowedSort::field('total')->defaultDirection('desc'),
                'id',
            ])
            ->allowedIncludes([
                'customer',
                'items.product',
                AllowedInclude::count('itemsCount'),
            ]);

        return [];
    }
}
