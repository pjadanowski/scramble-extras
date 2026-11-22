<?php

namespace PawelJadanowski\ScrambleExtras\Tests\Fixtures;

use Spatie\LaravelData\Data;

abstract class BaseInheritedData extends Data
{
    public string $inheritedField;
}
