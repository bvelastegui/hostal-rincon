<?php

namespace App\Http\Requests;

use App\Concerns\PasswordValidationRules;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Illuminate\Validation\Validator;
use Laravel\Fortify\Features;

class ResetPasswordReviewRequest extends ReviewableRequest
{
    use PasswordValidationRules;

    public function authorize(): bool
    {
        abort_unless(Features::enabled(Features::resetPasswords()), 404);

        return true;
    }

    /** @return array<string, list<string|PasswordRule>> */
    public function rules(): array
    {
        return [
            'token' => ['required', 'string'],
            'email' => ['required', 'email'],
            'password' => $this->passwordRules(),
        ];
    }

    protected function review(Validator $validator): void
    {
        $broker = Password::broker(config('fortify.passwords'));
        $user = $broker->getUser(['email' => Str::lower($this->string('email')->toString())]);
        if ($user === null || ! $broker->tokenExists($user, $this->string('token')->toString())) {
            $validator->errors()->add('email', __(Password::INVALID_TOKEN));
        }
    }
}
