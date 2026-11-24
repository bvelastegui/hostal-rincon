<?php

namespace App\Http\Requests;

use App\Actions\ConvertUsdToCents;

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
        return [
            'request_id' => ['required', 'uuid'],
            'kind' => ['required', 'in:income,expense,deposit'],
            'payment_method' => ['exclude_if:kind,deposit', 'required', 'in:cash,transfer'],
            'amount' => ['required', 'numeric', 'decimal:0,2', 'gt:0', 'max:999999999.99'],
            'description' => ['exclude_if:kind,deposit', 'required', 'string', 'max:255'],
            'origin_bank_id' => ['exclude_unless:kind,income', 'exclude_unless:payment_method,transfer', 'required', 'integer', 'exists:banks,id'],
            'bank_account_id' => ['exclude_if:kind,deposit', 'exclude_unless:payment_method,transfer', 'required', 'integer', 'exists:bank_accounts,id'],
            'voucher' => ['exclude_if:kind,deposit', 'exclude_unless:payment_method,transfer', ...$this->voucherRules()],
            'responsible_id' => ['exclude_unless:kind,deposit', 'required', 'integer', 'exists:users,id'],
            'confirm' => ['exclude_unless:kind,income', 'exclude_unless:payment_method,transfer', 'sometimes', 'boolean'],
        ];
    }

    public function amountCents(): int
    {
        return ConvertUsdToCents::fromDecimal((string) $this->validated('amount'));
    }
}
