<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

class RegisterRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name'     => 'required|string|max:255',
            'email'    => 'required|string|email|max:255',
            'password' => [
                'required',
                Password::min(8)
                    ->letters()
                    ->mixedCase()
                    ->numbers()
                    ->symbols(),
            ],
        ];
    }
    public function messages(): array
    {
        return [
            'name.required'      => 'Tên là bắt buộc.',
            'email.required'     => 'Email là bắt buộc.',
            'email.email'        => 'Email không hợp lệ.',
            'password.required'  => 'Mật khẩu là bắt buộc.',
            'password.min'       => 'Mật khẩu phải có ít nhất 8 ký tự.',
            'password.letters'   => 'Mật khẩu phải chứa ít nhất một chữ cái.',
            'password.mixed'     => 'Mật khẩu phải chứa cả chữ thường và chữ hoa.',
            'password.numbers'   => 'Mật khẩu phải chứa ít nhất một chữ số.',
            'password.symbols'   => 'Mật khẩu phải chứa ít nhất một ký tự đặc biệt.',
        ];
    }
}
