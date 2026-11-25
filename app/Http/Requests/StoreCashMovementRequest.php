<?php

namespace App\Http\Requests;

use App\Actions\ConvertUsdToCents;
use App\Models\CashMovement;
use Illuminate\Support\Facades\Gate;

class StoreCashMovementRequest extends ConfirmCashMovementRequest
{
    protected function prepareForValidation(): void
    {
        parent::prepareForValidation();

        $amount = $this->input('amount');

        if (is_string($amount)) {
            $this->merge(['amount' => str_replace(',', '.', $amount)]);
        }
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        $original = $this->route('movement');
        $confirmedDeposit = $original instanceof CashMovement && $original->kind === 'deposit' && $original->confirmed_at !== null;

        $rules = [
            'request_id' => ['required', 'uuid'],
            'kind' => ['required', 'in:income,expense,deposit'],
            'is_sensitive' => Gate::allows('manage-movement-sensitivity') ? ['sometimes', 'boolean'] : ['prohibited'],
            'payment_method' => ['exclude_if:kind,deposit', 'required', 'in:cash,transfer'],
            'amount' => ['required', 'numeric', 'decimal:0,2', 'gt:0', 'max:999999999.99'],
            'description' => ['exclude_if:kind,deposit', 'required', 'string', 'max:255'],
            'origin_bank_id' => ['exclude_unless:kind,income', 'exclude_unless:payment_method,transfer', 'required', 'integer', 'exists:banks,id'],
            'bank_account_id' => ['exclude_if:kind,deposit', 'exclude_unless:payment_method,transfer', 'required', 'integer', 'exists:bank_accounts,id'],
            'voucher' => ['exclude_if:kind,deposit', 'exclude_unless:payment_method,transfer', ...$this->voucherRules()],
            'responsible_id' => ['exclude_unless:kind,deposit', 'required', 'integer', 'exists:users,id'],
            'confirm' => ['exclude_unless:kind,income', 'exclude_unless:payment_method,transfer', 'sometimes', 'boolean'],
        ];

        if ($original instanceof CashMovement) {
            $rules['kind'] = ['required', 'in:'.$original->kind];
            $rules['void_reason'] = ['required', 'string', 'max:255'];
        }

        if ($confirmedDeposit) {
            $rules['bank_account_id'] = ['required', 'integer', 'exists:bank_accounts,id'];
            $rules['voucher'] = ['required', 'string', 'max:100'];
        }

        return $rules;
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return ['void_reason' => 'motivo de anulación'];
    }

    public function amountCents(): int
    {
        return ConvertUsdToCents::fromDecimal((string) $this->validated('amount'));
    }
}
