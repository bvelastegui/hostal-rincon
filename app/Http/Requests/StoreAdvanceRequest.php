<?php

namespace App\Http\Requests;

use App\Actions\CalculateCashBalances;
use App\Actions\ConvertUsdToCents;
use App\Actions\CorrectCashMovement;
use App\Models\Advance;
use App\Models\CashShift;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Validator;

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
            'void_reason' => [$this->routeIs('advances.replace') ? 'required' : 'exclude', 'string', 'max:255'],
            'amount' => ['required', ...ConvertUsdToCents::OPENING_BALANCE_RULES, 'gt:0'],
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
            'void_reason.required' => 'Ingresa el motivo de la corrección.',
            'void_reason.max' => 'El motivo de la corrección admite hasta 255 caracteres.',
            'amount.required' => 'Ingresa el monto del adelanto.',
            'amount.decimal' => 'Ingresa un monto con hasta dos decimales.',
            'amount.gt' => 'El monto debe ser mayor que cero.',
            'amount.max' => 'El monto excede el máximo admitido.',
            'payment_method.required' => 'Selecciona la forma de pago del adelanto.',
            'payment_method.in' => 'Selecciona efectivo o transferencia bancaria.',
            'bank_account_id.required' => 'Selecciona la cuenta bancaria de origen.',
            'bank_account_id.exists' => 'Selecciona una cuenta bancaria registrada.',
            'voucher.required' => 'Ingresa el número de voucher de la transferencia.',
        ];
    }

    protected function review(Validator $validator): void
    {
        $advance = $this->route('advance');
        abort_unless($advance === null || $advance instanceof Advance, 404);

        $employee = $this->route('employee') ?? $advance?->employee;
        abort_unless($employee instanceof User && ! $employee->is_admin, 404);
        $original = $advance?->cashMovement;
        if ($advance !== null) {
            if ($advance->deducted_at !== null) {
                $validator->errors()->add('amount', 'El adelanto ya fue descontado.');

                return;
            }

            if ($original?->kind !== 'advance' || $original->confirmed_at === null) {
                $validator->errors()->add('amount', 'El adelanto no tiene un egreso confirmado que se pueda corregir.');

                return;
            }

            $shift = CashShift::active();
            $corrections = app(CorrectCashMovement::class);
            if ($blocked = $corrections->blockedReason($original, $shift, $corrections->lastClosing($shift), forAdvance: true)) {
                $validator->errors()->add('amount', $blocked);

                return;
            }
        }

        $pending = (int) $employee->pendingAdvances()->sum('amount_cents');
        $remaining = $employee->remainingAdvanceCents($pending - ($advance->amount_cents ?? 0));
        if ($this->amountCents() > $remaining) {
            $available = number_format($remaining / 100, 2, ',', '.');
            $validator->errors()->add('amount', "El monto supera el disponible de USD {$available}.");

            return;
        }

        $account = $this->input('payment_method') === 'transfer'
            ? $this->reviewBankAccount($this->integer('bank_account_id'))
            : null;
        $credit = $original !== null && $account?->id === $original->bank_account_id
            ? $original->amount_cents
            : 0;
        app(CalculateCashBalances::class)->ensureFundsAvailable($this->amountCents(), $account, creditCents: $credit);

        if ($account !== null) {
            $this->reviewVoucher($validator, $this->input('voucher'), $account->bank_id, $original?->id, required: true);
        }
    }

    public function amountCents(): int
    {
        return ConvertUsdToCents::fromDecimal((string) $this->validated('amount'));
    }
}
