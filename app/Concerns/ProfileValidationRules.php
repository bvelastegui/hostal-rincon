<?php

namespace App\Concerns;

use App\Models\User;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Unique;

trait ProfileValidationRules
{
    /**
     * Get the validation rules used to validate user profiles.
     *
     * @return array<string, array<int, Unique|ValidationRule|array<mixed>|string>>
     */
    protected function profileRules(?int $userId = null): array
    {
        return [
            'name' => $this->nameRules(),
            ...$this->loginFieldRules($userId),
        ];
    }

    /** @return array<string, array<int, Unique|ValidationRule|array<mixed>|string>> */
    protected function loginFieldRules(?int $userId = null): array
    {
        return config('app.desktop')
            ? ['username' => $this->usernameRules($userId)]
            : ['email' => $this->emailRules($userId)];
    }

    protected function normalizeUsername(Request $request): void
    {
        if (config('app.desktop') && is_string($request->input('username'))) {
            $request->merge(['username' => Str::lower($request->input('username'))]);
        }
    }

    /** @return list<string|Unique> */
    protected function usernameRules(?int $userId = null): array
    {
        $unique = Rule::unique(User::class, 'username');

        return [
            'required',
            'string',
            'min:3',
            'max:64',
            'regex:/\A[a-z0-9][a-z0-9._-]*\z/',
            $userId === null ? $unique : $unique->ignore($userId),
        ];
    }

    /**
     * Get the validation rules used to validate user names.
     *
     * @return array<int, Unique|ValidationRule|array<mixed>|string>
     */
    protected function nameRules(): array
    {
        return ['required', 'string', 'max:255'];
    }

    /**
     * Get the validation rules used to validate user emails.
     *
     * @return array<int, Unique|ValidationRule|array<mixed>|string>
     */
    protected function emailRules(?int $userId = null): array
    {
        return [
            'required',
            'string',
            'email',
            'max:255',
            $userId === null
                ? Rule::unique(User::class)
                : Rule::unique(User::class)->ignore($userId),
        ];
    }
}
