<?php

namespace PawelJadanowski\ScrambleExtras\Tests\Feature;

use Dedoc\Scramble\Generator;
use PawelJadanowski\ScrambleExtras\Tests\Fixtures\ReviewController;
use PawelJadanowski\ScrambleExtras\Tests\TestCase;
use Illuminate\Pagination\CursorPaginator;
use Illuminate\Pagination\LengthAwarePaginator;
use PawelJadanowski\ScrambleExtras\Tests\Fixtures\PlainData;
use PHPUnit\Framework\Attributes\Test;
use Spatie\LaravelData\CursorPaginatedDataCollection;
use Spatie\LaravelData\PaginatedDataCollection;

/**
 * The documented envelopes must match what spatie/laravel-data's
 * TransformedDataCollectableResolver actually returns for paginated
 * collections - which is not the JsonResource envelope.
 */
class PaginatedEnvelopeTest extends TestCase
{
    protected function defineRoutes($router): void
    {
        $router->get('api/paginated', [ReviewController::class, 'paginated']);
        $router->get('api/cursor-paginated', [ReviewController::class, 'cursorPaginated']);
    }

    /**
     * @return array<string, mixed>
     */
    private function responseSchema(string $path): array
    {
        $result = app(Generator::class)();
        $openApi = is_array($result) ? $result : $result->toArray();

        return $openApi['paths'][$path]['get']['responses'][200]['content']['application/json']['schema'];
    }

    #[Test]
    public function length_aware_envelope_matches_laravel_data(): void
    {
        $schema = $this->responseSchema('/paginated');

        $this->assertSame(['data', 'links', 'meta'], $schema['required']);

        // `links` is the paginator's link collection: a list of {url, label, active}.
        $links = $schema['properties']['links'];
        $this->assertSame('array', $links['type']);
        $this->assertSame(['url', 'label', 'active'], $links['items']['required']);
        $this->assertSame(['url', 'label', 'page', 'active'], array_keys($links['items']['properties']));
        $this->assertSame(['string', 'null'], $links['items']['properties']['url']['type']);

        $meta = $schema['properties']['meta'];
        $expectedMeta = [
            'current_page', 'first_page_url', 'from', 'last_page', 'last_page_url',
            'next_page_url', 'path', 'per_page', 'prev_page_url', 'to', 'total',
        ];
        $this->assertSame($expectedMeta, array_keys($meta['properties']));
        $this->assertSame($expectedMeta, $meta['required']);
        $this->assertSame(['string', 'null'], $meta['properties']['next_page_url']['type']);
        $this->assertSame(['integer', 'null'], $meta['properties']['from']['type']);
    }

    #[Test]
    public function cursor_envelope_matches_laravel_data(): void
    {
        $schema = $this->responseSchema('/cursor-paginated');

        $this->assertSame(['data', 'links', 'meta'], $schema['required']);
        $this->assertSame('array', $schema['properties']['links']['type']);

        $meta = $schema['properties']['meta'];
        $expectedMeta = ['path', 'per_page', 'next_cursor', 'next_page_url', 'prev_cursor', 'prev_page_url'];
        $this->assertSame($expectedMeta, array_keys($meta['properties']));
        $this->assertSame($expectedMeta, $meta['required']);
        $this->assertSame(['string', 'null'], $meta['properties']['next_cursor']['type']);
    }

    #[Test]
    public function documented_keys_match_a_real_transformed_collection(): void
    {
        $items = [PlainData::from(['firstName' => 'Ada'])];

        $lengthAware = PlainData::collect(
            new LengthAwarePaginator($items, total: 1, perPage: 15),
            PaginatedDataCollection::class,
        )->toArray();
        $cursor = PlainData::collect(
            new CursorPaginator($items, perPage: 15),
            CursorPaginatedDataCollection::class,
        )->toArray();

        $lengthAwareSchema = $this->responseSchema('/paginated');
        $this->assertSame($lengthAwareSchema['required'], array_keys($lengthAware));
        $this->assertSame(array_keys($lengthAwareSchema['properties']['meta']['properties']), array_keys($lengthAware['meta']));
        $linkSchema = $lengthAwareSchema['properties']['links']['items'];
        $this->assertSame([], array_diff(array_keys($lengthAware['links'][0]), array_keys($linkSchema['properties'])), 'Undocumented link keys.');
        $this->assertSame([], array_diff($linkSchema['required'], array_keys($lengthAware['links'][0])), 'Required link keys missing at runtime.');

        $cursorSchema = $this->responseSchema('/cursor-paginated');
        $this->assertSame($cursorSchema['required'], array_keys($cursor));
        $this->assertSame(array_keys($cursorSchema['properties']['meta']['properties']), array_keys($cursor['meta']));
    }
}
