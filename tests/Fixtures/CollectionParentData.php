<?php

namespace PawelJadanowski\ScrambleExtras\Tests\Fixtures;

use Spatie\LaravelData\Data;
use Spatie\LaravelData\DataCollection;

class CollectionParentData extends Data
{
    /**
     * @param  DataCollection<int, ChildInputData>  $children
     */
    public function __construct(
        public DataCollection $children,
    ) {}
}
