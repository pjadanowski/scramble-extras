<?php

namespace PawelJadanowski\ScrambleExtras\Tests\Fixtures;

use Spatie\LaravelData\Data;

class PlainData extends Data
{
    public function __construct(
        public string $firstName,
    ) {}
}
