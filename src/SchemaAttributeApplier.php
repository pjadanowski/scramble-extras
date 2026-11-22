<?php

namespace PawelJadanowski\ScrambleExtras;

use Dedoc\Scramble\Support\Generator\Types\ArrayType as OpenApiArrayType;
use Dedoc\Scramble\Support\Generator\Types\BooleanType as OpenApiBooleanType;
use Dedoc\Scramble\Support\Generator\Types\IntegerType as OpenApiIntegerType;
use Dedoc\Scramble\Support\Generator\Types\NumberType as OpenApiNumberType;
use Dedoc\Scramble\Support\Generator\Types\StringType as OpenApiStringType;
use Dedoc\Scramble\Support\Generator\Types\Type as OpenApiType;
use Illuminate\Support\Arr;
use ReflectionAttribute;
use ReflectionEnum;
use ReflectionProperty;
use Spatie\LaravelData\Attributes\Validation as V;

/**
 * Translates every spatie/laravel-data validation attribute into one of:
 *  - an OpenAPI schema field (format, pattern, enum, minLength/maxLength,
 *    minimum/maximum, exclusiveMinimum/Maximum, multipleOf, contentMediaType,
 *    nullable),
 *  - a `required[]` override (`#[Required]` forces required, `#[Sometimes]`
 *    forces non-required),
 *  - or a constraint note appended to the property description (for things
 *    OpenAPI can't express natively, like `#[Same]`, `#[Confirmed]`,
 *    `#[Exists]`, conditional Required*, etc.).
 *
 * Also reads `@example`/`@default` from the property phpdoc.
 */
class SchemaAttributeApplier
{
    public function analyze(ReflectionProperty $property, OpenApiType $type): AttributeAnalysis
    {
        $analysis = new AttributeAnalysis($type);
        $analysis->isFile = $this->isFileProperty($property);

        foreach ($property->getAttributes() as $attribute) {
            $this->dispatch($attribute, $analysis);
        }

        return $analysis;
    }

    public function applyExamplesFromPhpDoc(ReflectionProperty $property, OpenApiType $type): void
    {
        $doc = (string) ($property->getDocComment() ?: '');
        if ($doc === '') {
            return;
        }

        if (preg_match_all('/@example\s+([^\n*]+)/', $doc, $matches)) {
            $examples = array_map(fn ($v) => $this->coerceScalar($type, trim($v)), $matches[1]);
            if (count($examples) === 1) {
                $type->example($examples[0]);
            } elseif (count($examples) > 1) {
                $type->examples($examples);
            }
        }

        if (preg_match('/@default\s+([^\n*]+)/', $doc, $m)) {
            $type->default($this->coerceScalar($type, trim($m[1])));
        }
    }

