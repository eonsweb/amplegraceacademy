<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    public function up(): void
    {
        $this->renamePermission('attendance.update', 'attendance.edit');
    }

    public function down(): void
    {
        $this->renamePermission('attendance.edit', 'attendance.update');
    }

    private function renamePermission(string $from, string $to): void
    {
        DB::transaction(function () use ($from, $to): void {
            $old = DB::table('permissions')->where('name', $from)->where('guard_name', 'web')->first();
            if ($old === null) {
                return;
            }
            $existing = DB::table('permissions')->where('name', $to)->where('guard_name', 'web')->first();
            if ($existing === null) {
                DB::table('permissions')->where('id', $old->id)->update(['name' => $to]);

                return;
            }
            foreach (['role_has_permissions', 'model_has_permissions'] as $table) {
                $rows = DB::table($table)->where('permission_id', $old->id)->get()->map(function (object $row) use ($existing): array {
                    return array_replace((array) $row, ['permission_id' => $existing->id]);
                })->all();
                foreach (array_chunk($rows, 250) as $chunk) {
                    DB::table($table)->insertOrIgnore($chunk);
                }
                DB::table($table)->where('permission_id', $old->id)->delete();
            }
            DB::table('permissions')->where('id', $old->id)->delete();
        });
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
