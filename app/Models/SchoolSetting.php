<?php

namespace App\Models;

use Database\Factories\SchoolSettingFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[Fillable([
    'school_name',
    'school_initials',
    'contact_email',
    'phone',
    'address',
    'dashboard_logo',
    'login_logo',
    'favicon',
    'currency_code',
    'date_format',
    'time_format',
    'timezone',
    'records_per_page',
])]
class SchoolSetting extends Model
{
    /** @use HasFactory<SchoolSettingFactory> */
    use HasFactory;
}
