<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

abstract class ReviewableRequest extends FormRequest
{
    /** @return list<callable(Validator): void> */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($this->isPrecognitive() && $validator->errors()->isEmpty()) {
                    $this->review($validator);
                }
            },
        ];
    }

    abstract protected function review(Validator $validator): void;
}
