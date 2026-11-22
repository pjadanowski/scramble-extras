<?php

namespace PawelJadanowski\ScrambleExtras\Tests\Fixtures;

use Spatie\LaravelData\CursorPaginatedDataCollection;
use Spatie\LaravelData\PaginatedDataCollection;

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
}
