<?php

namespace Database\Seeders;

use App\Support\Authorization\Roles;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;

class RoleSeeder extends Seeder
{
    public function run(): void
    {
        foreach (Roles::initial() as $role) {
            Role::findOrCreate($role, 'web');
        }
    }
}
