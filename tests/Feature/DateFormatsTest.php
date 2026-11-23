<?php

namespace PawelJadanowski\ScrambleExtras\Tests\Feature;

use Dedoc\Scramble\Generator;
use Illuminate\Routing\Router;
use PawelJadanowski\ScrambleExtras\Tests\Fixtures\DatesController;
use PawelJadanowski\ScrambleExtras\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * laravel-data writes dates with DateTimeInterfaceTransformer and reads them
 * with DateTimeInterfaceCast, each using its own format argument or
 * config('data.date_format'). Scramble documents every Carbon as date-time.
 */
class DateFormatsTest extends TestCase
{
    protected function defineRoutes($router): void
    {
        /** @var Router $router */
        $router->get('api/dates', [DatesController::class, 'show']);
        $router->post('api/dates', [DatesController::class, 'store']);
    }

    /**
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}
     */
    private function outputAndInput(): array
    {
        $result = app(Generator::class)();
        $document = is_array($result) ? $result : $result->toArray();

        return [
            $document['components']['schemas']['DatesData']['properties'],
            $document['paths']['/dates']['post']['requestBody']['content']['application/json']['schema']['properties'],
        ];
    }

    #[Test]
    public function transformer_and_cast_formats_are_documented(): void
    {
        [$output, $input] = $this->outputAndInput();

        $this->assertSame('date-time', $output['plain']['format']);
        $this->assertSame('date', $output['day']['format']);
        $this->assertArrayNotHasKey('format', $output['local']);
        $this->assertStringContainsString('`d.m.Y`', $output['local']['description']);

        $this->assertSame('date-time', $input['plain']['format']);
        $this->assertSame('date', $input['day']['format']);
        $this->assertSame('date-time', $input['local']['format']);
    }

    #[Test]
    public function a_date_format_validation_attribute_wins_on_input(): void
    {
        [, $input] = $this->outputAndInput();

        $this->assertSame('date-time', $input['validated']['format']);
    }

    #[Test]
    public function the_configured_date_format_is_the_default(): void
    {
        config(['data.date_format' => 'Y-m-d']);

        [$output, $input] = $this->outputAndInput();

        $this->assertSame('date', $output['plain']['format']);
        $this->assertSame('date', $input['plain']['format']);
    }

    #[Test]
    public function several_configured_formats_are_listed_for_input(): void
    {
        config(['data.date_format' => ['Y-m-d', 'Y-m-d\TH:i:sP']]);

        [$output, $input] = $this->outputAndInput();

        // Output uses the first format, input accepts any of them.
        $this->assertSame('date', $output['plain']['format']);
        $this->assertArrayNotHasKey('format', $input['plain']);
        $this->assertStringContainsString('`Y-m-d` or `Y-m-d\TH:i:sP`', $input['plain']['description']);
    }
}
