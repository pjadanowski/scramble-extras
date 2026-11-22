<?php

namespace PawelJadanowski\ScrambleExtras;

use Dedoc\Scramble\Support\Generator\Types\NumberType;

/**
 * NumberType variant supporting exclusiveMinimum/exclusiveMaximum/multipleOf.
 * Scramble's base NumberType only handles inclusive minimum/maximum.
 */
class ConstrainedNumberType extends NumberType
{
    public int|float|null $exclusiveMin = null;

    public int|float|null $exclusiveMax = null;

    public int|float|null $multipleOf = null;

    public function setExclusiveMin(int|float|null $value): self
    {
        $this->exclusiveMin = $value;

        return $this;
    }

    public function setExclusiveMax(int|float|null $value): self
    {
        $this->exclusiveMax = $value;

        return $this;
    }

    public function setMultipleOf(int|float|null $value): self
    {
        $this->multipleOf = $value;

        return $this;
    }

    /**
     * Scramble serializes through serializeAs31()/serializeAs32() (older
     * versions through toArray()), so the extra keywords go into all three.
     */
    public function toArray()
    {
        return $this->withConstraints(parent::toArray());
    }

    public function serializeAs31(): mixed
    {
        return $this->withConstraints(parent::serializeAs31());
    }

    public function serializeAs32(): mixed
    {
        return $this->withConstraints(parent::serializeAs32());
    }

    /**
     * @param  array<string, mixed>  $array
     * @return array<string, mixed>
     */
    private function withConstraints(array $array): array
    {
        return array_merge($array, array_filter([
            'exclusiveMinimum' => $this->exclusiveMin,
            'exclusiveMaximum' => $this->exclusiveMax,
            'multipleOf' => $this->multipleOf,
        ], fn ($v) => $v !== null));
    }
}
