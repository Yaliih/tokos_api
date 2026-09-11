<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

class ResetPasswordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'phone_code' => 'required|string|max:5|starts_with:+',
            'phone_number' => 'required|string|max:15',
            'password' => 'required|string|min:6|confirmed',
            'verification_code' => 'required|string|size:6',
        ];
    }

    public function messages(): array
    {
        return [
            'phone_code.starts_with' => 'Country code must start with +',
            'verification_code.size' => 'Verification code must be exactly 6 digits',
            'password.min' => 'Password must be at least 6 characters',
            'password.confirmed' => 'Passwords do not match',
        ];
    }
}