<?php

namespace App\Models;

use Database\Factories\SchoolSettingFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['school_name', 'contact_email', 'phone', 'address'])]
class SchoolSetting extends Model
{
    /** @use HasFactory<SchoolSettingFactory> */
    use HasFactory;
}
