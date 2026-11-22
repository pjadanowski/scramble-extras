<?php

namespace PawelJadanowski\ScrambleExtras\Tests\Feature;

use Dedoc\Scramble\Generator;
use Illuminate\Routing\Router;
use PawelJadanowski\ScrambleExtras\SchemaCache;
use PawelJadanowski\ScrambleExtras\Tests\Fixtures\UserController;
use PawelJadanowski\ScrambleExtras\Tests\Fixtures\UserData;
use PawelJadanowski\ScrambleExtras\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * The schema cache is on by default, so a warm cache must never change the
 * generated document: a run served from a cold cache, and a run in a "new
 * process" served from the cache file, must both equal a run with the cache
 * disabled.
 */
class SchemaCacheRoundTripTest extends TestCase
{
    private string $cachePath;

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $this->cachePath = sys_get_temp_dir().'/scramble-extras-roundtrip-'.uniqid().'/schemas.php';

        $app['config']->set('scramble-extras.cache.enabled', true);
        $app['config']->set('scramble-extras.cache.path', $this->cachePath);
    }

    protected function tearDown(): void
    {
        @unlink($this->cachePath);
        @rmdir(dirname($this->cachePath));

        parent::tearDown();
    }

    protected function defineRoutes($router): void
    {
        /** @var Router $router */
        $router->get('api/users/{id}', [UserController::class, 'show']);
        $router->post('api/users', [UserController::class, 'store']);
        $router->get('api/users', [UserController::class, 'index']);
        $router->match(['put', 'patch'], 'api/users/{id}', [UserController::class, 'update']);
    }

    /**
     * @return array<string, mixed>
     */
    private function generate(): array
    {
        $result = app(Generator::class)();

        return is_array($result) ? $result : $result->toArray();
    }

    #[Test]
    public function cold_and_warm_cache_produce_the_same_document_as_no_cache(): void
    {
        config(['scramble-extras.cache.enabled' => false]);
        $expected = $this->generate();

        config(['scramble-extras.cache.enabled' => true]);
        $cold = $this->generate();
        app(SchemaCache::class)->flush();
        $this->assertFileExists($this->cachePath);

        // Simulate a new process: drop the in-memory cache so the next run
        // loads the file written above.
        app()->forgetInstance(SchemaCache::class);
        $warm = $this->generate();

        $this->assertEquals($expected, $cold, 'Cold cache changed the document.');
        $this->assertEquals($expected, $warm, 'Warm cache (loaded from file) changed the document.');
    }

    #[Test]
    public function warm_cache_keeps_request_body_properties(): void
    {
        $this->generate();
        app(SchemaCache::class)->flush();
        app()->forgetInstance(SchemaCache::class);

        $schema = $this->generate()['paths']['/users']['post']['requestBody']['content']['application/json']['schema'];

        $this->assertArrayHasKey('properties', $schema);
        $this->assertArrayHasKey('email', $schema['properties']);
    }

    #[Test]
    public function warm_run_is_served_from_the_cache_file(): void
    {
        $this->generate();
        app(SchemaCache::class)->flush();

        // Tag the cached UserData schema; if the next run really serves it
        // from the file, the tag shows up in the document.
        $payload = include $this->cachePath;
        $key = UserData::class.'.out';
        $this->assertArrayHasKey($key, $payload);
        $payload[$key]['array']['description'] = 'served-from-cache';
        file_put_contents($this->cachePath, '<?php return '.var_export($payload, true).';');
        if (function_exists('opcache_invalidate')) {
            opcache_invalidate($this->cachePath, true);
        }

        app()->forgetInstance(SchemaCache::class);

        $this->assertSame(
            'served-from-cache',
            $this->generate()['components']['schemas']['UserData']['description'] ?? null,
        );
    }

    #[Test]
    public function changed_source_file_invalidates_the_entry(): void
    {
        $this->generate();
        app(SchemaCache::class)->flush();

        $payload = include $this->cachePath;
        $key = UserData::class.'.out';
        $payload[$key]['array']['description'] = 'stale';
        $payload[$key]['fingerprint'] = 'fingerprint-of-an-older-source';
        file_put_contents($this->cachePath, '<?php return '.var_export($payload, true).';');
        if (function_exists('opcache_invalidate')) {
            opcache_invalidate($this->cachePath, true);
        }

        app()->forgetInstance(SchemaCache::class);

        $this->assertArrayNotHasKey(
            'description',
            $this->generate()['components']['schemas']['UserData'],
        );
    }
}
