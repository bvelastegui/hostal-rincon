<?php

namespace App\Http\Requests;

use App\Models\CashMovement;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

class ConfirmCashMovementRequest extends FormRequest
{
    public function authorize(): bool
    {
        $movement = $this->route('movement');
        $kind = $movement instanceof CashMovement ? $movement->kind : $this->input('kind');

        return $kind !== 'deposit' || Gate::allows('manage-deposits');
    }

    protected function prepareForValidation(): void
    {
        $voucher = $this->input('voucher');

        if (is_string($voucher)) {
            $this->merge(['voucher' => Str::upper(trim($voucher))]);
        }
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        $movement = $this->route('movement');
        $isDeposit = $movement instanceof CashMovement && $movement->kind === 'deposit';

        return [
            'voucher' => $this->voucherRules(),
            'bank_account_id' => [$isDeposit ? 'required' : 'exclude', 'integer', 'exists:bank_accounts,id'],
        ];
    }

    /** @return list<string> */
    protected function voucherRules(): array
    {
        return ['nullable', 'string', 'max:100'];
    }
}
