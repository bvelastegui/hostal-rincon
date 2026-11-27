<?php

namespace App\Http\Requests;

use App\Models\Bank;
use Illuminate\Support\Str;
use Illuminate\Validation\Validator;

class StoreBankRequest extends ReviewableRequest
{
    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return ['name' => ['required', 'string', 'max:100']];
    }

    protected function review(Validator $validator): void
    {
        $key = Str::lower(Str::squish($this->string('name')->toString()));

        if (Bank::query()->where('name_key', $key)->exists()) {
            $validator->errors()->add('name', 'Este banco ya está registrado.');
        }
    }
}
