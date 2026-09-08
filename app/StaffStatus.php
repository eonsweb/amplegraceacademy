<?php

namespace App;

enum StaffStatus: string
{
    case Active = 'active';
    case Inactive = 'inactive';

    public function label(): string
    {
        return str($this->value)->headline()->toString();
    }
}
