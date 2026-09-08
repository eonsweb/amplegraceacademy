<?php

namespace App;

enum EmploymentType: string
{
    case Permanent = 'permanent';
    case Contract = 'contract';
    case PartTime = 'part-time';
    case Temporary = 'temporary';

    public function label(): string
    {
        return match ($this) {
            self::PartTime => 'Part-time',
            default => str($this->value)->headline()->toString(),
        };
    }
}
