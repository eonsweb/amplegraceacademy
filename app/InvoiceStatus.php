<?php

namespace App;

enum InvoiceStatus: string
{
    case Unpaid = 'unpaid';
    case PartiallyPaid = 'partially_paid';
    case Paid = 'paid';
    case Void = 'void';

    public function label(): string
    {
        return match ($this) {
            self::Unpaid => 'Unpaid', self::PartiallyPaid => 'Partially paid', self::Paid => 'Paid', self::Void => 'Void'
        };
    }
}
