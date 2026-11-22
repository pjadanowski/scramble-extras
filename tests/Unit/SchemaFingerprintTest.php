<?php

namespace PawelJadanowski\ScrambleExtras\Tests\Unit;

use Dedoc\Scramble\Support\Generator\Components;
use Dedoc\Scramble\Support\Generator\TypeTransformer;
use PawelJadanowski\ScrambleExtras\LaravelDataReflector;
use PawelJadanowski\ScrambleExtras\Tests\Fixtures\BaseInheritedData;
use PawelJadanowski\ScrambleExtras\Tests\Fixtures\HasNoteField;
use PawelJadanowski\ScrambleExtras\Tests\Fixtures\InheritedData;
use PawelJadanowski\ScrambleExtras\Tests\Fixtures\StatusEnum;
use PawelJadanowski\ScrambleExtras\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use ReflectionClass;

/**
 * A cached schema must be rebuilt when anything it was built from changes,
 * not only the Data class's own file.
 */
class SchemaFingerprintTest extends TestCase
{
    /**
     * @return array<string, array{class-string}>
     */
    public static function sources(): array
    {
        return [
            'the class itself' => [InheritedData::class],
            'a parent class' => [BaseInheritedData::class],
            'a trait' => [HasNoteField::class],
            'a class named in an attribute' => [StatusEnum::class],
        ];
    }

    #[Test]
    #[DataProvider('sources')]
    public function fingerprint_changes_when_a_source_file_changes(string $source): void
    {
        $reflector = new class($this->createStub(TypeTransformer::class), new Components) extends LaravelDataReflector
        {
            public function fingerprintOf(string $class): string
            {
                return $this->fingerprint($class);
            }
        };

        $file = (new ReflectionClass($source))->getFileName();
        $originalMtime = filemtime($file);
        $before = $reflector->fingerprintOf(InheritedData::class);

        try {
            touch($file, $originalMtime + 60);
            clearstatcache(true, $file);

            $this->assertNotSame($before, $reflector->fingerprintOf(InheritedData::class));
        } finally {
            touch($file, $originalMtime);
            clearstatcache(true, $file);
        }
    }
}
