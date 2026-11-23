<?php

namespace PawelJadanowski\ScrambleExtras\Tests\Fixtures;

class UploadController
{
    public function store(UploadData $data): array
    {
        return [];
    }

    public function image(ImageOnlyData $data): array
    {
        return [];
    }

    public function json(UserData $data): array
    {
        return [];
    }
}
