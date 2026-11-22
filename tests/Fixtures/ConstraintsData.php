<?php

namespace PawelJadanowski\ScrambleExtras\Tests\Fixtures;

use Spatie\LaravelData\Attributes\Validation\GreaterThan;
use Spatie\LaravelData\Attributes\Validation\MultipleOf;
use Spatie\LaravelData\Data;

class ConstraintsData extends Data
{
    public function __construct(
        #[GreaterThan(0)]
        public int $quantity,
        #[MultipleOf(5)]
        public int $step,
    ) {}
}
