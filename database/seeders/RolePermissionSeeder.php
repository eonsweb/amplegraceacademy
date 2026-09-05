<?php

namespace Database\Seeders;

use App\Support\Authorization\Permissions;
use App\Support\Authorization\Roles;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class RolePermissionSeeder extends Seeder
{
    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $mappings = [
            Roles::ADMIN => Permissions::all(),
            Roles::PROPRIETOR => [
                Permissions::DASHBOARD_VIEW, Permissions::STUDENTS_VIEW, Permissions::GUARDIANS_VIEW, Permissions::STAFF_VIEW,
                Permissions::CLASSES_VIEW, Permissions::SUBJECTS_VIEW, Permissions::ATTENDANCE_VIEW,
                Permissions::ASSESSMENTS_VIEW, Permissions::FEES_VIEW, Permissions::PAYMENTS_VIEW,
                Permissions::EXPENSES_VIEW, Permissions::REPORTS_VIEW,
                ...Permissions::userManagement(),
            ],
            Roles::HEADMASTER => [
                Permissions::DASHBOARD_VIEW,
                Permissions::STUDENTS_VIEW, Permissions::STUDENTS_CREATE, Permissions::STUDENTS_UPDATE,
                Permissions::GUARDIANS_VIEW, Permissions::GUARDIANS_CREATE, Permissions::GUARDIANS_UPDATE,
                Permissions::GUARDIANS_LINK_STUDENT, Permissions::GUARDIANS_UNLINK_STUDENT, Permissions::STAFF_VIEW,
                Permissions::CLASSES_VIEW, Permissions::CLASSES_CREATE, Permissions::CLASSES_UPDATE,
                Permissions::SUBJECTS_VIEW, Permissions::SUBJECTS_CREATE, Permissions::SUBJECTS_UPDATE,
                Permissions::ATTENDANCE_VIEW, Permissions::ATTENDANCE_RECORD, Permissions::ATTENDANCE_UPDATE,
                Permissions::ASSESSMENTS_VIEW, Permissions::ASSESSMENTS_CREATE, Permissions::ASSESSMENTS_UPDATE,
                Permissions::ASSESSMENTS_RECORD_SCORES, Permissions::REPORTS_VIEW,
                ...Permissions::userManagement(),
            ],
            Roles::TEACHER => [
                Permissions::DASHBOARD_VIEW, Permissions::STUDENTS_VIEW, Permissions::CLASSES_VIEW,
                Permissions::SUBJECTS_VIEW, Permissions::ATTENDANCE_VIEW, Permissions::ATTENDANCE_RECORD,
                Permissions::ASSESSMENTS_VIEW, Permissions::ASSESSMENTS_RECORD_SCORES,
            ],
        ];

        foreach ($mappings as $roleName => $permissions) {
            Role::findByName($roleName, 'web')->syncPermissions($permissions);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
