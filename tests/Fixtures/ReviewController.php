<?php

namespace PawelJadanowski\ScrambleExtras\Tests\Fixtures;

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
}
