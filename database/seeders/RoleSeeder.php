<?php

namespace Database\Seeders;

use App\Support\Authorization\Roles;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;

class RoleSeeder extends Seeder
{
    public function run(): void
    {
        foreach (Roles::initial() as $role) {
            DB::transaction(function () use ($role): void {
                $model = Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);

                if ($model->wasRecentlyCreated) {
                    $model->givePermissionTo(RolePermissionSeeder::defaults()[$role] ?? []);
                }
            });
        }
    }
}
