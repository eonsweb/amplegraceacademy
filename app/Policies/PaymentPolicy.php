<?php

namespace App\Policies;

use App\Models\Payment;
use App\Models\User;
use App\Support\Authorization\Permissions;

class PaymentPolicy
{
    public function view(User $user, Payment $payment): bool
    {
        return $user->can(Permissions::PAYMENTS_VIEW);
    }

    public function print(User $user, Payment $payment): bool
    {
        return $user->can(Permissions::RECEIPTS_PRINT);
    }

    public function void(User $user, Payment $payment): bool
    {
        return $user->can(Permissions::PAYMENTS_VOID);
    }
}
