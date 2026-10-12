<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use App\Services\Security\RecaptchaVerifier;
use Illuminate\Validation\ValidationException;

class StoreContactMessageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100'],
            'phone' => ['required', 'string', 'max:30', 'regex:/^(?:\+62|62|0)8[0-9]{7,13}$/'],
            'email' => ['nullable', 'email', 'max:255'],
            'type' => ['required', 'string', 'in:Company Profile,Landing Page,E-Commerce,Sistem / Dashboard,Lainnya'],
            'message' => ['required', 'string'],
            'g-recaptcha-response' => [config('services.recaptcha.enabled') ? 'required' : 'nullable', 'string'],
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'Nama wajib diisi.',
            'phone.required' => 'Nomor WhatsApp wajib diisi.',
            'phone.regex' => 'Format nomor WhatsApp tidak valid.',
            'email.email' => 'Format email tidak valid.',
            'type.required' => 'Jenis website wajib dipilih.',
            'type.in' => 'Jenis website tidak valid.',
            'message.required' => 'Pesan wajib diisi.',
            'g-recaptcha-response.required' => 'Silakan centang reCAPTCHA terlebih dahulu.',
        ];
    }

    protected function passedValidation(): void
    {
        if (! app(RecaptchaVerifier::class)->verify($this->input('g-recaptcha-response'), $this->ip())) {
            throw ValidationException::withMessages([
                'g-recaptcha-response' => 'Verifikasi reCAPTCHA gagal. Silakan coba lagi.',
            ]);
        }
    }
}
