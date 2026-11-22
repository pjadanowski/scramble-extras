# scramble-extras

[![tests](https://github.com/pjadanowski/scramble-extras/actions/workflows/tests.yml/badge.svg?branch=main)](https://github.com/pjadanowski/scramble-extras/actions/workflows/tests.yml)

A free, open Laravel package that adds full **Spatie ecosystem** support to [dedoc/scramble](https://github.com/dedoc/scramble) — covering everything `scramble-pro` does for the integrations most projects actually need.

## Features

- **Spatie Laravel Data** — output and input (request body) schemas auto-generated from your `Data` classes. Honors `MapName`/`MapInputName`, `Optional`, `Lazy`, `#[Computed]`, `#[Hidden]`, `#[DataCollectionOf]`, and reads `@example`/`@default` from property phpdoc.
- **All ~80 Spatie validation attributes → OpenAPI fields** — `#[Email]` → `format: email`, `#[Min(3)]` → `minLength`, `#[GreaterThan(0)]` → `exclusiveMinimum`, `#[In(...)]` / `#[Enum(SomeEnum::class)]` → `enum`, `#[Regex]` → `pattern`, `#[Image]` → `contentMediaType: image/*`, plus description notes for `#[Same]`, `#[Confirmed]`, `#[Exists]`, `#[RequiredIf]`, etc.
- **`PaginatedDataCollection<T>`, `CursorPaginatedDataCollection<T>`, `DataCollection<T>`** — proper response envelopes (Laravel paginator format / cursor format / plain array).
- **Spatie Laravel Query Builder** — `allowedFilters` / `allowedSorts` / `allowedIncludes` / `allowedFields` / `defaultSort` / `jsonPaginate()` parsed via AST. `AllowedFilter::exact($column)` on an enum-cast Eloquent column emits `enum: [...]` automatically.
- **Persistent schema cache** — file-based, invalidated per class when its source (or a parent class, trait or attribute-referenced class) changes. `php artisan scramble-extras:cache:clear` to wipe.
- **422 for `Data`-typed actions** — a controller action type-hinted with a `Data` class is validated the same way a `FormRequest` would be, so it now documents a `422` response the same way core Scramble already does for `FormRequest`.
- **`PATCH` documented alongside `PUT`** — Scramble's default route-methods resolver only documents the first HTTP method a route responds to, so `Route::apiResource()`'s update action (registered for both `PUT` and `PATCH`) only ever got a `put` operation. Opt out with `SCRAMBLE_EXTRAS_EXPAND_ROUTE_METHODS=false`. Requires a `dedoc/scramble` version with `GeneratorConfig::resolveOperationMethodsUsing()` (added after this package's 0.13.0 floor); on older installs this feature is a no-op rather than an error.

## Requirements

- PHP 8.2+
- Laravel 11, 12, or 13
- `dedoc/scramble` ^0.12.20

## Install

```bash
composer require pjadanowski/scramble-extras
```

The service provider auto-registers all extensions via Laravel's package discovery — no config touching needed. Install [`dedoc/scramble`](https://github.com/dedoc/scramble) itself if you haven't already, and you're done: your `Data` classes start showing up in the generated docs.

To document `allowedFilters/Sorts/Includes/Fields` you also need `spatie/laravel-query-builder` (the query-builder extension self-registers only when that package is present):

```bash
composer require spatie/laravel-query-builder
```

## Pairs well with Atlas

[**Atlas**](https://github.com/pjadanowski/atlas) is a drop-in renderer for Scramble: fast, fully static, three-column API docs with search, a try-it console and code samples in 12 clients. scramble-extras makes the generated OpenAPI document complete (Data schemas, validation constraints, query-builder parameters), and Atlas renders it.

```bash
composer require pjadanowski/atlas
```

Then set `'renderer' => 'atlas'` in `config/scramble.php`. See the [Atlas README](https://github.com/pjadanowski/atlas#laravel--scramble) for details.

![Atlas endpoint page](https://raw.githubusercontent.com/pjadanowski/atlas/main/docs/screenshots/endpoint-light.png)

## Configuration

There's nothing to configure to get started. If you want to tune the cache, publish the config:

```bash
php artisan vendor:publish --tag=scramble-extras-config
```

```php
// config/scramble-extras.php
return [
    'cache' => [
        'enabled' => env('SCRAMBLE_EXTRAS_CACHE', true),
        'path' => env('SCRAMBLE_EXTRAS_CACHE_PATH'), // null = storage/framework/cache/scramble-extras/schemas.php
    ],
];
```

Or just set the env vars — e.g. disable the cache while iterating on `Data` classes locally:

```dotenv
SCRAMBLE_EXTRAS_CACHE=false
```

## How it plugs in

The package boots in two ways:

1. Calls `Scramble::registerExtensions(...)` in its service provider, contributing TypeToSchema, OperationExtension, and InferExtension classes that Scramble picks up automatically.
2. Calls `Scramble::configure()->withParametersExtractors(...)` to prepend a Data-aware parameter extractor for request bodies typed with `Data` subclasses.

You don't have to add anything to `config/scramble.php`.

## Cache

The package transparently caches the response (output) schemas it builds from `Data` classes in `storage/framework/cache/scramble-extras/schemas.php`. An entry is rebuilt when the `Data` class's file changes, or the file of a parent class, a trait, or a class named in one of its attributes (e.g. `#[Enum(StatusEnum::class)]`). The cache file also carries a signature derived from the installed versions of Scramble, spatie/laravel-data and this package, so it self-invalidates after an upgrade — no manual clear required. Request body schemas are always built fresh.

Writes are deferred: a full export builds every schema in memory and persists the cache **once** on shutdown, rather than rewriting the file per class.

To wipe manually:

```bash
php artisan scramble-extras:cache:clear
```

## Request bodies vs responses

A `Data` class is often used as both a response and a request body, and the two projections differ — `#[Computed]` is output-only, `#[Hidden]` is input-only, and `Optional` / `#[MapInputName]` change required-ness and field names. To keep both faithful, **request body schemas are inlined** (rendered directly on the operation) instead of `$ref`-ing the shared output component. Response schemas remain reusable `$ref` components.

## What's NOT covered (yet)

Long-tail features still on the roadmap:

- `multipart/form-data` request bodies for `Data` classes containing `UploadedFile` properties
- Sparse fieldsets rendered as proper enum array params (currently emitted as `string` with allowed values in the description)
- Polymorphic `Data` with discriminator schemas

## Testing

```bash
composer install
composer test
```

The suite covers the attribute → OpenAPI translation, the query-builder AST extraction, the schema cache, the return-type inference, and a full end-to-end Scramble generation pass (request bodies, response `$ref`s, paginated envelopes, and query parameters) booted with `orchestra/testbench`.

## License

MIT