    /**
     * @param  ReflectionAttribute<object>  $attribute
     */
    protected function dispatch(ReflectionAttribute $attribute, AttributeAnalysis $a): void
    {
        $name = $attribute->getName();
        $args = $attribute->getArguments();

        match (true) {
            // --- Formats -----------------------------------------------------
            $name === V\Email::class => $this->setFormat($a, 'email'),
            $name === V\Url::class,
            $name === V\ActiveUrl::class => $this->setFormat($a, 'uri'),
            $name === V\Uuid::class => $this->setFormat($a, 'uuid'),
            $name === V\Ulid::class => $this->setFormat($a, 'ulid'),
            $name === V\IP::class => $this->setFormat($a, 'ip'),
            $name === V\IPv4::class => $this->setFormat($a, 'ipv4'),
            $name === V\IPv6::class => $this->setFormat($a, 'ipv6'),
            $name === V\MacAddress::class => $this->setFormat($a, 'mac'),
            $name === V\Json::class => $this->setFormat($a, 'json'),
            $name === V\Date::class => $this->setFormat($a, 'date'),
            $name === V\Timezone::class => $this->setFormat($a, 'timezone'),
            $name === V\Password::class => $this->setFormat($a, 'password'),
            $name === V\DateFormat::class => $this->applyDateFormat($a, $args),

            // --- Numeric / length bounds -------------------------------------
            $name === V\Min::class => $this->applyMin($a, $args[0] ?? null),
            $name === V\Max::class => $this->applyMax($a, $args[0] ?? null),
            $name === V\Between::class => $this->applyBetween($a, $args),
            $name === V\Size::class => $this->applySize($a, $args[0] ?? null),
            $name === V\GreaterThan::class => $this->applyExclusiveBound($a, 'min', $args[0] ?? null),
            $name === V\GreaterThanOrEqualTo::class => $this->applyMin($a, $this->fieldOrNumber($args[0] ?? null)),
            $name === V\LessThan::class => $this->applyExclusiveBound($a, 'max', $args[0] ?? null),
            $name === V\LessThanOrEqualTo::class => $this->applyMax($a, $this->fieldOrNumber($args[0] ?? null)),
            $name === V\MultipleOf::class => $this->applyMultipleOf($a, $args[0] ?? null),

            // --- Digits (numeric strings) ------------------------------------
            $name === V\Digits::class => $this->applyDigits($a, $args[0] ?? null),
            $name === V\DigitsBetween::class => $this->applyDigitsBetween($a, $args[0] ?? null, $args[1] ?? null),
            $name === V\MinDigits::class => $this->applyMinDigits($a, $args[0] ?? null),
            $name === V\MaxDigits::class => $this->applyMaxDigits($a, $args[0] ?? null),

            // --- Patterns ---------------------------------------------------
            $name === V\Regex::class => $this->applyRegex($a, $args[0] ?? null),
            $name === V\NotRegex::class => $a->addNote('Must not match pattern '.$this->stringify($args[0] ?? null)),
            $name === V\Alpha::class => $this->applyPattern($a, '^[A-Za-z]+$'),
            $name === V\AlphaDash::class => $this->applyPattern($a, '^[A-Za-z0-9_-]+$'),
            $name === V\AlphaNumeric::class => $this->applyPattern($a, '^[A-Za-z0-9]+$'),
            $name === V\Lowercase::class => $this->applyPattern($a, '^[^A-Z]*$'),
            $name === V\Uppercase::class => $this->applyPattern($a, '^[^a-z]*$'),
            $name === V\StartsWith::class => $this->applyPattern($a, '^('.$this->joinAlternatives(Arr::flatten($args)).')'),
            $name === V\EndsWith::class => $this->applyPattern($a, '('.$this->joinAlternatives(Arr::flatten($args)).')$'),
            $name === V\DoesntStartWith::class => $a->addNote('Must not start with: '.implode(', ', Arr::flatten($args))),
            $name === V\DoesntEndWith::class => $a->addNote('Must not end with: '.implode(', ', Arr::flatten($args))),

            // --- Enums ------------------------------------------------------
            $name === V\In::class => $this->applyEnum($a, $args),
            $name === V\NotIn::class => $a->addNote('Must not be one of: '.implode(', ', $this->scalarize($args))),
            $name === V\InArray::class => $a->addNote('Must exist in field '.$this->stringify($args[0] ?? null)),
            $name === V\Enum::class => $this->applyEnumClass($a, $args[0] ?? null),

            // --- Type reinforcement -----------------------------------------
            $name === V\StringType::class => $this->ensureType($a, OpenApiStringType::class),
            $name === V\IntegerType::class => $this->ensureType($a, OpenApiIntegerType::class),
            $name === V\Numeric::class => $this->ensureType($a, OpenApiNumberType::class),
            $name === V\BooleanType::class => $this->ensureType($a, OpenApiBooleanType::class),
            $name === V\ArrayType::class,
            $name === V\ListType::class => $this->ensureType($a, OpenApiArrayType::class),

            // --- Booleans / acceptance --------------------------------------
            $name === V\Accepted::class => $this->applyAccepted($a, true),
            $name === V\Declined::class => $this->applyAccepted($a, false),
            $name === V\AcceptedIf::class => $a->addNote('Must be accepted when '.$this->stringify($args[0] ?? null).' = '.$this->stringify($args[1] ?? null)),
            $name === V\DeclinedIf::class => $a->addNote('Must be declined when '.$this->stringify($args[0] ?? null).' = '.$this->stringify($args[1] ?? null)),

            // --- Files ------------------------------------------------------
            $name === V\File::class => $this->applyContentMedia($a, 'application/octet-stream'),
            $name === V\Image::class => $this->applyContentMedia($a, 'image/*'),
            $name === V\Mimes::class => $a->addNote('Allowed file extensions: '.implode(', ', Arr::flatten($args))),
            $name === V\MimeTypes::class => $a->addNote('Allowed mime types: '.implode(', ', Arr::flatten($args))),
            $name === V\Dimensions::class => $a->addNote('Image dimension constraints apply.'),

            // --- Required / presence ---------------------------------------
            $name === V\Required::class => $a->forceRequired = true,
            $name === V\Sometimes::class => $a->forceRequired = false,
            $name === V\Filled::class => $a->addNote('Must not be empty when present.'),
            $name === V\Present::class => $a->addNote('Must be present in the payload.'),
            $name === V\RequiredIf::class => $a->addNote('Required when '.$this->stringify($args[0] ?? null).' = '.implode('|', $this->scalarize(array_slice($args, 1)))),
            $name === V\RequiredUnless::class => $a->addNote('Required unless '.$this->stringify($args[0] ?? null).' = '.implode('|', $this->scalarize(array_slice($args, 1)))),
            $name === V\RequiredWith::class => $a->addNote('Required with: '.implode(', ', Arr::flatten($args))),
            $name === V\RequiredWithAll::class => $a->addNote('Required when all present: '.implode(', ', Arr::flatten($args))),
            $name === V\RequiredWithout::class => $a->addNote('Required without: '.implode(', ', Arr::flatten($args))),
            $name === V\RequiredWithoutAll::class => $a->addNote('Required when none present: '.implode(', ', Arr::flatten($args))),
            $name === V\RequiredArrayKeys::class => $a->addNote('Must contain keys: '.implode(', ', Arr::flatten($args))),

            // --- Prohibited / exclude --------------------------------------
            $name === V\Prohibited::class => $a->addNote('Must not be present.'),
            $name === V\ProhibitedIf::class => $a->addNote('Prohibited when '.$this->stringify($args[0] ?? null).' = '.implode('|', $this->scalarize(array_slice($args, 1)))),
            $name === V\ProhibitedUnless::class => $a->addNote('Prohibited unless '.$this->stringify($args[0] ?? null).' = '.implode('|', $this->scalarize(array_slice($args, 1)))),
            $name === V\Prohibits::class => $a->addNote('Prohibits: '.implode(', ', Arr::flatten($args))),
            $name === V\Exclude::class => $a->addNote('Excluded from the payload.'),
            $name === V\ExcludeIf::class,
            $name === V\ExcludeUnless::class,
            $name === V\ExcludeWith::class,
            $name === V\ExcludeWithout::class => $a->addNote('Conditionally excluded.'),

            // --- Cross-field / DB ------------------------------------------
            $name === V\Confirmed::class => $a->addNote('Must be confirmed by a matching `_confirmation` field.'),
            $name === V\Same::class => $a->addNote('Must equal field '.$this->stringify($args[0] ?? null).'.'),
            $name === V\Different::class => $a->addNote('Must differ from field '.$this->stringify($args[0] ?? null).'.'),
            $name === V\CurrentPassword::class => $a->addNote('Must match the authenticated user\'s current password.'),
            $name === V\Exists::class => $a->addNote('Must exist in `'.$this->stringify($args[0] ?? '').'`.'),
            $name === V\Unique::class => $a->addNote('Must be unique in `'.$this->stringify($args[0] ?? '').'`.'),
            $name === V\Distinct::class => $this->applyDistinct($a),

            // --- Dates ------------------------------------------------------
            $name === V\After::class => $a->addNote('Must be after '.$this->stringify($args[0] ?? null).'.'),
            $name === V\AfterOrEqual::class => $a->addNote('Must be on or after '.$this->stringify($args[0] ?? null).'.'),
            $name === V\Before::class => $a->addNote('Must be before '.$this->stringify($args[0] ?? null).'.'),
            $name === V\BeforeOrEqual::class => $a->addNote('Must be on or before '.$this->stringify($args[0] ?? null).'.'),
            $name === V\DateEquals::class => $a->addNote('Must equal date '.$this->stringify($args[0] ?? null).'.'),

            // --- Misc -------------------------------------------------------
            $name === V\Nullable::class => $a->type->nullable(true),
            $name === V\Bail::class => null, // validation flow only
            default => null,
        };
    }

