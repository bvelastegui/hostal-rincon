<?php

namespace App\Http\Requests\Settings;

use App\Actions\ConvertUsdToCents;
use Illuminate\Foundation\Http\FormRequest;

class OpeningBalanceRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $balance = $this->input('opening_balance');

        if (is_string($balance)) {
            $this->merge(['opening_balance' => str_replace(',', '.', $balance)]);
        }
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'opening_balance' => ['required', ...$this->openingBalanceRules()],
        ];
    }

    /** @return list<string> */
    protected function openingBalanceRules(): array
    {
        return ['numeric', 'decimal:0,2', 'min:0', 'max:999999999.99'];
    }

    public function balanceCents(): int
    {
        return ConvertUsdToCents::fromDecimal((string) $this->validated('opening_balance'));
    }
}
