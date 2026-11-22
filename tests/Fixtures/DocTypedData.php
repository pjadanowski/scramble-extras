<?php

namespace PawelJadanowski\ScrambleExtras\Tests\Fixtures;

use Spatie\LaravelData\Data;

class DocTypedData extends Data
{
    /**
     * @var array{
     *     lat: float,
     *     lng: float,
     * }
     */
    public array $location;

    /**
     * @param  array<int, int>  $ids
     * @param  array<int, PlainData>  $people
     */
    public function __construct(
        public array $ids,
        public array $people,
        public int|string $identifier = 0,
        public int|PlainData $ownerOrId = 0,
        public int|string|null $maybeIdentifier = null,
        public PlainData|ChildInputData|null $maybeOwner = null,
    ) {}
}
