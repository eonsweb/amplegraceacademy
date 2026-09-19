<?php

namespace App;

enum ExpenseStatus: string
{
    case Draft = 'draft';
    case Recorded = 'recorded';
    case Voided = 'voided';

    public function label(): string
    {
        return ucfirst($this->value);
    }
}
