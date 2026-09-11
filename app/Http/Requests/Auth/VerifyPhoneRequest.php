<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

class VerifyPhoneRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'verification_code' => 'required|string|size:6',
        ];
    }

    public function messages(): array
    {
        return [
            'verification_code.size' => 'Le code doit contenir exactement 6 chiffres',
        ];
    }
}