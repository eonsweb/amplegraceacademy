<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    public function up(): void
    {
        $names = ['expenses.view', 'expenses.create', 'expenses.update', 'expenses.delete', 'expenses.void', 'expense-categories.manage'];
        foreach ($names as $name) {
            DB::table('permissions')->insertOrIgnore(['name' => $name, 'guard_name' => 'web', 'created_at' => now(), 'updated_at' => now()]);
        }
        $admin = DB::table('roles')->where('name', 'Admin')->where('guard_name', 'web')->value('id');
        if ($admin !== null) {
            foreach (DB::table('permissions')->whereIn('name', $names)->where('guard_name', 'web')->pluck('id') as $permission) {
                DB::table('role_has_permissions')->insertOrIgnore(['role_id' => $admin, 'permission_id' => $permission]);
            }
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        /** Retain permission grants to preserve administrator customizations. */
    }
};
