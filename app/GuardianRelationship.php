<?php

namespace App;

enum GuardianRelationship
{
    case Mother;
    case Father;
    case Grandmother;
    case Grandfather;
    case Aunt;
    case Uncle;
    case Sibling;
    case LegalGuardian;
    case FosterParent;
    case Other;

    public function label(): string
    {
        return match ($this) {
            self::LegalGuardian => 'Legal Guardian',
            self::FosterParent => 'Foster Parent',
            default => $this->name,
        };
    }

    /** @return list<string> */
    public static function labels(): array
    {
        return array_map(fn (self $relationship): string => $relationship->label(), self::cases());
    }
}
