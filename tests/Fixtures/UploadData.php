<?php

namespace PawelJadanowski\ScrambleExtras\Tests\Fixtures;

use Illuminate\Http\UploadedFile;
use Spatie\LaravelData\Attributes\Validation\Image;
use Spatie\LaravelData\Attributes\Validation\Max;
use Spatie\LaravelData\Data;

class UploadData extends Data
{
    /**
     * @param  array<int, UploadedFile>  $files
     * @param  array<int, string>  $tags
     */
    public function __construct(
        #[Max(2048)]
        public UploadedFile $avatar,
        #[Image]
        public UploadedFile $photo,
        public array $files,
        public AddressData $address,
        public array $tags,
        public ?UploadedFile $optional = null,
    ) {}
}
