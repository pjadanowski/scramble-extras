<?php

namespace PawelJadanowski\ScrambleExtras\Tests\Feature;

use Dedoc\Scramble\Scramble;
use PawelJadanowski\ScrambleExtras\LaravelDataValidationExceptionExtension;
use PawelJadanowski\ScrambleExtras\QueryBuilderOperationExtension;
use PawelJadanowski\ScrambleExtras\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * Scramble's ScrambleServiceProvider::bootingPackage() collects
 * OperationExtension subclasses from config('scramble.extensions') and
 * Scramble::$extensions inside an OperationTransformers mapper, and
 * GeneratorConfig::withOperationTransformers() runs that mapper immediately.
 * The list is therefore frozen when Scramble's provider boots - before this
 * package's provider (package discovery order). Operation extensions must be
 * appended to the pipeline directly, not via Scramble::registerExtensions().
 */
class ServiceProviderRegistrationTest extends TestCase
{
    #[Test]
    public function query_builder_operation_extension_is_in_the_operation_transformer_pipeline(): void
    {
        $this->assertContains(
            QueryBuilderOperationExtension::class,
            Scramble::configure()->operationTransformers->all(),
        );
    }

    #[Test]
    public function data_validation_extension_is_in_the_operation_transformer_pipeline(): void
    {
        $this->assertContains(
            LaravelDataValidationExceptionExtension::class,
            Scramble::configure()->operationTransformers->all(),
        );
    }
}
