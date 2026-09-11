<?php

namespace App\Api\v1\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

class GroupStoreRequest extends FormRequest
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
        // Bound group on update (null on store) so the unique rule ignores self
        $groupId = $this->route('group')?->id;

        return [
            'name' => [
                'required',
                'regex:/^[A-zÀ-ú0-9\s\-_\']+$/',
                'max:32',
                Rule::notIn([__('label.all')]),
                Rule::unique('groups')->where(function ($query) use ($groupId) {
                    $query->where('user_id', $this->user()->id);

                    if ($groupId) {
                        $query->where('id', '<>', $groupId);
                    }
                }),
            ],
            'show_in_chips' => [
                'sometimes',
                'required',
                'boolean',
            ],
        ];
    }

    /**
     * Get the error messages for the defined validation rules.
     *
     * @return array<string, string>
     */
    public function messages() : array
    {
        return [
            'name.not_in' => __('error.reserved_name_please_choose_something_else'),
            'name.regex'  => __('error.accepted_char_for_group_name'),
        ];
    }
}
