<?php

namespace App\Support\Fees;

use InvalidArgumentException;

/** Exact minor-unit arithmetic; floating point is only used by the existing display formatter. */
final class Money
{
    public static function minor(string|int $amount): int
    {
        $value = (string) $amount;
        if (! preg_match('/^-?\d{1,15}(?:\.\d{1,2})?$/D', $value)) {
            throw new InvalidArgumentException('Expected a decimal amount with at most two decimal places.');
        }
        $negative = str_starts_with($value, '-');
        $parts = explode('.', ltrim($value, '-'));
        $minor = ((int) $parts[0] * 100) + (int) str_pad($parts[1] ?? '', 2, '0');

        return $negative ? -$minor : $minor;
    }

    public static function decimal(int $minor): string
    {
        return ($minor < 0 ? '-' : '').intdiv(abs($minor), 100).'.'.str_pad((string) (abs($minor) % 100), 2, '0', STR_PAD_LEFT);
    }

    /** @return list<string> */
    public static function rules(): array
    {
        return ['required', 'string', 'regex:/^\d{1,10}(?:\.\d{1,2})?$/D', 'numeric', 'min:0.01', 'max:9999999999.99'];
    }
}
