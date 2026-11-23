<?php

declare(strict_types=1);

namespace App\Shared\Validation;

use App\Shared\Time\BusinessDate;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Real-calendar validation for DATE inputs. Format-only rules such as
 * date_format:Y-m-d accept some impossible dates; this rule does not.
 */
final class BusinessDateRule implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || BusinessDate::tryFrom($value) === null) {
            $fail('The :attribute must be a real calendar date in YYYY-MM-DD format.');
        }
    }
}
