<?php

namespace PawelJadanowski\ScrambleExtras\Tests;

use Dedoc\Scramble\Scramble;
use Dedoc\Scramble\ScrambleServiceProvider;
use PawelJadanowski\ScrambleExtras\ScrambleExtrasServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;
use Spatie\LaravelData\LaravelDataServiceProvider;

abstract class TestCase extends Orchestra
{
    protected function setUp(): void
    {
        // Scramble::$extensions is process-wide static state that outlives
        // the per-test application. Without resetting it, extensions
        // registered by an earlier test's boot leak into the next one and
        // can mask boot-order bugs (an extension registered "too late"
        // would still show up from the previous test's leftovers).
        Scramble::$extensions = [];

        parent::setUp();
    }

    protected function getPackageProviders($app): array
    {
        return [
            LaravelDataServiceProvider::class,
            ScrambleServiceProvider::class,
            ScrambleExtrasServiceProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        // Keep schema generation deterministic across tests: never read a
        // schema cache file written by a previous run.
        $app['config']->set('scramble-extras.cache.enabled', false);
    }
}
