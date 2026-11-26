<?php

namespace App\Http\Requests;

use App\Actions\ConvertUsdToCents;
use App\Concerns\PasswordValidationRules;
use App\Concerns\ProfileValidationRules;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
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
        if (is_array($accounts)) {
            foreach ($accounts as &$account) {
                if (is_array($account) && array_key_exists('opening_balance', $account)) {
                    $account['opening_balance'] = ConvertUsdToCents::normalize($account['opening_balance']);
                }
            }
            $this->merge(['accounts' => $accounts]);
        }

        $employees = $this->input('employees');
        if (is_array($employees)) {
            foreach ($employees as &$employee) {
                if (! is_array($employee)) {
                    continue;
                }

                if (is_string($employee['username'] ?? null)) {
                    $employee['username'] = Str::lower($employee['username']);
                }
                if (array_key_exists('advance_limit', $employee)) {
                    $employee['advance_limit'] = ConvertUsdToCents::normalize($employee['advance_limit']);
                }
            }
            $this->merge(['employees' => $employees]);
        }
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
            'employees' => ['sometimes', 'array', 'list', 'max:20'],
            'employees.*' => ['required', 'array:name,username,password,password_confirmation,advance_limit'],
            'employees.*.name' => ['required', 'string', 'max:255'],
            'employees.*.username' => [...$this->usernameRules(), 'distinct:strict', 'different:username'],
            'employees.*.password' => ['required', 'string', 'min:8', 'confirmed'],
            'employees.*.advance_limit' => ['sometimes', 'required', ...ConvertUsdToCents::OPENING_BALANCE_RULES],
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
            'accounts.*.bank' => 'banco',
            'accounts.*.name' => 'alias de la cuenta',
            'accounts.*.opening_balance' => 'saldo inicial de la cuenta',
            'employees.*.name' => 'nombre del empleado',
            'employees.*.username' => 'nombre de usuario del empleado',
            'employees.*.password' => 'contraseña del empleado',
            'employees.*.advance_limit' => 'límite de adelantos del empleado',
        ];
    }
}
