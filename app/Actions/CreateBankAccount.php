<?php

namespace App\Actions;

use App\Models\Bank;
use App\Models\BankAccount;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CreateBankAccount
{
    public const string DUPLICATE_NAME = 'Ya existe una cuenta con este nombre en el banco seleccionado.';

    public function __invoke(Bank $bank, string $name, int $balanceCents, string $nameField = 'name'): void
    {
        $name = Str::squish($name);
        $account = BankAccount::query()->createOrFirst([
            'bank_id' => $bank->id,
            'name_key' => Str::lower($name),
        ], [
            'name' => $name,
            'opening_balance_cents' => $balanceCents,
        ]);

        if (! $account->wasRecentlyCreated) {
            throw ValidationException::withMessages([$nameField => self::DUPLICATE_NAME]);
        }
    }
}
