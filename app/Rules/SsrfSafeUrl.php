<?php

namespace App\Rules;

use App\Services\UrlValidator;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * SSRF-Safe URL Validation Rule
 *
 * Laravel validation rule that wraps the UrlValidator service
 * for use in form validation. Ensures that user-provided URLs
 * cannot be used for Server-Side Request Forgery attacks.
 */
class SsrfSafeUrl implements ValidationRule
{
    /**
     * Run the validation rule.
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (empty($value)) {
            return; // nullable — let 'required' handle presence
        }

        $validator = new UrlValidator();
        $result = $validator->validate($value);

        if (!$result['valid']) {
            $fail($result['error'] ?? 'The URL failed security validation.');
        }
    }
}
