<?php

namespace PawelJadanowski\ScrambleExtras\Tests\Feature;

use Dedoc\Scramble\Generator;
use Illuminate\Routing\Router;
use PawelJadanowski\ScrambleExtras\Tests\Fixtures\UploadController;
use PawelJadanowski\ScrambleExtras\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * A Data class with UploadedFile properties is sent as multipart/form-data:
 * files are binary fields, and nested values use the bracket keys PHP
 * parses (address[city], tags[]), since a form field can't hold JSON.
 */
class MultipartUploadTest extends TestCase
{
    protected function defineRoutes($router): void
    {
        /** @var Router $router */
        $router->post('api/uploads', [UploadController::class, 'store']);
        $router->post('api/image', [UploadController::class, 'image']);
        $router->post('api/json', [UploadController::class, 'json']);
    }

    /**
     * @return array<string, mixed>
     */
    private function requestBody(string $path): array
    {
        $result = app(Generator::class)();

        return (is_array($result) ? $result : $result->toArray())['paths'][$path]['post']['requestBody'];
    }

    #[Test]
    public function a_data_class_with_files_is_a_multipart_body(): void
    {
        $content = $this->requestBody('/uploads')['content'];

        $this->assertSame(['multipart/form-data'], array_keys($content));
    }

    #[Test]
    public function files_are_binary_fields(): void
    {
        $properties = $this->requestBody('/uploads')['content']['multipart/form-data']['schema']['properties'];

        $this->assertSame('binary', $properties['avatar']['format']);
        $this->assertStringContainsString('2048 kilobytes', $properties['avatar']['description']);
        $this->assertSame('binary', $properties['photo']['format']);
        $this->assertSame('image/*', $properties['photo']['contentMediaType']);
        $this->assertSame('binary', $properties['files[]']['items']['format']);
        $this->assertSame('binary', $properties['optional']['format']);
    }

    #[Test]
    public function nested_values_use_bracket_keys(): void
    {
        $schema = $this->requestBody('/uploads')['content']['multipart/form-data']['schema'];

        $this->assertSame(
            ['avatar', 'photo', 'files[]', 'address[street]', 'address[city]', 'address[postalCode]', 'tags[]', 'optional'],
            array_keys($schema['properties']),
        );
        $this->assertSame(
            ['avatar', 'photo', 'files[]', 'address[street]', 'address[city]', 'tags[]'],
            $schema['required'],
        );
    }

    #[Test]
    public function a_file_narrowed_to_images_still_makes_the_body_multipart(): void
    {
        // image/* instead of application/octet-stream, which is all
        // Scramble itself looks for.
        $content = $this->requestBody('/image')['content'];

        $this->assertSame(['multipart/form-data'], array_keys($content));
        $this->assertSame('image/*', $content['multipart/form-data']['schema']['properties']['photo']['contentMediaType']);
    }

    #[Test]
    public function bodies_without_files_stay_json(): void
    {
        $content = $this->requestBody('/json')['content'];

        $this->assertSame(['application/json'], array_keys($content));
        $this->assertArrayHasKey('email', $content['application/json']['schema']['properties']);
    }
}
