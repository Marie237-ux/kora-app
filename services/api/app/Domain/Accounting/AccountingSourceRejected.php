<?php

namespace App\Domain\Accounting;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/** Stable machine reason in addition to the existing localized HTTP validation error. */
final class AccountingSourceRejected extends ValidationException
{
    public function __construct(public readonly string $reason)
    {
        $validator = Validator::make([], []);
        $validator->errors()->add('resource_id', __('accounting.'.$reason));
        parent::__construct($validator);
    }
}
