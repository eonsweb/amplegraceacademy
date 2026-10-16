<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * @property array<string, mixed> $payload
 * @property list<string> $messages
 */
#[Fillable(['student_import_id', 'row_number', 'payload', 'status', 'messages', 'generate_number', 'student_id', 'guardians_created', 'guardians_linked'])]
class StudentImportRow extends Model
{
    protected function casts(): array
    {
        return ['payload' => 'encrypted:array', 'messages' => 'array', 'generate_number' => 'boolean'];
    }

    protected $hidden = ['payload'];
}
