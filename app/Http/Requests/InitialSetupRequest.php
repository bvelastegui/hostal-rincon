<?php

namespace App\Http\Requests;

use App\Actions\ConvertUsdToCents;
use App\Concerns\PasswordValidationRules;
use App\Concerns\ProfileValidationRules;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\Rules\Unique;

class InitialSetupRequest extends FormRequest
{
    use PasswordValidationRules;
    use ProfileValidationRules;

    protected function prepareForValidation(): void
    {
        $this->normalizeUsername($this);
        $this->merge(['opening_balance' => ConvertUsdToCents::normalize($this->input('opening_balance'))]);

        $accounts = $this->input('accounts');
        if (! is_array($accounts)) {
            return;
        }

        foreach ($accounts as $index => $account) {
            if (is_array($account) && array_key_exists('opening_balance', $account)) {
                $accounts[$index]['opening_balance'] = ConvertUsdToCents::normalize($account['opening_balance']);
            }
        }

        $this->merge(['accounts' => $accounts]);
    }

    /** @return array<string, list<string|Password|Unique>> */
    public function rules(): array
    {
        return [
            'opening_balance' => ['required', ...ConvertUsdToCents::OPENING_BALANCE_RULES],
            'name' => ['required', 'string', 'max:255'],
            'username' => $this->usernameRules(),
            'password' => $this->passwordRules(),
            'accounts' => ['sometimes', 'array', 'list', 'max:20'],
            'accounts.*' => ['required', 'array:bank,name,opening_balance'],
            'accounts.*.bank' => ['required', 'string', 'max:100'],
            'accounts.*.name' => ['required', 'string', 'max:100'],
            'accounts.*.opening_balance' => ['required', ...ConvertUsdToCents::OPENING_BALANCE_RULES],
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
            'accounts.*.bank' => 'banco',
            'accounts.*.name' => 'alias de la cuenta',
            'accounts.*.opening_balance' => 'saldo inicial de la cuenta',
        ];
    }
}
