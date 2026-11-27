<?php

namespace App\Http\Requests;

use App\Actions\RestoreLocalBackup;
use Illuminate\Validation\Validator;

class RestoreBackupRequest extends ReviewableRequest
{
    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return ['backup' => ['required', 'file', 'extensions:zip', 'max:65536']];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return ['backup' => 'respaldo'];
    }

    protected function review(Validator $validator): void
    {
        app(RestoreLocalBackup::class)->handle($this->file('backup'), review: true);
    }
}
