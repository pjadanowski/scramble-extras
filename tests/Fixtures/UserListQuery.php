<?php

namespace PawelJadanowski\ScrambleExtras\Tests\Fixtures;

use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;

/**
 * A query builder subclass that configures itself.
 */
class UserListQuery extends QueryBuilder
{
    public function __construct()
    {
        parent::__construct(User::query());

        $this->allowedFilters(AllowedFilter::exact('status'))
            ->allowedSorts('name');

        $this->configureIncludes();
    }

    public static function make(): static
    {
        return new static;
    }

    private function configureIncludes(): void
    {
        $this->allowedIncludes('posts');
    }
}
