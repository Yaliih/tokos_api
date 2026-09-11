<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

class LoginRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'phone_code' => 'required|string|max:5',
            'phone_number' => 'required|string|max:15',
            'password' => 'required|string',
            'device_name' => 'nullable|string|max:100',
        ];
    }
}