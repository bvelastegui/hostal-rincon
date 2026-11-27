<?php

namespace App\Http\Requests;

use App\Models\CashClosing;
use Illuminate\Validation\Validator;

class ResolveCashShortageRequest extends ReviewableRequest
{
    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'method' => ['required', 'in:deduction,other'],
            'note' => ['required', 'string', 'max:500'],
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return ['method' => 'forma de resolución', 'note' => 'nota de resolución'];
    }

    protected function review(Validator $validator): void
    {
        $closing = $this->route('closing');
        abort_unless($closing instanceof CashClosing, 404);

        if ($closing->difference_cents >= 0) {
            $validator->errors()->add('method', 'Solo se pueden resolver registros con faltante.');
        }
    }
}
