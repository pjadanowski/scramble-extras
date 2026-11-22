<?php

namespace PawelJadanowski\ScrambleExtras\Tests\Feature;

use Dedoc\Scramble\Generator;
use PawelJadanowski\ScrambleExtras\Tests\Fixtures\ReviewController;
use PawelJadanowski\ScrambleExtras\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * Nested Data inside a request body must use the nested class's input
 * projection (input names, no #[Computed]), not a $ref to its output
 * component.
 */
class NestedInputDataTest extends TestCase
{
    protected function defineRoutes($router): void
    {
        // The output component is built first, so a transform cached for the
        // response must not leak into the request body (and vice versa).
        $router->post('api/collection-parent', [ReviewController::class, 'storeCollectionParent']);
        $router->get('api/child', [ReviewController::class, 'showChild']);
        $router->post('api/nested', [ReviewController::class, 'storeNested']);
    }

    /**
     * @return array<string, mixed>
     */
    private function generate(): array
    {
        $result = app(Generator::class)();

        return is_array($result) ? $result : $result->toArray();
    }

    #[Test]
    public function nested_data_in_request_body_uses_input_projection(): void
    {
        $properties = $this->generate()['paths']['/nested']['post']['requestBody']['content']['application/json']['schema']['properties'];

        $this->assertSame(['child_name'], array_keys($properties['child']['properties'] ?? []));
        $this->assertSame(['child_name'], array_keys($properties['children']['items']['properties'] ?? []));
    }

    #[Test]
    public function self_referencing_data_falls_back_to_the_component(): void
    {
        $openApi = $this->generate();
        $parent = $openApi['paths']['/nested']['post']['requestBody']['content']['application/json']['schema']['properties']['parent'];

        $this->assertStringContainsString('#/components/schemas/ParentInputData', json_encode($parent, JSON_UNESCAPED_SLASHES));
    }

    #[Test]
    public function response_still_references_output_components(): void
    {
        $openApi = $this->generate();
        $component = $openApi['components']['schemas']['ParentInputData']['properties'];

        $this->assertSame('#/components/schemas/ChildInputData', $component['child']['$ref'] ?? null);
        $this->assertSame(['label', 'name'], array_keys($openApi['components']['schemas']['ChildInputData']['properties']));
    }

    #[Test]
    public function data_collection_docblock_items_do_not_leak_between_input_and_output(): void
    {
        $openApi = $this->generate();

        // The body is built before any response: its input projection must not
        // end up in the output component or in GET /child.
        $this->assertSame(['label', 'name'], array_keys($openApi['components']['schemas']['ChildInputData']['properties'] ?? []));
        $this->assertSame(
            '#/components/schemas/ChildInputData',
            $openApi['components']['schemas']['CollectionParentData']['properties']['children']['items']['$ref'] ?? null,
        );

        $body = $openApi['paths']['/collection-parent']['post']['requestBody']['content']['application/json']['schema'];
        $this->assertSame(['child_name'], array_keys($body['properties']['children']['items']['properties'] ?? []));
    }
}
