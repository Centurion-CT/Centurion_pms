<?php

declare(strict_types=1);

namespace App\Actions\Auth;

use App\Models\Operator;
use App\Models\Tenant;

final readonly class RegistrationResult
{
    public function __construct(
        public Tenant $tenant,
        public Operator $operator,
    ) {}
}
