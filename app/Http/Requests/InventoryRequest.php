<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class InventoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'item_type' => ['required', Rule::in(['frame', 'lens', 'contact_lens', 'accessory'])],
            'brand' => ['nullable', 'string', 'max:255'],
            'model_name' => ['nullable', 'string', 'max:255'],
            // Upper bounds keep values within the decimal(10,2) / unsigned-int columns
            // (an out-of-range entry is a 422, never a DB-overflow 500).
            'cost_price' => ['required', 'numeric', 'min:0', 'max:99999999'],
            'selling_price' => ['required', 'numeric', 'min:0', 'max:99999999'],
            'stock_qty' => ['nullable', 'integer', 'min:0', 'max:1000000'],
            'min_alert_qty' => ['nullable', 'integer', 'min:0', 'max:1000000'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'brand' => trim((string) $this->brand) ?: null,
            'model_name' => trim((string) $this->model_name) ?: null,
            'stock_qty' => $this->stock_qty ?? 0,
            'min_alert_qty' => $this->min_alert_qty ?? 5,
        ]);
    }
}
