<?php

namespace App;

enum PaymentMethod: string
{
    case Cash = 'cash';
    case MobileMoney = 'mobile_money';
    case BankTransfer = 'bank_transfer';
    case Cheque = 'cheque';
    case Card = 'card';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Cash => 'Cash', self::MobileMoney => 'Mobile money', self::BankTransfer => 'Bank transfer', self::Cheque => 'Cheque', self::Card => 'Card', self::Other => 'Other'
        };
    }
}
