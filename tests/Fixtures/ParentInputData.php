<?php

namespace PawelJadanowski\ScrambleExtras\Tests\Fixtures;

use Spatie\LaravelData\Data;

class ParentInputData extends Data
{
    /**
     * @param  array<int, ChildInputData>  $children
     */
    public function __construct(
        public ChildInputData $child,
        public array $children,
        public ?ParentInputData $parent = null,
    ) {}
}
