<?php

namespace PawelJadanowski\ScrambleExtras\Tests\Feature;

use Dedoc\Scramble\Generator;
use Illuminate\Routing\Router;
use Illuminate\Support\Collection;
use PawelJadanowski\ScrambleExtras\Tests\Fixtures\ReviewController;
use PawelJadanowski\ScrambleExtras\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * A paginated Data collection reads the page (or cursor) from the query
 * string, so the operation documents that parameter.
 */
class PaginationParametersTest extends TestCase
{
    protected function defineRoutes($router): void
    {
        /** @var Router $router */
        $router->get('api/paginated', [ReviewController::class, 'paginated']);
        $router->get('api/paginated-response', [ReviewController::class, 'paginatedResponse']);
        $router->get('api/paginated-documented', [ReviewController::class, 'paginatedDocumented']);
        $router->get('api/paginated-json', [ReviewController::class, 'paginatedJson']);
        $router->get('api/cursor-paginated', [ReviewController::class, 'cursorPaginated']);
        $router->get('api/constraints', [ReviewController::class, 'constraints']);
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
    public function length_aware_pagination_documents_the_page(): void
    {
        foreach (['/paginated', '/paginated-response'] as $path) {
            $page = $this->parameters($path)['page'] ?? null;

            $this->assertNotNull($page, $path);
            $this->assertSame('query', $page['in']);
            $this->assertSame(['type' => 'integer', 'minimum' => 1], $page['schema']);
        }
    }

    #[Test]
    public function cursor_pagination_documents_the_cursor(): void
    {
        $parameters = $this->parameters('/cursor-paginated');

        $this->assertSame(['type' => 'string'], $parameters['cursor']['schema']);
        $this->assertFalse($parameters->has('page'));
    }

    #[Test]
    public function existing_page_parameters_are_kept(): void
    {
        $documented = $this->parameters('/paginated-documented');
        $this->assertSame('Page to show.', $documented['page']['description']);

        $json = $this->parameters('/paginated-json');
        $this->assertTrue($json->has('page[number]'));
        $this->assertFalse($json->has('page'));
    }

    #[Test]
    public function other_responses_get_no_pagination_parameter(): void
    {
        $this->assertSame([], $this->parameters('/constraints')->keys()->all());
    }
}
