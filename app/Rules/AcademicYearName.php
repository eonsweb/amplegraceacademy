<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Translation\PotentiallyTranslatedString;

class AcademicYearName implements ValidationRule
{
    /**
     * Run the validation rule.
     *
     * @param  Closure(string, ?string=): PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || preg_match('/\A(\d{4})\/(\d{4})\z/', $value, $matches) !== 1) {
            $fail('The :attribute must use the YYYY/YYYY format.');

            return;
        }

        if ((int) $matches[2] !== (int) $matches[1] + 1) {
            $fail('The second year must be exactly one year after the first year.');
        }
    }
}
