<?php

namespace App;

enum StudentStatus: string
{
    case Active = 'active';
    case Inactive = 'inactive';
    case Graduated = 'graduated';
    case Transferred = 'transferred';

    public function label(): string
    {
        return str($this->value)->headline()->toString();
    }
}
