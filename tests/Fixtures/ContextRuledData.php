<?php

namespace PawelJadanowski\ScrambleExtras\Tests\Fixtures;

use Spatie\LaravelData\Data;
use Spatie\LaravelData\Support\Validation\ValidationContext;

class ContextRuledData extends Data
{
    public function __construct(
        public string $name,
    ) {}

    public static function rules(ValidationContext $context): array
    {
        return [
            'name' => ['string', 'max:10'],
        ];
    }
}
