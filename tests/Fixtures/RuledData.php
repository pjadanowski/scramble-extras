<?php

namespace PawelJadanowski\ScrambleExtras\Tests\Fixtures;

use Spatie\LaravelData\Data;

class RuledData extends Data
{
    /**
     * @param  array<int, string>  $tags
     */
    public function __construct(
        public string $title,
        public array $tags,
        public ?string $code,
        public int $age,
        public AddressData $address,
    ) {}

    public static function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:20'],
            'tags' => ['array', 'min:1'],
            'tags.*' => ['string', 'in:a,b'],
            'code' => ['nullable', 'regex:/^[A-Z]+$/'],
            'age' => 'integer|min:18',
            'address.city' => ['string', 'max:50'],
            'not_a_property' => ['string'],
        ];
    }
}
