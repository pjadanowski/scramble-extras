<?php

namespace PawelJadanowski\ScrambleExtras\Tests\Fixtures;

use Illuminate\Http\Request;
use Spatie\LaravelData\DataCollection;
use Spatie\LaravelData\PaginatedDataCollection;

class ToResponseController
{
    public function data(Request $request)
    {
        return UserData::from([])->toResponse($request);
    }

    public function collection(Request $request)
    {
        return UserData::collect([], DataCollection::class)->toResponse($request);
    }

    public function paginated(Request $request)
    {
        return PlainData::collect([], PaginatedDataCollection::class)->toResponse($request);
    }

    public function created(Request $request)
    {
        return UserData::from([])->toResponse($request)->setStatusCode(201);
    }

    public function abstract(Request $request)
    {
        return $this->resolve()->toResponse($request);
    }

    private function resolve(): AbstractData
    {
        return PlainData::from([]);
    }
}
