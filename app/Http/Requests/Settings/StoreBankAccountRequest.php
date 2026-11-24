<?php

namespace App\Http\Requests\Settings;

class StoreBankAccountRequest extends OpeningBalanceRequest
{
    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            ...parent::rules(),
            'bank_id' => ['required', 'integer', 'exists:banks,id'],
            'name' => ['required', 'string', 'max:100'],
        ];
    }
}
