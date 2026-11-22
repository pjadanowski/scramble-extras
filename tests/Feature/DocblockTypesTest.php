<?php

namespace PawelJadanowski\ScrambleExtras\Tests\Feature;

use Dedoc\Scramble\Generator;
use PawelJadanowski\ScrambleExtras\Tests\Fixtures\ReviewController;
use PawelJadanowski\ScrambleExtras\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * PHPDoc types are read with a real PHPDoc parser: generics with spaces in
 * constructor `@param` tags, and multi-line `@var` array shapes.
 */
class DocblockTypesTest extends TestCase
{
    protected function defineRoutes($router): void
    {
        $router->get('api/doc-typed', [ReviewController::class, 'docTyped']);
    }

    /**
     * @return array<string, mixed>
     */
    private function properties(): array
    {
        $result = app(Generator::class)();
        $openApi = is_array($result) ? $result : $result->toArray();

        return $openApi['components']['schemas']['DocTypedData']['properties'];
    }

    #[Test]
    public function constructor_param_generic_with_spaces_is_used(): void
    {
        $properties = $this->properties();

        $this->assertSame('integer', $properties['ids']['items']['type'] ?? null);
        $this->assertSame('#/components/schemas/PlainData', $properties['people']['items']['$ref'] ?? null);
    }

    #[Test]
    public function multi_line_var_array_shape_is_used(): void
    {
        $location = $this->properties()['location'];

        $this->assertSame('object', $location['type']);
        $this->assertSame(['lat', 'lng'], array_keys($location['properties'] ?? []));
        // The shape's continuation lines are part of the tag, not a description.
        $this->assertArrayNotHasKey('description', $location);
    }

    #[Test]
    public function native_union_types_document_every_member(): void
    {
        $properties = $this->properties();

        $identifier = json_encode($properties['identifier']);
        $this->assertStringContainsString('integer', $identifier);
        $this->assertStringContainsString('string', $identifier);

        $ownerOrId = json_encode($properties['ownerOrId'], JSON_UNESCAPED_SLASHES);
        $this->assertStringContainsString('integer', $ownerOrId);
        $this->assertStringContainsString('#/components/schemas/PlainData', $ownerOrId);
    }

    #[Test]
    public function native_union_types_with_null_stay_nullable(): void
    {
        $properties = $this->properties();

        $this->assertStringContainsString('null', json_encode($properties['maybeIdentifier']));
        $this->assertStringContainsString('null', json_encode($properties['maybeOwner']));
    }
}
