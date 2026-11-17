<?php
// app/Http/Requests/Auth/SendVerificationCodeRequest.php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SendVerificationCodeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'phone_code' => 'required|string|max:5|starts_with:+',
            'phone_number' => [
                'required',
                'string',
                'max:15',
                'regex:/^[1-9][0-9]+$/',
                // ⭐ Vérifier l'unicité du numéro
                Rule::unique('users')->where(function ($query) {
                    return $query->where('phone_code', $this->phone_code);
                }),
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'phone_code.starts_with' => __('The country code must start with +'),
            'phone_number.regex' => __('The phone number must not start with 0'),
            'phone_number.unique' => __('This phone number is already registered. Please login instead.'),
        ];
    }
}