<?php

namespace PawelJadanowski\ScrambleExtras\Tests\Fixtures;

use Illuminate\Http\UploadedFile;
use Spatie\LaravelData\Attributes\Validation\Image;
use Spatie\LaravelData\Data;

class ImageOnlyData extends Data
{
    public function __construct(
        #[Image]
        public UploadedFile $photo,
        public string $caption,
    ) {}
}
