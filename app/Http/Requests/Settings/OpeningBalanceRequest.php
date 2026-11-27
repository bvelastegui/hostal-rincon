<?php

namespace App\Http\Requests\Settings;

use App\Actions\ConvertUsdToCents;
use App\Http\Requests\ReviewableRequest;
use App\Models\CashOpening;
use Illuminate\Validation\Validator;

class OpeningBalanceRequest extends ReviewableRequest
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

    protected function review(Validator $validator): void
    {
        if (CashOpening::query()->sole()->opening_locked_at !== null) {
            $validator->errors()->add('opening_balance', 'El saldo inicial ya se usó en un movimiento.');
        }
    }

    public function balanceCents(): int
    {
        return ConvertUsdToCents::fromDecimal((string) $this->validated('opening_balance'));
    }
}
