<?php

namespace PawelJadanowski\ScrambleExtras\Tests\Fixtures;

use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;

class QueryUsageController
{
    public function variables()
    {
        $filters = ['name', AllowedFilter::exact('id')];
        $sorts = ['name', 'created_at'];

        return QueryBuilder::for(User::class)
            ->allowedFilters($filters)
            ->allowedSorts(...$sorts)
            ->get();
    }

    public function callee()
    {
        return $this->query()->get();
    }

    public function subclass()
    {
        return (new UserListQuery)->get();
    }

    public function subclassFactory()
    {
        return UserListQuery::make()->get();
    }

    public function plain()
    {
        return [];
    }

    private function query(): QueryBuilder
    {
        return $this->applySorts(QueryBuilder::for(User::class)->allowedFilters('email'));
    }

    private function applySorts(QueryBuilder $query): QueryBuilder
    {
        return $query->allowedSorts('email');
    }

    /**
     * Not called by any route: must not leak into other operations.
     */
    private function unrelated(): QueryBuilder
    {
        return QueryBuilder::for(User::class)->allowedFilters('secret');
    }
}
