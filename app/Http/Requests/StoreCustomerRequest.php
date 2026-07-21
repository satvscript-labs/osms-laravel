<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCustomerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $customerId = $this->route('customer')?->id;

        return [
            'name' => ['required', 'string', 'max:255'],
            'phone' => [
                // Normalised to "{code} {national}" in prepareForValidation, e.g. "+91 9876543210".
                // National part is exactly 10 digits (Indian mobile standard).
                'required', 'string', 'max:30', 'regex:/^\+\d{1,4}\s\d{10}$/',
                // Unique per tenant + phone (matches the Supabase constraint).
                Rule::unique('customers')
                    ->where('tenant_id', $this->user()->tenant_id)
                    ->ignore($customerId),
            ],
            'age' => ['nullable', 'integer', 'min:0', 'max:150'],
            'birthday' => ['nullable', 'date', 'before:today', 'after:1900-01-01'],
            'gender' => ['nullable', Rule::in(['male', 'female', 'other'])],
            // PRIV-01 — data consent is REQUIRED (must be ticked); WhatsApp opt-in is optional.
            'data_consent' => ['accepted'],
            'whatsapp_opt_in' => ['nullable', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'phone.unique' => 'A customer with this phone number already exists in your store.',
            'phone.regex' => 'Enter a valid 10-digit phone number.',
            'data_consent.accepted' => 'Please record the customer\'s consent to continue.',
        ];
    }

    protected function prepareForValidation(): void
    {
        // Combine the country-code selector with the national number into one stored value.
        // Default to +91 (India) and strip any separators the user typed in the number.
        $code = trim((string) ($this->country_code ?: '+91'));
        $national = preg_replace('/\D/', '', (string) $this->phone);

        $this->merge([
            'name' => trim((string) $this->name),
            'phone' => $national !== '' ? $code . ' ' . $national : '',
            'gender' => $this->gender ?: null,
        ]);
    }
}
