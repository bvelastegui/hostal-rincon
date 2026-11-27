<?php

namespace App\Http\Requests\Settings;

use App\Actions\ConvertUsdToCents;
use App\Actions\CreateBankAccount;
use App\Models\BankAccount;
use Illuminate\Support\Str;
use Illuminate\Validation\Validator;

class UpdateBankAccountRequest extends OpeningBalanceRequest
{
    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'name' => ['required_without:opening_balance', 'filled', 'string', 'max:100'],
            'opening_balance' => ['required_without:name', 'filled', ...ConvertUsdToCents::OPENING_BALANCE_RULES],
        ];
    }

    protected function review(Validator $validator): void
    {
        $account = $this->route('account');
        if ($this->filled('opening_balance') && $account->opening_locked_at !== null
            && $this->balanceCents() !== $account->opening_balance_cents) {
            $validator->errors()->add('opening_balance', 'El saldo inicial ya se usó en un movimiento.');
        }

        if ($this->filled('name')) {
            $key = Str::lower(Str::squish($this->string('name')->toString()));
            if (BankAccount::query()->where('bank_id', $account->bank_id)->where('name_key', $key)->whereKeyNot($account->id)->exists()) {
                $validator->errors()->add('name', CreateBankAccount::DUPLICATE_NAME);
            }
        }
    }
}
