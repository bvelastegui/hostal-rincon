<?php

namespace App\Http\Requests;

use App\Actions\CalculateCashBalances;
use App\Actions\ConvertUsdToCents;
use App\Models\CashShift;
use Illuminate\Validation\Validator;

class StoreCashClosingRequest extends ReviewableRequest
{
    protected function prepareForValidation(): void
    {
        $amount = $this->input('counted_cash');

        if (is_string($amount)) {
            $this->merge(['counted_cash' => str_replace(',', '.', $amount)]);
        }
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'request_id' => ['required', 'uuid'],
            'cash_shift_id' => ['nullable', 'integer', 'min:1'],
            'expected_cash_cents' => ['required', 'integer'],
            'counted_cash' => ['required', 'numeric', 'decimal:0,2', 'min:0', 'max:999999999.99'],
            'note' => ['nullable', 'string', 'max:500'],
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return ['counted_cash' => 'efectivo contado', 'note' => 'observación'];
    }

    protected function review(Validator $validator): void
    {
        $shift = CashShift::active();
        $shiftId = $this->filled('cash_shift_id') ? $this->integer('cash_shift_id') : null;
        if ($shift?->id !== $shiftId) {
            $validator->errors()->add('cash_shift_id', 'El turno activo cambió. Revisa quién tiene la caja antes de confirmar.');

            return;
        }

        $current = app(CalculateCashBalances::class)->handle(true)['cash'];
        if ($current === null || $current !== $this->integer('expected_cash_cents')) {
            $validator->errors()->add('expected_cash_cents', $current === null
                ? 'Configura el saldo inicial de efectivo para registrar el cierre.'
                : 'El saldo esperado cambió. Revisa el saldo actualizado antes de confirmar.');

            return;
        }

        if ($this->filled('note')) {
            return;
        }

        if ($this->routeIs('cash-closings.administrative-close')) {
            $validator->errors()->add('note', 'Ingresa el motivo del cierre administrativo.');
        } elseif ($this->countedCashCents() !== $current) {
            $validator->errors()->add('note', 'Ingresa una observación para registrar el faltante o sobrante.');
        }
    }

    public function countedCashCents(): int
    {
        return ConvertUsdToCents::fromDecimal((string) $this->validated('counted_cash'));
    }
}
