<?php

namespace App\Http\Requests;

use App\Actions\CalculateCashBalances;
use App\Actions\ConvertUsdToCents;
use App\Actions\CorrectCashMovement;
use App\Models\Bank;
use App\Models\CashMovement;
use App\Models\CashOpening;
use App\Models\CashShift;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Validator;

class StoreCashMovementRequest extends ConfirmCashMovementRequest
{
    public function authorize(): bool
    {
        return $this->routeIs('movements.store') || parent::authorize();
    }

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

    protected function review(Validator $validator): void
    {
        $kind = $this->input('kind');
        $transfer = $kind !== 'deposit' && $this->input('payment_method') === 'transfer';
        $original = $this->route('movement');
        abort_unless($original === null || $original instanceof CashMovement, 404);

        $confirmedDeposit = $kind === 'deposit' && $original?->confirmed_at !== null;
        $account = $transfer || $confirmedDeposit
            ? $this->reviewBankAccount($this->integer('bank_account_id'))
            : null;
        $user = $this->user();

        $shift = CashShift::active();
        if ($restriction = CashShift::movementRestriction($user, $shift)) {
            $validator->errors()->add('movement', $restriction);

            return;
        }

        if ($original !== null) {
            $corrections = app(CorrectCashMovement::class);
            if ($blocked = $corrections->blockedReason($original, $shift, $corrections->lastClosing($shift))) {
                $validator->errors()->add('movement', $blocked);

                return;
            }
        }

        if ($transfer || $confirmedDeposit) {
            $bank = $this->filled('origin_bank_id')
                ? Bank::query()->findOrFail($this->integer('origin_bank_id'))
                : $account->bank;
            if (! $bank->is_active) {
                $validator->errors()->add('origin_bank_id', 'Selecciona un banco de origen activo.');

                return;
            }

            $this->reviewVoucher($validator, $this->input('voucher'), $bank->id, $original?->id,
                required: $kind === 'expense' || $confirmedDeposit || $this->boolean('confirm'));
        }

        if ($kind === 'income' && ! $transfer && CashOpening::query()->sole()->opening_balance_cents === null) {
            $validator->errors()->add('amount', 'La administradora debe registrar el saldo inicial de efectivo.');
        }

        if (in_array($kind, ['deposit', 'expense'], true)) {
            $fundsAccount = $kind === 'deposit' ? null : $account;
            $credit = $original !== null && ($kind === 'deposit' || $fundsAccount?->id === $original->bank_account_id)
                ? $original->amount_cents
                : 0;
            app(CalculateCashBalances::class)->ensureFundsAvailable(
                $this->amountCents(), $fundsAccount, revealBalance: $fundsAccount === null || $user->is_admin,
                creditCents: $credit,
            );
        }
    }

    public function amountCents(): int
    {
        return ConvertUsdToCents::fromDecimal((string) $this->validated('amount'));
    }
}
