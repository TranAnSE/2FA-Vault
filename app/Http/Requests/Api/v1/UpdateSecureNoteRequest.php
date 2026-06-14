<?php

namespace App\Http\Requests\Api\v1;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;

class UpdateSecureNoteRequest extends FormRequest
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
     * Get the validation rules that apply to the request.
     *
     * @return array
     */
    public function rules()
    {
        return [
            'title'       => 'sometimes|nullable|string|max:255',
            'content'     => 'sometimes|nullable|string',
            'content_type' => 'sometimes|nullable|string|in:plain,markdown',
            'is_pinned'   => 'sometimes|nullable|boolean',
        ];
    }
}
