<?php

namespace PawelJadanowski\ScrambleExtras\Tests\Feature;

use Dedoc\Scramble\Generator;
use PawelJadanowski\ScrambleExtras\Tests\Fixtures\ReviewController;
use PawelJadanowski\ScrambleExtras\Tests\TestCase;
use Illuminate\Http\Request;
use PawelJadanowski\ScrambleExtras\Tests\Fixtures\Order;
use PHPUnit\Framework\Attributes\Test;
use Spatie\QueryBuilder\QueryBuilder;

/**
 * Filters, sorts and includes declared with the builder classes
 * (AllowedFilter/AllowedSort/AllowedInclude), with chained modifiers,
 * aliases, and casts declared in the model's casts() method.
 */
class QueryBuilderBuildersTest extends TestCase
{
    protected function defineRoutes($router): void
    {
        $router->get('api/orders', [ReviewController::class, 'orders']);
        $router->get('api/includes', [ReviewController::class, 'includes']);
        $router->get('api/documented', [ReviewController::class, 'documented']);
    }

    /**
     * @return \Illuminate\Support\Collection<string, array<string, mixed>>
     */
    private function parameters(string $path = '/orders'): \Illuminate\Support\Collection
    {
        $result = app(Generator::class)();
        $openApi = is_array($result) ? $result : $result->toArray();

        return collect($openApi['paths'][$path]['get']['parameters'] ?? [])->keyBy('name');
    }

    #[Test]
    public function chained_filters_are_documented(): void
    {
        $parameters = $this->parameters();

        $this->assertTrue($parameters->has('filter[chained]'));
        $this->assertTrue($parameters->has('filter[placed_after]'));
    }

    #[Test]
    public function exact_filter_enum_comes_from_the_casts_method(): void
    {
        $this->assertSame(['active', 'inactive', 'pending'], $this->parameters()['filter[status]']['schema']['enum'] ?? null);
    }

    #[Test]
    public function aliased_exact_filter_uses_the_internal_column_cast(): void
    {
        $this->assertSame(['active', 'inactive', 'pending'], $this->parameters()['filter[state]']['schema']['enum'] ?? null);
    }

    #[Test]
    public function allowed_sort_builders_are_documented(): void
    {
        $description = $this->parameters()['sort']['description'];

        foreach (['`name`', '`total`', '`id`'] as $sort) {
            $this->assertStringContainsString($sort, $description);
        }
        $this->assertStringNotContainsString('customer_name', $description);
    }

    #[Test]
    public function documented_includes_match_what_the_installed_query_builder_allows(): void
    {
        $builder = QueryBuilder::for(Order::class, new Request)->allowedIncludes(...ReviewController::includeList());
        $allowed = (new \ReflectionProperty($builder, 'allowedIncludes'))->getValue($builder)
            ->map(fn ($include) => $include->getName())
            ->values()
            ->all();

        $description = $this->parameters('/includes')['include']['description'];
        preg_match_all('/`([^`]+)`/', substr($description, strpos($description, 'Allowed:')), $m);

        $this->assertEqualsCanonicalizing($allowed, $m[1]);
    }

    #[Test]
    public function include_suffixes_follow_the_query_builder_config(): void
    {
        config([
            'query-builder.suffixes' => ['count' => 'Total', 'exists' => 'Present'],
            'query-builder.count_suffix' => 'Total',
            'query-builder.exists_suffix' => 'Present',
        ]);

        $description = $this->parameters('/includes')['include']['description'];

        $this->assertStringContainsString('`customerTotal`', $description);
        $this->assertStringContainsString('`customerPresent`', $description);
    }

    #[Test]
    public function an_already_documented_parameter_is_not_listed_twice(): void
    {
        $result = app(Generator::class)();
        $parameters = (is_array($result) ? $result : $result->toArray())['paths']['/documented']['get']['parameters'];
        $names = array_column($parameters, 'name');

        $this->assertSame(1, count(array_keys($names, 'filter[name]', true)));
        $this->assertContains('filter[email]', $names);
        $this->assertSame('Exact name.', collect($parameters)->firstWhere('name', 'filter[name]')['description']);
    }
}
