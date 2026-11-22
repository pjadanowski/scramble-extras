<?php

namespace PawelJadanowski\ScrambleExtras\Tests\Feature;

use Dedoc\Scramble\Generator;
use Illuminate\Routing\Router;
use PawelJadanowski\ScrambleExtras\Tests\Fixtures\ToResponseController as C;
use PawelJadanowski\ScrambleExtras\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * Data::toResponse($request) returns a JsonResponse holding the transformed
 * Data; without a return type extension Scramble documented it as a string.
 */
class ToResponseTest extends TestCase
{
    protected function defineRoutes($router): void
    {
        /** @var Router $router */
        foreach (['data', 'collection', 'paginated', 'created', 'abstract'] as $method) {
            $router->get("api/$method", [C::class, $method]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function responses(string $path): array
    {
        $result = app(Generator::class)();

        return (is_array($result) ? $result : $result->toArray())['paths'][$path]['get']['responses'];
    }

    #[Test]
    public function data_to_response_documents_the_data_schema(): void
    {
        $this->assertSame(
            ['$ref' => '#/components/schemas/UserData'],
            $this->responses('/data')['200']['content']['application/json']['schema'],
        );
    }

    #[Test]
    public function collections_to_response_document_their_envelopes(): void
    {
        $this->assertSame(
            ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/UserData']],
            $this->responses('/collection')['200']['content']['application/json']['schema'],
        );

        $paginated = $this->responses('/paginated')['200']['content']['application/json']['schema'];
        $this->assertSame(['data', 'links', 'meta'], array_keys($paginated['properties']));
    }

    #[Test]
    public function a_changed_status_code_is_kept(): void
    {
        $responses = $this->responses('/created');

        $this->assertArrayHasKey('201', $responses);
        $this->assertSame(['$ref' => '#/components/schemas/UserData'], $responses['201']['content']['application/json']['schema']);
    }

    #[Test]
    public function abstract_data_does_not_break_generation(): void
    {
        $this->assertArrayHasKey('200', $this->responses('/abstract'));
    }
}
