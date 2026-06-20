<?php

namespace App\Api\v1\Requests;

use Illuminate\Foundation\Http\FormRequest;

class BreachCheckEmailRequest extends FormRequest
{
    public function authorize() : bool
    {
        return (bool) $this->user();
    }

    /**
     * @return array<string, mixed>
     */
    public function rules() : array
    {
        return [
            // 'me' uses the authenticated user's own email; otherwise a raw address
            'email'             => ['required', 'string', 'email:rfc'],
            'use_account_email' => ['sometimes', 'boolean'],
        ];
    }
}
