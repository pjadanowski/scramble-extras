# Changelog

All notable changes to `scramble-extras` will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Fixed
- **Schema cache served broken and stale schemas.** With the cache on (the
  default), a warm cache stripped all properties from components and
  request bodies, and its entries were effectively never reused. Only
  response schemas are cached now; an entry is invalidated when the Data
  class, a parent class, a trait or a class named in its attributes
  changes, or when Scramble, laravel-data or this package is upgraded.
- **`#[GreaterThan]`, `#[LessThan]`, `#[MultipleOf]`** never reached the
  generated document (`exclusiveMinimum` / `exclusiveMaximum` / `multipleOf`).
- **Property names** now follow laravel-data's own mapping: `MapName(input,
  output)`, `MapInputName`, `MapOutputName` (property or class) and
  `data.name_mapping_strategy`. Several of these were wrong or ignored.
- **Paginated envelopes** now match what laravel-data returns:
  `PaginatedDataCollection` has `links` as a list and the page URLs in
  `meta`; `CursorPaginatedDataCollection` nests the cursors under `meta`.
- **`Data` arguments on GET/DELETE/HEAD** are documented as query
  parameters instead of an invalid `in: body` parameter.
- **PHPDoc types with spaces** in constructor `@param` tags (e.g.
  `array<string, mixed>`) and multi-line `@var` array shapes are read
  correctly.
- **`#[MinDigits]`/`#[MaxDigits]`/`#[Digits]`/`#[DigitsBetween]` on `int`**
  bound the value instead of emitting the digit count as `minimum`/`maximum`.
- **`#[Min]`/`#[Max]`/`#[Size]` on arrays** set `minItems`/`maxItems`
  (`#[Distinct]` sets `uniqueItems`); on uploaded files they are described
  in kilobytes instead of emitted as string lengths.
- **Query builder:** filters with chained modifiers
  (`AllowedFilter::exact('x')->default(...)`), `AllowedSort::*` and
  `AllowedInclude::*` entries are documented; implicit `{relation}Count` /
  `{relation}Exists` includes are listed; exact-filter enums are detected
  from `casts()` and from the internal column of an aliased filter.
- **Nested `Data` in request bodies** use their own input projection
  instead of referencing the output component.
- **Response schemas** mark nullable and defaulted properties as required
  (laravel-data always writes them).
- **Native union types** (`int|string`) document every member.
- **`#[Regex]` with modifiers** and **`#[DateFormat]`** are no longer
  documented with a different meaning.

### Removed
- **Laravel 11 support.** Every 11.x release has unpatched security
  advisories, so it can no longer be installed or tested.
- `DataClassNameRegistry` (internal; no longer used by the cache).

## [0.5.0] - 2026-11-22

