<?php

namespace PawelJadanowski\ScrambleExtras\Tests\Fixtures;

class DatesController
{
    public function show(): DatesData
    {
        return DatesData::from([]);
    }

    public function store(DatesData $data): array
    {
        return [];
    }
}
