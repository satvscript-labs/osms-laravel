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
        return [
            'name' => ['required', 'string', 'max:255'],
            'phone' => [
                // SHARE-01 — nullable: a walk-in who won't give a number is still a
                // customer, and requiring one is what forced 639 fake `+91 0…`
                // numbers into the Sahaj migration.
                //
                // NOT unique. A number identifies a household handset, not a
                // person; families legitimately share one. Duplicate handling
                // lives in the controller, where a human can judge it.
                'nullable', 'string', 'max:30', 'regex:/^\+\d{1,4}\s\d{10}$/',
            ],
            'age' => ['nullable', 'integer', 'min:0', 'max:150'],
            'birthday' => ['nullable', 'date', 'before:today', 'after:1900-01-01'],
            'gender' => ['nullable', Rule::in(['male', 'female', 'other'])],
            // PRIV-01 — data consent is REQUIRED (must be ticked); WhatsApp opt-in is optional.
            'data_consent' => ['accepted'],
            'whatsapp_opt_in' => ['nullable', 'boolean'],
            // SHARE-01 / PRIV — opting a customer into WhatsApp on a number a
            // relative also holds means order updates about them land on a handset
            // someone else may be holding. Consent is per person, so that has to
            // be a deliberate acknowledgement rather than a default.
            // Built conditionally rather than with requiredIf + accepted: `accepted`
            // is not skipped when the field is absent, so pairing the two would
            // reject every customer who simply isn't opting into WhatsApp.
            'whatsapp_shared_ack' => $this->boolean('whatsapp_opt_in') && $this->phoneIsShared()
                ? ['accepted']
                : ['nullable'],
        ];
    }

    /** Whether another customer in this store already holds this number. */
    private function phoneIsShared(): bool
    {
        $phone = $this->input('phone');

        if (! is_string($phone) || $phone === '') {
            return false;
        }

        return \App\Models\Customer::query()
            ->sharingPhone($phone)
            ->when($this->route('customer'), fn ($q, $c) => $q->whereKeyNot($c->id))
            ->exists();
    }

    public function messages(): array
    {
        return [
            'phone.regex' => 'Enter a valid 10-digit phone number.',
            'data_consent.accepted' => 'Please record the customer\'s consent to continue.',
            'whatsapp_shared_ack.required' => 'This number is shared with someone else. Confirm they\'re happy for updates about them to arrive on it.',
            'whatsapp_shared_ack.accepted' => 'This number is shared with someone else. Confirm they\'re happy for updates about them to arrive on it.',
        ];
    }

    protected function prepareForValidation(): void
    {
        // Combine the country-code selector with the national number into one stored value.
        // Default to +91 (India) and strip any separators the user typed in the number.
        $raw = (string) $this->phone;
        $code = trim((string) ($this->country_code ?: '+91'));
        $national = preg_replace('/\D/', '', $raw);

        $this->merge([
            'name' => trim((string) $this->name),
            // SHARE-01 — a genuinely absent number becomes NULL, never "": an empty
            // string is a value, and every numberless customer would then look like
            // one household sharing the number "". Input that contained something
            // unusable ("abc") is passed through UNCHANGED so the regex rejects it —
            // nulling it would silently discard a typo as "no number given".
            'phone' => match (true) {
                $national !== '' => $code . ' ' . $national,
                trim($raw) === '' => null,
                default => $raw,
            },
            'gender' => $this->gender ?: null,
        ]);
    }
}
