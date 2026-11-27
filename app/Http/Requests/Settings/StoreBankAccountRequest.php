<?php

namespace App\Http\Requests\Settings;

use App\Actions\CreateBankAccount;
use App\Models\Bank;
use App\Models\BankAccount;
use Illuminate\Support\Str;
use Illuminate\Validation\Validator;

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

    protected function review(Validator $validator): void
    {
        $bank = Bank::query()->findOrFail($this->integer('bank_id'));
        if (! $bank->is_active) {
            $validator->errors()->add('bank_id', 'Selecciona un banco activo.');

            return;
        }

        $key = Str::lower(Str::squish($this->string('name')->toString()));
        if (BankAccount::query()->where('bank_id', $bank->id)->where('name_key', $key)->exists()) {
            $validator->errors()->add('name', CreateBankAccount::DUPLICATE_NAME);
        }
    }
}