    // ---------------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------------

    protected function setFormat(AttributeAnalysis $a, string $format): void
    {
        $a->type->format($format);
    }

    /**
     * PHP date formats that correspond to an OpenAPI/JSON Schema format.
     */
    private const DATE_FORMATS = [
        'Y-m-d' => 'date',
        'Y-m-d\\TH:i:sP' => 'date-time',
        'Y-m-d\\TH:i:sp' => 'date-time',
        'Y-m-d\\TH:i:s.vP' => 'date-time',
        'Y-m-d\\TH:i:s.uP' => 'date-time',
        'c' => 'date-time',
        'H:i:s' => 'time',
    ];

    /**
     * @param  array<int, mixed>  $args
     */
    protected function applyDateFormat(AttributeAnalysis $a, array $args): void
    {
        $formats = array_values(array_filter(Arr::flatten($args), 'is_string'));
        if ($formats === []) {
            return;
        }

        if (count($formats) === 1 && isset(self::DATE_FORMATS[$formats[0]])) {
            $a->type->format(self::DATE_FORMATS[$formats[0]]);

            return;
        }

        $a->addNote('Date format: '.implode(' or ', array_map(fn ($f) => "`$f`", $formats)).' (PHP date format).');
    }

    protected function applyMin(AttributeAnalysis $a, mixed $value): void
    {
        if (! is_numeric($value)) {
            return;
        }

        $value = $value + 0;
        $type = $a->type;

        if ($a->isFile) {
            $a->addNote("Minimum file size: {$value} kilobytes.");
        } elseif ($type instanceof OpenApiArrayType) {
            $type->setMin((int) $value);
        } elseif ($type instanceof OpenApiStringType) {
            $type->setMin((int) $value);
        } elseif ($type instanceof OpenApiNumberType) {
            $type->setMin($value);
        }
    }

