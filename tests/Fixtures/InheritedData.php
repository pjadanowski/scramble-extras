<?php

namespace PawelJadanowski\ScrambleExtras\Tests\Fixtures;

use Spatie\LaravelData\Attributes\Validation\Enum;

class InheritedData extends BaseInheritedData
{
    use HasNoteField;

    #[Enum(StatusEnum::class)]
    public string $status;
}
