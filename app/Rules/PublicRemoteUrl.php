<?php

namespace App\Rules;

use App\Services\Traits\ValidatesUrls;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Validates that a URL points to a public, non-internal target.
 *
 * Defends against Server-Side Request Forgery (SSRF) on user-supplied
 * destination URLs (e.g. webhook endpoints) by reusing the central
 * ValidatesUrls trait so the same DNS-rebinding / private-range / NAT64 /
 * IMDS guards that protect icon fetching also apply here.
 */
class PublicRemoteUrl implements ValidationRule
{
    use ValidatesUrls;

    /**
     * Run the validation rule.
     */
    public function validate(string $attribute, mixed $value, Closure $fail) : void
    {
        if (! is_string($value) || ! $this->isPublicRemoteUrl($value)) {
            $fail('validation.public_remote_url')->translate();
        }
    }
}
