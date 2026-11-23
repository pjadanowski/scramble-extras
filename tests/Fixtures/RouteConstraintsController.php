<?php

namespace PawelJadanowski\ScrambleExtras\Tests\Fixtures;

use Dedoc\Scramble\Attributes\PathParameter;

class RouteConstraintsController
{
    public function kind(string $kind): array
    {
        return [];
    }

    public function id(int $id): array
    {
        return [];
    }

    public function twoParams(string $org, string $kind): array
    {
        return [];
    }

    public function attributedParams(#[PathParameter('org', description: 'Organisation')] string $org, string $kind): array
    {
        return [];
    }

    public function withDefault(string $kind = 'beta'): array
    {
        return [];
    }

    public function byStatus(StatusEnum $status): array
    {
        return [];
    }

    public function byLocale(string $locale): array
    {
        return [];
    }
}
