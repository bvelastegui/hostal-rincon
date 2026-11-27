<?php

namespace App\Http\Requests;

use App\Actions\ConfirmCashMovement;
use App\Models\BankAccount;
use App\Models\CashMovement;
use App\Models\CashShift;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Validator;

class ConfirmCashMovementRequest extends ReviewableRequest
{
    public function authorize(): bool
    {
        $movement = $this->route('movement');
        abort_if($movement instanceof CashMovement && ! $movement->detailsVisibleTo($this->user()), 404);

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

    protected function review(Validator $validator): void
    {
        $movement = $this->route('movement');
        abort_unless($movement instanceof CashMovement, 404);

        $shift = CashShift::active();
        $user = $this->user();

        if (! $user->is_admin && $shift?->id !== $movement->cash_shift_id) {
            abort(404);
        }

        if ($restriction = CashShift::movementRestriction($user, $shift)) {
            $validator->errors()->add('movement', $restriction);

            return;
        }

        if ($movement->voided_at !== null || $movement->returned_at !== null) {
            $validator->errors()->add('movement', $movement->voided_at !== null
                ? 'El movimiento ya está anulado.'
                : 'El efectivo de este retiro ya volvió a caja.');

            return;
        }

        $voucher = $this->input('voucher') ?? $movement->voucher;
        $bankId = $movement->kind === 'deposit'
            ? $this->reviewBankAccount($this->integer('bank_account_id'))->bank_id
            : $movement->bank_id;

        if ($movement->kind === 'deposit' || $movement->payment_method === 'transfer') {
            $this->reviewVoucher($validator, $voucher, $bankId, $movement->id, required: true);
        }
    }

    protected function reviewBankAccount(int $id): BankAccount
    {
        $account = BankAccount::query()->with('bank')->findOrFail($id);

        if (! $account->bank->is_active) {
            throw ValidationException::withMessages([
                'bank_account_id' => 'Selecciona una cuenta de un banco activo.',
            ]);
        }

        return $account;
    }

    protected function reviewVoucher(Validator $validator, ?string $voucher, ?int $bankId, ?int $ignoreId, bool $required): void
    {
        if ($voucher === null) {
            if ($required) {
                $validator->errors()->add('voucher', 'Ingresa el número de voucher para confirmar el movimiento bancario.');
            }

            return;
        }

        $duplicate = CashMovement::query()->where('bank_id', $bankId)->where('voucher', $voucher)
            ->when($ignoreId !== null, fn ($query) => $query->whereKeyNot($ignoreId))
            ->when($this->filled('request_id'), fn ($query) => $query->where('request_id', '!=', $this->input('request_id')))
            ->exists();

        if ($duplicate) {
            throw app(ConfirmCashMovement::class)->duplicateVoucher();
        }
    }

    /** @return list<string> */
    protected function voucherRules(): array
    {
        return ['nullable', 'string', 'max:100'];
    }
}
