<?php

namespace PawelJadanowski\ScrambleExtras\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;

/**
 * Declares its casts in the casts() method (the Laravel 11+ convention)
 * rather than the $casts property.
 */
class Order extends Model
{
    protected function casts(): array
    {
        return ['status' => StatusEnum::class];
    }
}
