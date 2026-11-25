<?php

namespace App\Http\Requests;

use App\Actions\ConvertUsdToCents;
use App\Models\Advance;
use Illuminate\Support\Facades\Gate;

class StoreAdvanceRequest extends ConfirmCashMovementRequest
{
    public function authorize(): bool
    {
        return Gate::allows('manage-advances');
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'request_id' => ['required', 'uuid'],
            'amount' => ['required', 'numeric', 'decimal:0,2', 'gt:0', 'max:'.(Advance::LIMIT_CENTS / 100)],
            'note' => ['nullable', 'string', 'max:500'],
            'payment_method' => ['required', 'in:cash,transfer'],
            'bank_account_id' => ['exclude_unless:payment_method,transfer', 'required', 'integer', 'exists:bank_accounts,id'],
            'voucher' => ['exclude_unless:payment_method,transfer', 'required', ...$this->voucherRules()],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'amount.required' => 'Ingresa el monto del adelanto.',
            'amount.decimal' => 'Ingresa un monto con hasta dos decimales.',
            'amount.gt' => 'El monto debe ser mayor que cero.',
            'amount.max' => 'El monto supera el límite de adelantos.',
            'payment_method.required' => 'Selecciona la forma de pago del adelanto.',
            'payment_method.in' => 'Selecciona efectivo o transferencia bancaria.',
            'bank_account_id.required' => 'Selecciona la cuenta bancaria de origen.',
            'bank_account_id.exists' => 'Selecciona una cuenta bancaria registrada.',
            'voucher.required' => 'Ingresa el número de voucher de la transferencia.',
        ];
    }

    public function amountCents(): int
    {
        return ConvertUsdToCents::fromDecimal((string) $this->validated('amount'));
    }
}
