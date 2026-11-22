<?php

namespace PawelJadanowski\ScrambleExtras\Tests\Fixtures;

use Spatie\LaravelData\Attributes\Validation as V;

/**
 * Carrier class whose properties hold spatie/laravel-data validation
 * attributes. Used by SchemaAttributeApplierTest, which reflects each property
 * and feeds it to SchemaAttributeApplier together with a chosen base type.
 *
 * The attributes are never instantiated by the applier (it reads raw
 * arguments), so even argument shapes that wouldn't pass Spatie's own runtime
 * checks are fine here.
 */
class ValidationFixtures
{
    #[V\Email]
    public string $email;

    #[V\Url]
    public string $url;

    #[V\Uuid]
    public string $uuid;

    #[V\Min(3)]
    public string $minString;

    #[V\Max(10)]
    public string $maxString;

    #[V\Between(2, 8)]
    public string $betweenString;

    #[V\Min(5)]
    public int $minInt;

    #[V\GreaterThan(0)]
    public int $positiveInt;

    #[V\LessThan(100)]
    public int $belowHundred;

    #[V\MultipleOf(5)]
    public int $multipleOfFive;

    #[V\Regex('/^[a-z]+$/')]
    public string $regexed;

    #[V\AlphaDash]
    public string $slug;

    #[V\StartsWith('foo', 'bar')]
    public string $prefixed;

    #[V\In('a', 'b', 'c')]
    public string $inList;

    #[V\Enum(StatusEnum::class)]
    public string $enumBacked;

    #[V\Digits(4)]
    public string $pin;

    #[V\Image]
    public string $avatar;

    #[V\File]
    public string $attachment;

    #[V\Required]
    public ?string $forcedRequired;

    #[V\Sometimes]
    public string $forcedOptional;

    #[V\Confirmed]
    public string $passwordConfirmed;

    #[V\Same('password')]
    public string $sameAsPassword;

    #[V\Exists('users', 'id')]
    public int $existingUser;

    #[V\RequiredIf('type', 'admin')]
    public ?string $conditionallyRequired;

    #[V\Nullable]
    public string $explicitlyNullable;

    public string $plain;

    #[V\MinDigits(3)]
    public int $minDigitsInt;

    #[V\MaxDigits(4)]
    public int $maxDigitsInt;

    #[V\Digits(4)]
    public int $digitsInt;

    #[V\DigitsBetween(2, 4)]
    public int $digitsBetweenInt;

    #[V\Min(1)]
    public array $minItems;

    #[V\Max(5)]
    public array $maxItems;

    #[V\Size(2)]
    public array $sizeItems;

    #[V\Distinct]
    public array $distinctItems;

    #[V\Max(2048)]
    public \Illuminate\Http\UploadedFile $upload;

    #[V\File, V\Max(1024)]
    public string $fileByAttribute;

    #[V\Regex('/^[a-z]+$/i')]
    public string $caseInsensitiveRegex;

    #[V\Regex('{^\d+$}')]
    public string $braceDelimitedRegex;

    #[V\DateFormat('Y-m-d')]
    public string $isoDate;

    #[V\DateFormat('Y-m-d\TH:i:sP')]
    public string $isoDateTime;

    #[V\DateFormat('d/m/Y')]
    public string $customDate;

    /**
     * @example 5
     */
    #[V\GreaterThan(0)]
    public int $positiveWithExample;
}