### Fixed
- **Query-builder parameters missing from the spec.** `filter[...]`, `sort`,
  `include` and `fields[...]` were never documented: Scramble collects
  operation extensions from its global extension list once, while its own
  service provider boots (before this package's), so
  `QueryBuilderOperationExtension` registered via `registerExtensions()`
  never reached the operation pipeline. It is now appended to the pipeline
  directly. The `config/scramble.php` workaround is no longer needed, but
  keeping it is harmless (the extension is not run twice).

## [0.4.0] - 2026-07-23

### Added
- **422 for `Data`-typed actions.** A controller action type-hinted with a
  `Data` class is resolved and validated by Laravel exactly like a
  `FormRequest` would be, but core Scramble only checked for `FormRequest`
  when deciding whether to document a `422` response. Mirrors core's own
  `ErrorResponsesExtension` handling.
- **`PATCH` documented alongside `PUT`.** Scramble's default route-methods
  resolver only documents the first HTTP method a route responds to, so
  `Route::apiResource()`'s update action (registered for both `PUT` and
  `PATCH` on one Route) only ever produced a `put` operation. Opt out with
  `SCRAMBLE_EXTRAS_EXPAND_ROUTE_METHODS=false` /
  `scramble-extras.expand_route_methods`. Requires a `dedoc/scramble` version
  with `GeneratorConfig::resolveOperationMethodsUsing()` (added after this
  package's 0.13.0 floor); on older installs this feature is a no-op rather
  than an error.

### Fixed
- **`PaginatedDataCollection`/`CursorPaginatedDataCollection`/`DataCollection`
  response envelopes were empty when the item type came from a spec-correct,
  two-argument generic docblock** (`PaginatedDataCollection<int, ProductData>`,
  matching spatie/laravel-data's own `<TKey of array-key, TValue>` template
  declaration, mirroring `Illuminate\Support\Collection`). The three
  `*TypeToSchemaExtension` classes always read the item type off
  `templateTypes[0]`, which only holds it in the single-template shape
  Scramble's flow inference produces from a bare `Data::collect()` call with
  no docblock; `TValue` is the *last* template parameter, not the first.
- Stale test assertion against a `dedoc/scramble` `Type::toArray()` shape
  that changed between patch releases within this package's `^0.13.0` range
  (scalar `example` key on 0.13.0, folded into the plural `examples` list on
  later 0.13.x) — the underlying `SchemaAttributeApplier` behavior was never
  wrong, only the test's expectation of a fixed output shape.

## [0.3.0] - 2026-06-12

### Changed
- **Require `dedoc/scramble ^0.13`** (previously `^0.12.20`). The full test
  suite (60 tests) passes unchanged against Scramble 0.13.27, so no extension
  code needed to change. The 0.12 line is dropped to avoid maintaining
  compatibility shims across two diverging internal-API versions; projects
  still on Scramble 0.12 should stay on scramble-extras 0.2.x.

## [0.2.0] - 2026-05-30

### Added
- Publishable `config/scramble-extras.php` with `cache.enabled` and `cache.path`
  options (also settable via `SCRAMBLE_EXTRAS_CACHE` / `SCRAMBLE_EXTRAS_CACHE_PATH`).
- Full test suite: unit tests for the attribute → OpenAPI applier, query-builder
  AST extraction, schema cache, support types, and return-type inference, plus an
  end-to-end Scramble generation test booted with `orchestra/testbench`.
- GitHub Actions CI matrix across PHP 8.2 / 8.3 / 8.4 and lowest/stable dependencies,
  plus a CI status badge in the README.

### Changed
- Test suite uses `#[Test]` attributes instead of the `test` method-name prefix.
  Dev tooling allows `phpunit/phpunit ^11.5 || ^12.0`: PHP 8.3+ runs on PHPUnit 12,
  while PHP 8.2 (still a supported runtime) runs on PHPUnit 11, which is the last
  line that supports PHP 8.2.

### Changed
- **Request bodies are now inlined** instead of `$ref`-ing a shared component.
  When a `Data` class is used as both a response and a request body, the input
  and output projections differ (`#[Computed]`, `#[Hidden]`, `Optional`,
  `#[MapInputName]`); sharing a component name made the request body describe the
  output shape. Response schemas remain reusable `$ref` components.
- `SchemaCache` writes are deferred and flushed once on shutdown rather than
  rewritten on every schema build, and the cache file now carries a signature
  derived from the installed Scramble version so it self-invalidates after an
  upgrade.

### Fixed
- Use `Response::description()` instead of `Response::setDescription()` on
  paginated/cursor responses; `setDescription()` does not exist on Scramble
  0.12.20 (the declared minimum), so generation crashed on older-but-supported
  Scramble versions. The `^0.12.20` floor is now actually honored.
- Extracted `QueryBuilderUsageVisitor` into its own file for PSR-4 compliance.
- Removed unused imports.

## [0.1.0] - 2026-05-04

### Added
- Initial release: Spatie Laravel Data (input/output schemas), all Spatie
  validation attributes, paginated/cursor/plain Data collections, Spatie Laravel
  Query Builder parameter extraction, and a persistent schema cache.

[Unreleased]: https://github.com/pjadanowski/scramble-extras/compare/v0.5.0...HEAD
[0.5.0]: https://github.com/pjadanowski/scramble-extras/compare/v0.4.0...v0.5.0
[0.4.0]: https://github.com/pjadanowski/scramble-extras/compare/v0.3.0...v0.4.0
[0.3.0]: https://github.com/pjadanowski/scramble-extras/compare/v0.2.0...v0.3.0
[0.2.0]: https://github.com/pjadanowski/scramble-extras/compare/v0.1.0...v0.2.0
[0.1.0]: https://github.com/pjadanowski/scramble-extras/releases/tag/v0.1.0
