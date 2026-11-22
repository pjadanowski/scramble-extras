<?php

namespace PawelJadanowski\ScrambleExtras\Tests\Fixtures;

use Spatie\LaravelData\Attributes\MapInputName;
use Spatie\LaravelData\Attributes\MapName;
use Spatie\LaravelData\Attributes\MapOutputName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;

#[MapOutputName(SnakeCaseMapper::class)]
class MappedData extends Data
{
    public function __construct(
        #[MapName('in_both', 'out_both')]
        public string $both,
        #[MapName('same_both')]
        public string $sameBoth,
        #[MapInputName('input_only')]
        public string $inputOnly,
        #[MapOutputName('output_only')]
        public string $outputOnly,
        #[MapName(input: 'named_in', output: 'named_out')]
        public string $namedArgs,
        public string $classMapped,
    ) {}
}
