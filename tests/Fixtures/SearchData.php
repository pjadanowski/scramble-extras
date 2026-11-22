<?php

namespace PawelJadanowski\ScrambleExtras\Tests\Fixtures;

use Spatie\LaravelData\Attributes\MapInputName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Optional;

class SearchData extends Data
{
    public function __construct(
        /** Free-text search term. */
        public string $term,
        #[MapInputName('per_page')]
        public int|Optional $perPage,
    ) {}
}
