<?php

namespace App\Support\Authorization;

final class Permissions
{
    public const DASHBOARD_VIEW = 'dashboard.view';

    public const STUDENTS_VIEW = 'students.view';

    public const STUDENTS_CREATE = 'students.create';

    public const STUDENTS_UPDATE = 'students.update';

    public const STUDENTS_DELETE = 'students.delete';

    public const GUARDIANS_VIEW = 'guardians.view';

    public const GUARDIANS_CREATE = 'guardians.create';

    public const GUARDIANS_UPDATE = 'guardians.update';

    public const GUARDIANS_DELETE = 'guardians.delete';

    public const STAFF_VIEW = 'staff.view';

    public const STAFF_CREATE = 'staff.create';

    public const STAFF_UPDATE = 'staff.update';

    public const STAFF_DELETE = 'staff.delete';

    public const CLASSES_VIEW = 'classes.view';

    public const CLASSES_CREATE = 'classes.create';

    public const CLASSES_UPDATE = 'classes.update';

    public const CLASSES_DELETE = 'classes.delete';

    public const SUBJECTS_VIEW = 'subjects.view';

    public const SUBJECTS_CREATE = 'subjects.create';

    public const SUBJECTS_UPDATE = 'subjects.update';

    public const SUBJECTS_DELETE = 'subjects.delete';

    public const ATTENDANCE_VIEW = 'attendance.view';

    public const ATTENDANCE_RECORD = 'attendance.record';

    public const ATTENDANCE_UPDATE = 'attendance.update';

    public const ASSESSMENTS_VIEW = 'assessments.view';

    public const ASSESSMENTS_CREATE = 'assessments.create';

    public const ASSESSMENTS_UPDATE = 'assessments.update';

    public const ASSESSMENTS_DELETE = 'assessments.delete';

    public const ASSESSMENTS_RECORD_SCORES = 'assessments.record-scores';

    public const FEES_VIEW = 'fees.view';

    public const FEES_MANAGE = 'fees.manage';

    public const PAYMENTS_VIEW = 'payments.view';

    public const PAYMENTS_RECORD = 'payments.record';

    public const EXPENSES_VIEW = 'expenses.view';

    public const EXPENSES_CREATE = 'expenses.create';

    public const EXPENSES_UPDATE = 'expenses.update';

    public const EXPENSES_DELETE = 'expenses.delete';

    public const REPORTS_VIEW = 'reports.view';

    public const USERS_VIEW = 'users.view';

    public const USERS_CREATE = 'users.create';

    public const USERS_UPDATE = 'users.update';

    public const USERS_DELETE = 'users.delete';

    public const ROLES_VIEW = 'roles.view';

    public const ROLES_CREATE = 'roles.create';

    public const ROLES_UPDATE = 'roles.update';

    public const ROLES_DELETE = 'roles.delete';

    public const PERMISSIONS_VIEW = 'permissions.view';

    public const PERMISSIONS_ASSIGN = 'permissions.assign';

    public const SETTINGS_VIEW = 'settings.view';

    public const SETTINGS_UPDATE = 'settings.update';

    public const AUDIT_LOGS_VIEW = 'audit-logs.view';

    /**
     * @return array<string, array<string, string>>
     */
    public static function grouped(): array
    {
        return [
            'Dashboard' => [self::DASHBOARD_VIEW => 'View dashboard'],
            'Students' => [self::STUDENTS_VIEW => 'View students', self::STUDENTS_CREATE => 'Create students', self::STUDENTS_UPDATE => 'Update students', self::STUDENTS_DELETE => 'Delete students'],
            'Guardians' => [self::GUARDIANS_VIEW => 'View guardians', self::GUARDIANS_CREATE => 'Create guardians', self::GUARDIANS_UPDATE => 'Update guardians', self::GUARDIANS_DELETE => 'Delete guardians'],
            'Staff' => [self::STAFF_VIEW => 'View staff', self::STAFF_CREATE => 'Create staff', self::STAFF_UPDATE => 'Update staff', self::STAFF_DELETE => 'Delete staff'],
            'Classes' => [self::CLASSES_VIEW => 'View classes', self::CLASSES_CREATE => 'Create classes', self::CLASSES_UPDATE => 'Update classes', self::CLASSES_DELETE => 'Delete classes'],
            'Subjects' => [self::SUBJECTS_VIEW => 'View subjects', self::SUBJECTS_CREATE => 'Create subjects', self::SUBJECTS_UPDATE => 'Update subjects', self::SUBJECTS_DELETE => 'Delete subjects'],
            'Attendance' => [self::ATTENDANCE_VIEW => 'View attendance', self::ATTENDANCE_RECORD => 'Record attendance', self::ATTENDANCE_UPDATE => 'Update attendance'],
            'Assessments' => [self::ASSESSMENTS_VIEW => 'View assessments', self::ASSESSMENTS_CREATE => 'Create assessments', self::ASSESSMENTS_UPDATE => 'Update assessments', self::ASSESSMENTS_DELETE => 'Delete assessments', self::ASSESSMENTS_RECORD_SCORES => 'Record scores'],
            'Fees' => [self::FEES_VIEW => 'View fees', self::FEES_MANAGE => 'Manage fees'],
            'Payments' => [self::PAYMENTS_VIEW => 'View payments', self::PAYMENTS_RECORD => 'Record payments'],
            'Expenses' => [self::EXPENSES_VIEW => 'View expenses', self::EXPENSES_CREATE => 'Create expenses', self::EXPENSES_UPDATE => 'Update expenses', self::EXPENSES_DELETE => 'Delete expenses'],
            'Reports' => [self::REPORTS_VIEW => 'View reports'],
            'Users' => [self::USERS_VIEW => 'View users', self::USERS_CREATE => 'Create users', self::USERS_UPDATE => 'Update users', self::USERS_DELETE => 'Delete users'],
            'Roles' => [self::ROLES_VIEW => 'View roles', self::ROLES_CREATE => 'Create roles', self::ROLES_UPDATE => 'Update roles', self::ROLES_DELETE => 'Delete roles'],
            'Permissions' => [self::PERMISSIONS_VIEW => 'View permissions', self::PERMISSIONS_ASSIGN => 'Assign permissions'],
            'Settings' => [self::SETTINGS_VIEW => 'View system settings', self::SETTINGS_UPDATE => 'Update system settings'],
            'Audit logs' => [self::AUDIT_LOGS_VIEW => 'View audit logs'],
        ];
    }

    /** @return list<string> */
    public static function all(): array
    {
        $permissions = [];

        foreach (self::grouped() as $group) {
            array_push($permissions, ...array_keys($group));
        }

        return $permissions;
    }

    /** @return list<string> */
    public static function critical(): array
    {
        return [self::USERS_UPDATE, self::ROLES_UPDATE, self::PERMISSIONS_ASSIGN];
    }

    public static function isPowerful(string $permission): bool
    {
        return in_array($permission, [self::PERMISSIONS_ASSIGN, self::ROLES_UPDATE, self::SETTINGS_UPDATE, self::USERS_DELETE], true);
    }
}
