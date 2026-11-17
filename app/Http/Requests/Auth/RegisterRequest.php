<?php
// app/Http/Requests/Auth/RegisterRequest.php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RegisterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'first_name' => 'required|string|min:2|max:100',
            'last_name' => 'required|string|min:2|max:100',
            'phone_code' => 'required|string|max:5|starts_with:+',
            'phone_number' => [
                'required',
                'string',
                'min:6',
                'max:15',
                'regex:/^[1-9][0-9]+$/',
                Rule::unique('users')->where(function ($query) {
                    return $query->where('phone_code', $this->phone_code);
                }),
            ],
            'email' => 'nullable|email|max:255|unique:users,email',
            'password' => 'required|string|min:6|confirmed',
            'birth_date' => 'required|date|before:-13 years',
            'gender' => 'required|in:male,female',
            'preferences' => 'nullable|array',
            'preferences.*' => 'exists:preferences,id',
            'verification_code' => 'required|string|size:6', // ⭐ AJOUTÉ
        ];
    }

    public function messages(): array
    {
        return [
            'phone_code.starts_with' => __('L\'indicatif doit commencer par +.'),
            'phone_number.regex' => 'Le numéro ne doit pas commencer par 0.',
            'phone_number.unique' => 'Ce numéro a déjà un compte. Connecte-toi.',
            'birth_date.before' => __('Il faut avoir au moins 13 ans.'),
            'gender.required' => __('Choisis : une femme ou un homme.'),
            'gender.in' => __('Choisis : une femme ou un homme.'),
            'verification_code.required' => 'Entre le code reçu par SMS.',
            'verification_code.size' => __('Le code a 6 chiffres.'),
        ];
    }
}