    protected function applyMax(AttributeAnalysis $a, mixed $value): void
    {
        if (! is_numeric($value)) {
            return;
        }

        $value = $value + 0;
        $type = $a->type;

        if ($a->isFile) {
            $a->addNote("Maximum file size: {$value} kilobytes.");
        } elseif ($type instanceof OpenApiArrayType) {
            $type->setMax((int) $value);
        } elseif ($type instanceof OpenApiStringType) {
            $type->setMax((int) $value);
        } elseif ($type instanceof OpenApiNumberType) {
            $type->setMax($value);
        }
    }

    /**
     * @param  array<int, mixed>  $args
     */
    protected function applyBetween(AttributeAnalysis $a, array $args): void
    {
        $this->applyMin($a, $args[0] ?? null);
        $this->applyMax($a, $args[1] ?? null);
    }

    protected function applySize(AttributeAnalysis $a, mixed $value): void
    {
        $this->applyMin($a, $value);
        $this->applyMax($a, $value);
    }

    /**
     * @param  'min'|'max'  $bound
     */
    protected function applyExclusiveBound(AttributeAnalysis $a, string $bound, mixed $value): void
    {
        $value = $this->fieldOrNumber($value);
        if (! is_numeric($value)) {
            return;
        }

        $type = $a->type;
        if (! ($type instanceof OpenApiNumberType)) {
            return;
        }

        $rich = $this->upgradeNumber($a, $type);
        $bound === 'min'
            ? $rich->setExclusiveMin($value + 0)
            : $rich->setExclusiveMax($value + 0);
    }

    protected function applyMultipleOf(AttributeAnalysis $a, mixed $value): void
    {
        if (! is_numeric($value)) {
            return;
        }

        $type = $a->type;
        if (! ($type instanceof OpenApiNumberType)) {
            return;
        }

        $this->upgradeNumber($a, $type)->setMultipleOf($value + 0);
    }

    protected function upgradeNumber(AttributeAnalysis $a, OpenApiNumberType $current): ConstrainedNumberType
    {
        if ($current instanceof ConstrainedNumberType) {
            return $current;
        }

        $rich = new ConstrainedNumberType($current->type);
        $rich->setMin($current->min);
        $rich->setMax($current->max);
        $rich->addProperties($current);
        $rich->format($current->format);
        $a->type = $rich;

        return $rich;
    }

