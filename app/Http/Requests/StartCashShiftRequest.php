<?php

namespace App\Http\Requests;

use App\Actions\CalculateCashBalances;
use App\Models\CashShift;
use Illuminate\Validation\Validator;

class StartCashShiftRequest extends ReviewableRequest
{
    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return ['responsible_id' => ['required', 'integer', 'exists:users,id']];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return ['responsible_id' => 'responsable de caja'];
    }

    protected function review(Validator $validator): void
    {
        if (CashShift::active() !== null) {
            $validator->errors()->add('responsible_id', 'Ya hay un turno de caja activo.');

            return;
        }

        $cash = app(CalculateCashBalances::class)->handle(true)['cash'];
        if ($cash === null || $cash < 0) {
            $validator->errors()->add('responsible_id', 'Configura y revisa el saldo de efectivo antes de iniciar el turno.');
        }
    }
}
