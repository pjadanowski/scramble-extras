<?php

namespace PawelJadanowski\ScrambleExtras\Tests\Fixtures;

use Spatie\LaravelData\Attributes\Computed;
use Spatie\LaravelData\Attributes\MapInputName;
use Spatie\LaravelData\Data;

class ChildInputData extends Data
{
    #[Computed]
    public string $label;

    public function __construct(
        #[MapInputName('child_name')]
        public string $name,
    ) {
        $this->label = strtoupper($name);
    }
}
