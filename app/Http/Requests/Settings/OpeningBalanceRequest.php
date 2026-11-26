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
            $this->merge(['opening_balance' => ConvertUsdToCents::normalize($balance)]);
        }
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'opening_balance' => ['required', ...ConvertUsdToCents::OPENING_BALANCE_RULES],
        ];
    }

    public function balanceCents(): int
    {
        return ConvertUsdToCents::fromDecimal((string) $this->validated('opening_balance'));
    }
}