    protected function applyDigits(AttributeAnalysis $a, mixed $count): void
    {
        if (! is_numeric($count)) {
            return;
        }

        $this->applyDigitsBetween($a, $count, $count);
    }

    /**
     * On an integer, a digit count bounds the value (3 digits: 100-999); on
     * a string, it bounds the length and the characters.
     */
    protected function applyDigitsBetween(AttributeAnalysis $a, mixed $min, mixed $max): void
    {
        if (! is_numeric($min) || ! is_numeric($max)) {
            return;
        }

        $min = (int) $min;
        $max = (int) $max;

        if ($a->type instanceof OpenApiIntegerType) {
            $a->type->setMin($this->smallestWithDigits($min));
            $a->type->setMax($this->largestWithDigits($max));

            return;
        }

        if ($a->type instanceof OpenApiStringType) {
            $a->type->setMin($min);
            $a->type->setMax($max);
        }

        $this->applyPattern($a, $min === $max ? '^\\d{'.$min.'}$' : '^\\d{'.$min.','.$max.'}$');
    }

    protected function applyMinDigits(AttributeAnalysis $a, mixed $count): void
    {
        if (! is_numeric($count)) {
            return;
        }

        if ($a->type instanceof OpenApiIntegerType) {
            $a->type->setMin($this->smallestWithDigits((int) $count));
        } elseif ($a->type instanceof OpenApiStringType) {
            $a->type->setMin((int) $count);
        }
    }

    protected function applyMaxDigits(AttributeAnalysis $a, mixed $count): void
    {
        if (! is_numeric($count)) {
            return;
        }

        if ($a->type instanceof OpenApiIntegerType) {
            $a->type->setMax($this->largestWithDigits((int) $count));
        } elseif ($a->type instanceof OpenApiStringType) {
            $a->type->setMax((int) $count);
        }
    }

    protected function smallestWithDigits(int $digits): int
    {
        return $digits <= 1 ? 0 : 10 ** ($digits - 1);
    }

    protected function largestWithDigits(int $digits): int
    {
        return 10 ** max($digits, 1) - 1;
    }

    protected function applyDistinct(AttributeAnalysis $a): void
    {
        // setUniqueItems() doesn't exist on older Scramble versions.
        if ($a->type instanceof OpenApiArrayType && method_exists($a->type, 'setUniqueItems')) {
            $a->type->setUniqueItems(true);

            return;
        }

        $a->addNote('All array items must be distinct.');
    }

