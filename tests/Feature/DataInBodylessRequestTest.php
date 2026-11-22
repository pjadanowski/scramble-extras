<?php

namespace PawelJadanowski\ScrambleExtras\Tests\Feature;

use Dedoc\Scramble\Generator;
use PawelJadanowski\ScrambleExtras\Tests\Fixtures\ReviewController;
use PawelJadanowski\ScrambleExtras\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * On GET/DELETE/HEAD, laravel-data fills a Data argument from the query
 * string. It must be documented as one query parameter per property - not
 * as a `*` parameter `in: body`, which isn't valid OpenAPI 3.
 */
class DataInBodylessRequestTest extends TestCase
{
    protected function defineRoutes($router): void
    {
        $router->get('api/search', [ReviewController::class, 'search']);
        $router->delete('api/search', [ReviewController::class, 'search']);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function parameters(string $method): array
    {
        $result = app(Generator::class)();
        $openApi = is_array($result) ? $result : $result->toArray();

        $this->assertArrayNotHasKey('requestBody', $openApi['paths']['/search'][$method]);

        return $openApi['paths']['/search'][$method]['parameters'] ?? [];
    }

    #[Test]
    public function get_documents_data_properties_as_query_parameters(): void
    {
        $parameters = collect($this->parameters('get'))->keyBy('name');

        $this->assertSame(['term', 'per_page'], $parameters->keys()->all());
        $this->assertSame(['query'], $parameters->pluck('in')->unique()->values()->all());

        $this->assertTrue($parameters['term']['required'] ?? false);
        $this->assertSame('string', $parameters['term']['schema']['type']);
        $this->assertSame('Free-text search term.', $parameters['term']['description'] ?? null);

        $this->assertFalse($parameters['per_page']['required'] ?? false);
        $this->assertSame('integer', $parameters['per_page']['schema']['type']);
    }

    #[Test]
    public function delete_documents_data_properties_as_query_parameters(): void
    {
        $this->assertSame(['term', 'per_page'], array_column($this->parameters('delete'), 'name'));
    }
}
