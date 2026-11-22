<?php

namespace PawelJadanowski\ScrambleExtras\Tests\Feature;

use Dedoc\Scramble\Generator;
use Illuminate\Routing\Router;
use Illuminate\Support\Collection;
use PawelJadanowski\ScrambleExtras\Tests\Fixtures\QueryUsageController as C;
use PawelJadanowski\ScrambleExtras\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * Query builder configuration that isn't spelled out inline in the route
 * method: lists held in variables, builders set up in methods the route
 * calls, and QueryBuilder subclasses that configure themselves.
 */
class QueryBuilderReachTest extends TestCase
{
    protected function defineRoutes($router): void
    {
        /** @var Router $router */
        foreach (['variables', 'callee', 'subclass', 'subclassFactory', 'plain'] as $method) {
            $router->get("api/$method", [C::class, $method]);
        }
    }

    /**
     * @return Collection<string, array<string, mixed>>
     */
    private function parameters(string $path): Collection
    {
        $result = app(Generator::class)();
        $document = is_array($result) ? $result : $result->toArray();

        return collect($document['paths'][$path]['get']['parameters'] ?? [])->keyBy('name');
    }

    #[Test]
    public function lists_held_in_variables_are_read(): void
    {
        $parameters = $this->parameters('/variables');

        $this->assertTrue($parameters->has('filter[name]'));
        $this->assertTrue($parameters->has('filter[id]'));
        $this->assertStringContainsString('`name`, `created_at`', $parameters['sort']['description']);
    }

    #[Test]
    public function methods_the_route_calls_are_followed(): void
    {
        $parameters = $this->parameters('/callee');

        $this->assertTrue($parameters->has('filter[email]'));
        $this->assertStringContainsString('`email`', $parameters['sort']['description']);
        $this->assertFalse($parameters->has('filter[secret]'));
    }

    #[Test]
    public function query_builder_subclasses_are_read(): void
    {
        foreach (['/subclass', '/subclassFactory'] as $path) {
            $parameters = $this->parameters($path);

            $this->assertTrue($parameters->has('filter[status]'), $path);
            $this->assertTrue($parameters->has('sort'), $path);
            $this->assertTrue($parameters->has('include'), $path);
            // The model comes from parent::__construct(User::query()), so
            // the enum cast on status is found.
            $this->assertSame(['active', 'inactive', 'pending'], $parameters['filter[status]']['schema']['enum'], $path);
        }
    }

    #[Test]
    public function routes_without_a_query_builder_get_nothing(): void
    {
        $this->assertSame([], $this->parameters('/plain')->keys()->all());
    }
}
