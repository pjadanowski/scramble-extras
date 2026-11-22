<?php

namespace PawelJadanowski\ScrambleExtras;

use Dedoc\Scramble\Configuration\OperationTransformers;
use Dedoc\Scramble\Configuration\ParametersExtractors;
use Dedoc\Scramble\Scramble;
use Dedoc\Scramble\Support\OperationExtensions\ParameterExtractor\FormRequestParametersExtractor;
use Illuminate\Support\ServiceProvider;
use PawelJadanowski\ScrambleExtras\Console\Commands\ClearSchemaCacheCommand;

/**
 * Boots scramble-extras's integrations into Scramble:
 *  - Registers TypeToSchema/Infer extensions on Scramble's global extension
 *    list, so they participate in the same pipeline as user-defined
 *    extensions registered via config/scramble.php (those lists are read
 *    lazily, so registering from this provider's boot() is early enough).
 *  - Prepends a Spatie Data parameter extractor so request bodies typed with
 *    Data subclasses produce inlined input schemas (kept separate from the
 *    reusable output components to stay faithful to input-only rules).
 *  - Adds the operation extensions (422-for-Data-DTO, query-builder
 *    parameters) directly onto Scramble's OperationTransformers pipeline:
 *    Scramble snapshots OperationExtension classes from its global list when
 *    its own provider boots, before this one, so registerExtensions() would
 *    never reach that pipeline.
 *  - Binds the SchemaCache singleton (file-backed, invalidated per class
 *    from its source files) and registers the cache:clear artisan command.
 */
class ScrambleExtrasServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/scramble-extras.php', 'scramble-extras');

        $this->app->singleton(SchemaCache::class, function ($app) {
            $configured = $app['config']->get('scramble-extras.cache.path');

            return new SchemaCache(
                $configured ?: storage_path('framework/cache/scramble-extras/schemas.php'),
            );
        });
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__.'/../config/scramble-extras.php' => $this->app->configPath('scramble-extras.php'),
        ], 'scramble-extras-config');

        $extensions = [
            LaravelDataTypeToSchemaExtension::class,
            PaginatedDataCollectionTypeToSchemaExtension::class,
            CursorPaginatedDataCollectionTypeToSchemaExtension::class,
            DataCollectionTypeToSchemaExtension::class,
            LaravelDataReturnTypeExtension::class,
            LaravelDataResponseMethodReturnTypeExtension::class,
        ];

        Scramble::registerExtensions($extensions);

        // Scramble takes any class with a rules() method for a form request,
        // so a Data class with rules() got a second, rules-only request body
        // (an allOf with a stray component). LaravelDataParametersExtractor
        // folds those rules into the Data schema instead. ignoreInstanceOf()
        // is missing on older Scramble versions, which keep the allOf.
        if (method_exists(FormRequestParametersExtractor::class, 'ignoreInstanceOf')) {
            FormRequestParametersExtractor::ignoreInstanceOf(\Spatie\LaravelData\Contracts\BaseData::class);
        }

        Scramble::configure()->withParametersExtractors(function (ParametersExtractors $extractors) {
            $extractors->prepend(LaravelDataParametersExtractor::class);
        });

        Scramble::configure()->withOperationTransformers(function (OperationTransformers $transformers) {
            $transformers->prepend(LaravelDataValidationExceptionExtension::class);
            $transformers->append(RouteWhereInParameterExtension::class);
            $transformers->append(MethodAwareOperationIdExtension::class);

            // Appended directly rather than via registerExtensions(): Scramble
            // collects OperationExtension classes from Scramble::$extensions
            // once, while its own provider boots (before this one), so a
            // registerExtensions() call here would never reach the pipeline.
            // Only when the (optional) Spatie package is actually installed.
            // OperationTransformers::all() de-duplicates, so users who also
            // list it in config('scramble.extensions') don't get it twice.
            if (class_exists(\Spatie\QueryBuilder\QueryBuilder::class)) {
                $transformers->append(QueryBuilderOperationExtension::class);
            }

            // After the query builder, whose jsonPaginate() page[number]
            // takes precedence over a plain page parameter.
            $transformers->append(PaginationParametersOperationExtension::class);
        });

        // resolveOperationMethodsUsing() doesn't exist before dedoc/scramble
        // ~0.13.2x (it's still on the single-operation-per-route Generator on
        // this package's ^0.13.0 floor, with no configurable resolver at all -
        // see RouteMethodsResolver's docblock). Guard it so older installs
        // degrade gracefully instead of a hard boot-time error.
        if (
            $this->app['config']->get('scramble-extras.expand_route_methods', true)
            && method_exists(Scramble::configure(), 'resolveOperationMethodsUsing')
        ) {
            Scramble::configure()->resolveOperationMethodsUsing(RouteMethodsResolver::resolve(...));
        }

        if ($this->app->runningInConsole()) {
            $this->commands([
                ClearSchemaCacheCommand::class,
            ]);
        }
    }
}
