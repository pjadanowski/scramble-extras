<?php

namespace PawelJadanowski\ScrambleExtras\Tests\Fixtures;

use Dedoc\Scramble\Attributes\QueryParameter;
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
    #[QueryParameter('filter[name]', description: 'Exact name.', type: 'string')]
    public function documented()
    {
        return QueryBuilder::for(User::class)->allowedFilters('name', 'email')->get();
    }

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

    public function paginatedResponse(\Illuminate\Http\Request $request)
    {
        return PlainData::collect([], PaginatedDataCollection::class)->toResponse($request);
    }

    #[QueryParameter('page', description: 'Page to show.', type: 'int')]
    public function paginatedDocumented()
    {
        return PlainData::collect([], PaginatedDataCollection::class);
    }

    public function paginatedJson()
    {
        QueryBuilder::for(Order::class)->jsonPaginate();

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

    public function storeNested(ParentInputData $data): ParentInputData
    {
        return $data;
    }

    public function showChild(): ChildInputData
    {
        return ChildInputData::from(['child_name' => 'x']);
    }

    public function storeCollectionParent(CollectionParentData $data): CollectionParentData
    {
        return $data;
    }

    /**
     * The include list shared by the documentation and the runtime test.
     *
     * @return array<int, mixed>
     */
    public static function includeList(): array
    {
        return [
            'customer',
            'postsCount',
            'items.product',
            AllowedInclude::relationship('owner'),
            AllowedInclude::count('itemsCount'),
        ];
    }

    public function includes(): array
    {
        QueryBuilder::for(Order::class)->allowedIncludes(
            'customer',
            'postsCount',
            'items.product',
            AllowedInclude::relationship('owner'),
            AllowedInclude::count('itemsCount'),
        );

        return [];
    }
}
