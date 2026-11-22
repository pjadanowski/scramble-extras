<?php

namespace PawelJadanowski\ScrambleExtras\Tests\Feature;

use Dedoc\Scramble\Scramble;
use PawelJadanowski\ScrambleExtras\QueryBuilderOperationExtension;
use PawelJadanowski\ScrambleExtras\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * Users who applied the workaround from the issue (listing the extension in
 * config/scramble.php) must not end up with the extension running twice -
 * that would emit every filter/sort/include parameter twice.
 */
class ConfigListedQueryBuilderExtensionTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('scramble.extensions', [QueryBuilderOperationExtension::class]);
    }

    #[Test]
    public function extension_listed_in_config_is_not_registered_twice(): void
    {
        $occurrences = array_filter(
            Scramble::configure()->operationTransformers->all(),
            fn ($transformer) => $transformer === QueryBuilderOperationExtension::class,
        );

        $this->assertCount(1, $occurrences);
    }
}
