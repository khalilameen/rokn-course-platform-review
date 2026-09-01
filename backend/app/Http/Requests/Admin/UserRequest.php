<?php

namespace App\Http\Requests\Admin;

use App\Support\UnicodeText;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UserRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        if ($this->input('name') !== null) {
            $this->merge(['name' => UnicodeText::clean($this->input('name'), false)]);
        }
        if ($this->input('phone') !== null) {
            $this->merge(['phone' => UnicodeText::identifier($this->input('phone'))]);
        }
    }

    /**
     * Determine if the user is authorized to make this request.
     *
     * @return bool
     */
    public function authorize()
    {
        return strtolower((string) $this->user()?->role) === 'admin';
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array
     */
    public function rules()
    {
        $routeUser = $this->route('user');
        $userId = is_object($routeUser) ? $routeUser->getKey() : $routeUser;

        return [
            'name' => ['required', 'string', 'min:2', 'max:255'],
            'phone' => ['required', 'string', 'max:30', Rule::unique('users', 'phone')->ignore($userId)],
            'email' => ['required', 'email:rfc', 'max:255', Rule::unique('users', 'email')->ignore($userId)],
            'password' => [$this->isMethod('post') ? 'required' : 'nullable', 'string', 'min:10', 'max:72'],
            'image' => ['nullable', 'image', 'mimes:jpeg,png,jpg,webp', 'max:4096'],
        ];
    }

    /**
     * Get custom messages for validator errors.
     *
     * @return array
     */
    public function messages()
    {
        return [];
    }
}
