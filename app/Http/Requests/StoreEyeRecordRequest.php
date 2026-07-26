<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreEyeRecordRequest extends FormRequest
{
    /**
     * Accepted visual-acuity notations.
     *
     * Deliberately NOT a closed list of "standard" values. Three notations are
     * in real use — metric (6/6), Snellen (20/20) and decimal (1.0) — plus the
     * low-vision shorthand CF (counting fingers), HM (hand motion), PL
     * (perception of light) and NPL, and modifier suffixes such as "6/9+",
     * meaning slightly better than 6/9.
     *
     * A dropdown of standard values was considered and rejected against the
     * real data: this store's own records hold 6/4, 6/5, 6/8, 6/10 and 6/9+ —
     * 371 readings that no standard list contains and a dropdown would have made
     * unenterable.
     */
    public const VA_PATTERN = '/^(?:(?:6|20)\/\d{1,3}(?:\.\d)?[+-]?\d?|0?\.\d{1,2}|[12](?:\.\d{1,2})?|CF|HM|PL|NPL)$/i';

    public function authorize(): bool
    {
        return true;
    }

    /**
     * An axis of 0 is not a reading: an axis runs 1..180, and 0 is indistinct
     * from 180. Treat it as "not recorded" so a legacy row carrying it can still
     * be opened and saved.
     */
    protected function prepareForValidation(): void
    {
        $normalised = [];

        foreach (['od', 'os'] as $eye) {
            $axis = $this->input("{$eye}_axis");
            if ($axis !== null && $axis !== '' && (int) $axis === 0) {
                $normalised["{$eye}_axis"] = null;
            }
        }

        if ($normalised !== []) {
            $this->merge($normalised);
        }
    }

    public function rules(): array
    {
        $rules = [
            'pd' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'checked_by' => ['nullable', 'string', 'max:255'],
        ];

        foreach (['od', 'os'] as $eye) {
            // Clinically realistic bounds that also stay within the decimal(5,2)/(6,2)
            // columns, so an out-of-range value is a 422, never a DB-overflow 500.
            $rules["{$eye}_sph"] = ['nullable', 'numeric', 'between:-30,30'];
            $rules["{$eye}_cyl"] = ['nullable', 'numeric', 'between:-15,15'];
            // An axis is an orientation from 1 to 180 degrees. 0 is not a
            // distinct angle (it is 180), so it is treated as "not recorded"
            // and normalised to null in prepareForValidation().
            $rules["{$eye}_axis"] = ['nullable', 'integer', 'min:1', 'max:180'];
            $rules["{$eye}_add"] = ['nullable', 'numeric', 'between:0,6'];
            // Visual acuity is not a closed list — metric (6/6), Snellen
            // (20/20), decimal (1.0) and the low-vision notations all appear,
            // as do suffixes like "6/9+". See the pattern's own comment.
            $rules["{$eye}_va"] = ['nullable', 'string', 'max:20', 'regex:' . self::VA_PATTERN];
            $rules["{$eye}_nv"] = ['nullable', 'numeric', 'between:-50,50'];
        }

        return $rules;
    }

    /** Reject a fully blank record — at least one measurement (or PD) is required. */
    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $fields = ['pd'];
            foreach (['od', 'os'] as $eye) {
                foreach (['sph', 'cyl', 'axis', 'add', 'nv'] as $f) {
                    $fields[] = "{$eye}_{$f}";
                }
            }

            $hasAny = collect($fields)->contains(fn ($field) => $this->filled($field));

            if (! $hasAny) {
                $validator->errors()->add('od_sph', 'Enter at least one measurement before saving.');
            }

            // Cylinder and axis are a pair, always. A cylinder power says HOW
            // MUCH astigmatism there is; the axis says WHICH WAY it lies. One
            // without the other cannot be made into a lens.
            //
            // 23 legacy rows already break this, so the messages name the fix
            // rather than only the fault — someone opening an old record to add
            // a note has to be able to see what to do.
            foreach (['od' => 'Right', 'os' => 'Left'] as $eye => $label) {
                $cyl = $this->input("{$eye}_cyl");
                $axis = $this->input("{$eye}_axis");

                $hasCyl = $cyl !== null && $cyl !== '' && (float) $cyl != 0.0;
                $hasAxis = $axis !== null && $axis !== '' && (int) $axis !== 0;

                if ($hasCyl && ! $hasAxis) {
                    $validator->errors()->add(
                        "{$eye}_axis",
                        "{$label} eye: a cylinder power needs an axis (1–180). Add the axis, or clear the cylinder if there is no astigmatism.",
                    );
                } elseif (! $hasCyl && $hasAxis) {
                    $validator->errors()->add(
                        "{$eye}_axis",
                        "{$label} eye: an axis has no meaning without a cylinder power. Add the cylinder, or clear the axis.",
                    );
                }
            }
        });
    }

    public function messages(): array
    {
        return [
            'od_va.regex' => 'Right eye V/S: use 6/6, 20/20, 1.0, or CF / HM / PL / NPL.',
            'os_va.regex' => 'Left eye V/S: use 6/6, 20/20, 1.0, or CF / HM / PL / NPL.',
            'od_axis.min' => 'Right eye axis runs from 1 to 180 degrees.',
            'os_axis.min' => 'Left eye axis runs from 1 to 180 degrees.',
        ];
    }
}
