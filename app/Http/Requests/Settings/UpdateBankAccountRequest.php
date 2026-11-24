<?php

namespace App\Http\Requests\Settings;

class UpdateBankAccountRequest extends OpeningBalanceRequest
{
    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'name' => ['required_without:opening_balance', 'filled', 'string', 'max:100'],
            'opening_balance' => ['required_without:name', 'filled', ...$this->openingBalanceRules()],
        ];
    }
}
