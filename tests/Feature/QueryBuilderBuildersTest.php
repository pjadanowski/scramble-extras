<?php

namespace PawelJadanowski\ScrambleExtras\Tests\Feature;

use Dedoc\Scramble\Generator;
use PawelJadanowski\ScrambleExtras\Tests\Fixtures\ReviewController;
use PawelJadanowski\ScrambleExtras\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

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
    }

    /**
     * @return \Illuminate\Support\Collection<string, array<string, mixed>>
     */
    private function parameters(): \Illuminate\Support\Collection
    {
        $result = app(Generator::class)();
        $openApi = is_array($result) ? $result : $result->toArray();

        return collect($openApi['paths']['/orders']['get']['parameters'] ?? [])->keyBy('name');
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
    public function includes_list_implicit_count_and_exists_and_builders(): void
    {
        $description = $this->parameters()['include']['description'];

        foreach (['`customer`', '`customerCount`', '`customerExists`', '`items.product`', '`itemsCount`'] as $include) {
            $this->assertStringContainsString($include, $description);
        }
        // Count/exists are only added for top-level relationships.
        $this->assertStringNotContainsString('items.productCount', $description);
    }
}
