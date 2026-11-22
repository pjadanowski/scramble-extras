<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Schema cache
    |--------------------------------------------------------------------------
    |
    | scramble-extras caches the schemas it builds from your spatie/laravel-data
    | classes so a full `scramble:export` / docs request doesn't re-reflect and
    | re-parse every Data class on every run. An entry is invalidated when the
    | Data class's file, a parent class, a trait or a class named in one of
    | its attributes changes.
    |
    | You'll typically want it enabled in production and CI, and may prefer it
    | disabled while actively iterating on Data classes locally.
    |
    */

    'cache' => [

        'enabled' => env('SCRAMBLE_EXTRAS_CACHE', true),

        // Absolute path to the cache file. When null, defaults to
        // storage/framework/cache/scramble-extras/schemas.php.
        'path' => env('SCRAMBLE_EXTRAS_CACHE_PATH'),

    ],

    /*
    |--------------------------------------------------------------------------
    | Expand PUT|PATCH routes into separate operations
    |--------------------------------------------------------------------------
    |
    | Scramble core only ever documents the FIRST HTTP method a route responds
    | to. Route::apiResource()'s update action registers both PUT and PATCH on
    | a single Route, so PATCH silently never gets documented. When enabled,
    | every method the route actually responds to (except HEAD/OPTIONS) gets
    | its own operation.
    |
    | This isn't Spatie-specific - it fixes documentation for both Data-typed
    | and Resource-typed actions alike. Disable it if your app already
    | registers its own Scramble::configure()->resolveOperationMethodsUsing()
    | and you don't want this package to take precedence (whichever call runs
    | last wins; your own AppServiceProvider boots after package providers, so
    | it already wins by default - this toggle is only for the rare case where
    | you'd rather this package not touch it at all).
    |
    */

    'expand_route_methods' => env('SCRAMBLE_EXTRAS_EXPAND_ROUTE_METHODS', true),

];
