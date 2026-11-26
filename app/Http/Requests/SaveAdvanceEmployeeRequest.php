<?php

namespace App\Http\Requests;

use App\Actions\ConvertUsdToCents;
use App\Concerns\ProfileValidationRules;
use App\Models\User;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rules\Unique;

class SaveAdvanceEmployeeRequest extends FormRequest
{
    use ProfileValidationRules;

    public function authorize(): bool
    {
        return Gate::allows('manage-advances');
    }

    protected function prepareForValidation(): void
    {
        $employee = $this->route('employee');
        abort_if($employee instanceof User && $employee->is_admin, 404);

        $this->normalizeUsername($this);

        if ($this->has('advance_limit')) {
            $this->merge(['advance_limit' => ConvertUsdToCents::normalize($this->input('advance_limit'))]);
        }
    }

    /** @return array<string, array<int, string|Unique|ValidationRule|array<mixed>>> */
    public function rules(): array
    {
        $employee = $this->route('employee');

        return [
            'name' => $this->nameRules(),
            ...$this->loginFieldRules($employee instanceof User ? $employee->id : null),
            'password' => [$employee instanceof User ? 'nullable' : 'required', 'string', 'min:8', 'confirmed'],
            'advance_limit' => ['sometimes', 'required', ...ConvertUsdToCents::OPENING_BALANCE_RULES],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'name.required' => 'Ingresa el nombre del empleado.',
            'email.required' => 'Ingresa el correo del empleado.',
            'email.unique' => 'Ya existe una cuenta con este correo.',
            'password.required' => 'Define una contraseña inicial para el empleado.',
            'password.confirmed' => 'Las contraseñas no coinciden.',
            'password.min' => 'La contraseña debe tener al menos 8 caracteres.',
            'advance_limit.required' => 'Ingresa el límite máximo de adelantos.',
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return ['advance_limit' => 'límite máximo de adelantos'];
    }

    public function advanceLimitCents(): ?int
    {
        $limit = $this->validated('advance_limit');

        return $limit === null ? null : ConvertUsdToCents::fromDecimal((string) $limit);
    }
}
