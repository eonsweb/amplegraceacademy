<?php

use App\Support\Authorization\Permissions;
use App\Support\Authorization\Roles;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Permission::findOrCreate(Permissions::STUDENTS_IMPORT, 'web');
        foreach (Role::query()->where('guard_name', 'web')->whereIn('name', [Roles::ADMIN, Roles::HEADMASTER])->get() as $role) {
            $role->givePermissionTo(Permissions::STUDENTS_IMPORT);
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Permission::query()->where('name', Permissions::STUDENTS_IMPORT)->where('guard_name', 'web')->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
