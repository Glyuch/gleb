<?php

namespace App\Support\Nutrition;

class Fmt
{
    /**
     * Число с одним знаком после точки без хвостовых нулей: 82.30 → «82.3», 2.0 → «2».
     */
    public static function num(float $value): string
    {
        return rtrim(rtrim(number_format($value, 1, '.', ''), '0'), '.');
    }
}
