<?php

namespace App\Http\Requests;

use App\Actions\ConvertUsdToCents;
use Illuminate\Foundation\Http\FormRequest;

class StoreCashClosingRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $amount = $this->input('counted_cash');

        if (is_string($amount)) {
            $this->merge(['counted_cash' => str_replace(',', '.', $amount)]);
        }
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'request_id' => ['required', 'uuid'],
            'cash_shift_id' => ['nullable', 'integer', 'min:1'],
            'expected_cash_cents' => ['required', 'integer'],
            'counted_cash' => ['required', 'numeric', 'decimal:0,2', 'min:0', 'max:999999999.99'],
            'note' => ['nullable', 'string', 'max:500'],
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return ['counted_cash' => 'efectivo contado', 'note' => 'observación'];
    }

    public function countedCashCents(): int
    {
        return ConvertUsdToCents::fromDecimal((string) $this->validated('counted_cash'));
    }
}
