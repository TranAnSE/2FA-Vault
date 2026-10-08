<?php

namespace App\Api\v1\Requests;

use App\Rules\IsValidEmailList;
use App\Rules\IsValidRegex;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;

class SettingUpdateRequest extends FormRequest
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
        $routeParam = $this->route()?->parameter('settingName')
            // The user preference route names its parameter preferenceName
            // (UserController::setPreference) — per-key rules below key on it.
            ?? $this->route()?->parameter('preferenceName');

        if ($routeParam == 'restrictList') {
            $rule = [
                'value' => [
                    new IsValidEmailList,
                ],
            ];
        } elseif ($routeParam == 'restrictRule') {
            $rule = [
                'value' => [
                    new IsValidRegex,
                ],
            ];
        } elseif ($routeParam == 'snapshot_frequency') {
            // Scheduled snapshot schedule (v1.4.0).
            $rule = [
                'value' => [
                    'required',
                    'in:off,daily,weekly',
                ],
            ];
        } elseif ($routeParam == 'snapshot_time') {
            $rule = [
                'value' => [
                    'required',
                    'date_format:H:i',
                ],
            ];
        } elseif ($routeParam == 'auto_backup_enabled') {
            $rule = [
                'value' => [
                    'required',
                    'boolean',
                ],
            ];
        } elseif ($routeParam == 'auto_backup_frequency') {
            $rule = [
                'value' => [
                    'required',
                    'in:daily,weekly,monthly',
                ],
            ];
        } elseif ($routeParam == 'auto_backup_time') {
            $rule = [
                'value' => [
                    'required',
                    'date_format:H:i',
                ],
            ];
        } else {
            $rule = [
                'value' => [
                    'required',
                ],
            ];
        }

        return $rule;
    }
}
