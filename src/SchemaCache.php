<?php

namespace PawelJadanowski\ScrambleExtras;

use Composer\InstalledVersions;

/**
 * File-based cache for fully-built Data class schemas. Persists across runs of
 * `php artisan scramble:export` so we don't re-reflect every Data class and
 * re-parse every PHPDoc on every export.
 *
 * Cache shape:
 *   [ "Fully\\Qualified\\Name.out" => [
 *       'fingerprint' => '…',                         // source files + mtimes
 *       'deps'  => ['JobData' => 'App\\Data\\JobData'], // component name => class
 *       'array' => [...],                             // schema array form
 *   ], ... ]
 *
 * Writes are deferred: `put()` only mutates the in-memory map and marks the
 * cache dirty; the file is written once on shutdown (or on an explicit
 * `flush()`). This keeps a full export — which can build hundreds of Data
 * schemas — to a single disk write instead of one rewrite per class.
 *
 * The on-disk payload carries a signature derived from the installed versions
 * of Scramble, spatie/laravel-data and this package, plus an internal format
 * version, so the cache self-invalidates when any of them changes (no manual
 * `cache:clear` needed after an upgrade).
 */
class SchemaCache
{
    /**
     * Bump when the cached array shape produced by this package changes.
     */
    private const FORMAT_VERSION = 2;

    /** @var array<string, array{fingerprint: string, deps: array<string, string>, array: array<string, mixed>}>|null */
    private ?array $entries = null;

    private bool $dirty = false;

    private bool $flushRegistered = false;

    public function __construct(protected string $path) {}

    public function path(): string
    {
        return $this->path;
    }

    /**
     * @return array{fingerprint: string, deps: array<string, string>, array: array<string, mixed>}|null
     */
    public function get(string $key): ?array
    {
        return $this->load()[$key] ?? null;
    }

    /**
     * @param  array{fingerprint: string, deps: array<string, string>, array: array<string, mixed>}  $entry
     */
    public function put(string $key, array $entry): void
    {
        $this->load();
        $this->entries[$key] = $entry;
        $this->dirty = true;
        $this->registerFlush();
    }

    public function clear(): void
    {
        $this->entries = [];
        $this->dirty = false;
        if (file_exists($this->path)) {
            @unlink($this->path);
        }
    }

    /**
     * Write pending changes to disk. Safe to call repeatedly — a no-op unless
     * something changed since the last write.
     */
    public function flush(): void
    {
        if (! $this->dirty || $this->entries === null) {
            return;
        }

        $this->persist();
        $this->dirty = false;
    }

    private function registerFlush(): void
    {
        if ($this->flushRegistered) {
            return;
        }

        $this->flushRegistered = true;
        register_shutdown_function(function (): void {
            $this->flush();
        });
    }

    /**
     * @return array<string, array{fingerprint: string, deps: array<string, string>, array: array<string, mixed>}>
     */
    private function load(): array
    {
        if ($this->entries !== null) {
            return $this->entries;
        }
        if (! file_exists($this->path)) {
            return $this->entries = [];
        }

        $loaded = @include $this->path;

        if (! is_array($loaded) || ($loaded['__signature'] ?? null) !== $this->signature()) {
            return $this->entries = [];
        }

        unset($loaded['__signature']);

        return $this->entries = $loaded;
    }

    private function persist(): void
    {
        $dir = dirname($this->path);
        if (! is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }

        $payload = $this->entries ?? [];
        $payload['__signature'] = $this->signature();

        $contents = "<?php\n\nreturn ".var_export($payload, true).";\n";
        $tmp = $this->path.'.'.getmypid().'.tmp';

        if (@file_put_contents($tmp, $contents) === false) {
            @unlink($tmp);

            return;
        }

        if (! @rename($tmp, $this->path)) {
            @unlink($tmp);

            return;
        }

        // The file is loaded with `include`: without this, OPcache (notably
        // with validate_timestamps=0 in production) keeps serving the
        // previously compiled version of the file.
        if (function_exists('opcache_invalidate')) {
            @opcache_invalidate($this->path, true);
        }
    }

    private function signature(): string
    {
        $versions = array_map(
            fn (string $package) => class_exists(InstalledVersions::class) && InstalledVersions::isInstalled($package)
                ? (InstalledVersions::getReference($package) ?? InstalledVersions::getVersion($package) ?? 'unknown')
                : 'unknown',
            ['dedoc/scramble', 'spatie/laravel-data', 'pjadanowski/scramble-extras'],
        );

        return self::FORMAT_VERSION.'|'.implode('|', $versions);
    }
}
