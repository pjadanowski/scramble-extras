<?php

namespace PawelJadanowski\ScrambleExtras\Tests\Fixtures;

use Carbon\Carbon;
use Spatie\LaravelData\Attributes\Validation\DateFormat;
use Spatie\LaravelData\Attributes\WithCast;
use Spatie\LaravelData\Attributes\WithTransformer;
use Spatie\LaravelData\Casts\DateTimeInterfaceCast;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Transformers\DateTimeInterfaceTransformer;

class DatesData extends Data
{
    public function __construct(
        public Carbon $plain,
        #[WithTransformer(DateTimeInterfaceTransformer::class, format: 'Y-m-d')]
        #[WithCast(DateTimeInterfaceCast::class, format: 'Y-m-d')]
        public Carbon $day,
        #[WithTransformer(DateTimeInterfaceTransformer::class, format: 'd.m.Y')]
        public Carbon $local,
        #[DateFormat('Y-m-d\TH:i:sP')]
        #[WithCast(DateTimeInterfaceCast::class, format: 'Y-m-d')]
        public Carbon $validated,
    ) {}
}
