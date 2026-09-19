<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    public function up(): void
    {
        $names = ['fee-types.manage', 'invoices.view', 'invoices.generate', 'invoices.void', 'balances.view', 'financial-reports.view', 'receipts.print', 'payments.void'];
        foreach ($names as $name) {
            DB::table('permissions')->updateOrInsert(['name' => $name, 'guard_name' => 'web'], ['created_at' => now(), 'updated_at' => now()]);
        }
        $admin = DB::table('roles')->where('name', 'Admin')->where('guard_name', 'web')->value('id');
        $proprietor = DB::table('roles')->where('name', 'Proprietor')->where('guard_name', 'web')->value('id');
        foreach (DB::table('permissions')->whereIn('name', $names)->where('guard_name', 'web')->get() as $permission) {
            if ($admin !== null) {
                DB::table('role_has_permissions')->insertOrIgnore(['role_id' => $admin, 'permission_id' => $permission->id]);
            }
            if ($proprietor !== null && in_array($permission->name, ['invoices.view', 'balances.view', 'financial-reports.view', 'receipts.print'], true)) {
                DB::table('role_has_permissions')->insertOrIgnore(['role_id' => $proprietor, 'permission_id' => $permission->id]);
            }
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        /** Permission grants are retained on rollback to preserve administrator customizations. */
    }
};