    protected function isFileProperty(ReflectionProperty $property): bool
    {
        $type = $property->getType();
        $types = $type instanceof \ReflectionUnionType ? $type->getTypes() : ($type ? [$type] : []);

        foreach ($types as $t) {
            if ($t instanceof \ReflectionNamedType && is_a($t->getName(), \SplFileInfo::class, true)) {
                return true;
            }
        }

        foreach ([V\File::class, V\Image::class, V\Mimes::class, V\MimeTypes::class, V\Dimensions::class] as $fileRule) {
            if ($property->getAttributes($fileRule) !== []) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<int, mixed>  $args
     */
    protected function applyEnum(AttributeAnalysis $a, array $args): void
    {
        $values = $this->scalarize(Arr::flatten($args));

        if ($values !== []) {
            $a->type->enum(array_values(array_unique($values, SORT_REGULAR)));
        }
    }

    protected function applyEnumClass(AttributeAnalysis $a, mixed $enumClass): void
    {
        if (! is_string($enumClass) || ! enum_exists($enumClass)) {
            return;
        }

        try {
            $reflection = new ReflectionEnum($enumClass);
        } catch (\ReflectionException) {
            return;
        }

        if (! $reflection->isBacked()) {
            return;
        }

        $values = array_map(
            fn (\BackedEnum $case) => $case->value,
            $enumClass::cases(),
        );

        $a->type->enum($values);
    }

    protected function applyPattern(AttributeAnalysis $a, mixed $pattern): void
    {
        if (! is_string($pattern) || $pattern === '') {
            return;
        }

        $type = $a->type;
        if (! ($type instanceof OpenApiStringType)) {
            return;
        }

        $patterned = $type instanceof PatternedStringType
            ? $type
            : (new PatternedStringType)
                ->setMin($type->min)
                ->setMax($type->max)
                ->addProperties($type);

        if (! ($type instanceof PatternedStringType)) {
            $patterned->format($type->format);
        }

        $patterned->setPattern($pattern);
        $a->type = $patterned;
    }

    protected function applyContentMedia(AttributeAnalysis $a, string $mediaType): void
    {
        if ($a->type instanceof OpenApiStringType) {
            $a->type->contentMediaType($mediaType);
            $a->type->contentEncoding('binary');
        }
    }

    protected function applyAccepted(AttributeAnalysis $a, bool $value): void
    {
        if ($a->type instanceof OpenApiBooleanType) {
            $a->type->enum([$value]);

            return;
        }

        $a->addNote($value ? 'Must be accepted (true/yes/on/1).' : 'Must be declined (false/no/off/0).');
    }

    /**
     * @param  class-string<OpenApiType>  $targetClass
     */
    protected function ensureType(AttributeAnalysis $a, string $targetClass): void
    {
        if ($a->type instanceof $targetClass) {
            return;
        }

        $newType = new $targetClass;
        $newType->addProperties($a->type);
        $a->type = $newType;
    }

    /**
     * A PCRE pattern becomes a JSON Schema `pattern` when it can be carried
     * over as is: delimiters are dropped, but modifiers (`/i`, `/u`, ...)
     * can't be expressed there, so a pattern with modifiers is described in
     * a note instead of being documented with a different meaning.
     */
    protected function applyRegex(AttributeAnalysis $a, mixed $regex): void
    {
        if (! is_string($regex) || $regex === '') {
            return;
        }

        $closing = ['{' => '}', '(' => ')', '[' => ']', '<' => '>'];
        $open = $regex[0];
        $close = $closing[$open] ?? $open;
        $end = strrpos($regex, $close);

        if (ctype_alnum($open) || $open === '\\' || $end === false || $end === 0) {
            $this->applyPattern($a, $regex);

            return;
        }

        $pattern = substr($regex, 1, $end - 1);
        $modifiers = substr($regex, $end + 1);

        if ($modifiers !== '') {
            $a->addNote("Must match pattern `{$regex}`.");

            return;
        }

        $this->applyPattern($a, $pattern);
    }

    /**
     * @param  array<int, mixed>  $values
     */
    protected function joinAlternatives(array $values): string
    {
        return implode('|', array_map(
            fn ($v) => preg_quote((string) $v, '/'),
            array_filter($values, fn ($v) => is_scalar($v)),
        ));
    }

    /**
     * @param  array<int, mixed>  $values
     * @return array<int, scalar>
     */
    protected function scalarize(array $values): array
    {
        $out = [];
        foreach (Arr::flatten($values) as $v) {
            if ($v instanceof \BackedEnum) {
                $out[] = $v->value;
            } elseif ($v instanceof \UnitEnum) {
                $out[] = $v->name;
            } elseif (is_scalar($v)) {
                $out[] = $v;
            }
        }

        return $out;
    }

    protected function fieldOrNumber(mixed $value): mixed
    {
        if (is_numeric($value)) {
            return $value;
        }

        return null;
    }

    protected function stringify(mixed $value): string
    {
        if ($value === null) {
            return '';
        }
        if ($value instanceof \BackedEnum) {
            return (string) $value->value;
        }
        if (is_scalar($value)) {
            return (string) $value;
        }
        if (is_object($value) && method_exists($value, '__toString')) {
            return (string) $value;
        }

        return '';
    }

    protected function coerceScalar(OpenApiType $type, string $raw): mixed
    {
        if ($type instanceof OpenApiIntegerType && is_numeric($raw)) {
            return (int) $raw;
        }
        if ($type instanceof OpenApiNumberType && is_numeric($raw)) {
            return (float) $raw;
        }
        if (in_array(strtolower($raw), ['true', 'false'], true)) {
            return strtolower($raw) === 'true';
        }

        return $raw;
    }
}
