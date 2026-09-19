<?php

namespace App\Policies;

use App\Models\Invoice;
use App\Models\User;
use App\Support\Authorization\Permissions;

class InvoicePolicy
{
    public function view(User $user, Invoice $invoice): bool
    {
        return $user->can(Permissions::INVOICES_VIEW);
    }

    public function void(User $user, Invoice $invoice): bool
    {
        return $user->can(Permissions::INVOICES_VOID);
    }
}
