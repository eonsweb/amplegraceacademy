<?php

namespace App\Support\Authorization;

final class Roles
{
    public const ADMIN = 'Admin';

    public const PROPRIETOR = 'Proprietor';

    public const HEADMASTER = 'Headmaster';

    public const TEACHER = 'Teacher';

    /** @return list<string> */
    public static function initial(): array
    {
        return [self::ADMIN, self::PROPRIETOR, self::HEADMASTER, self::TEACHER];
    }
}
