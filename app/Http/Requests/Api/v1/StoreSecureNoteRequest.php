<?php

namespace App\Http\Requests\Api\v1;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;

class StoreSecureNoteRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     *
     * @return bool
     */
    public function authorize()
    {
        return Auth::check();
    }

    /**
     * Prepare the data for validation.
     */
    protected function prepareForValidation(): void
    {
        if (! $this->has('content_type') || $this->input('content_type') === null) {
            $this->merge(['content_type' => 'plain']);
        }
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array
     */
    public function rules()
    {
        return [
            'title'       => 'required|string|max:255',
            'content'     => 'required|string',
            'content_type' => 'sometimes|nullable|string|in:plain,markdown',
            'is_pinned'   => 'sometimes|nullable|boolean',
        ];
    }
}
