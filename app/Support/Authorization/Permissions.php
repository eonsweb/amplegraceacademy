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

    public const GUARDIANS_LINK_STUDENT = 'guardians.link-student';

    public const GUARDIANS_UNLINK_STUDENT = 'guardians.unlink-student';

    public const STAFF_VIEW = 'staff.view';

    public const STAFF_CREATE = 'staff.create';

    public const STAFF_UPDATE = 'staff.update';

    public const STAFF_DELETE = 'staff.delete';

    public const STAFF_MANAGE_STATUS = 'staff.manage-status';

    public const STAFF_MANAGE_USER_ACCOUNT = 'staff.manage-user-account';

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

    public const ATTENDANCE_EDIT = 'attendance.edit';

    public const ASSESSMENTS_VIEW = 'assessments.view';

    public const ASSESSMENTS_CREATE = 'assessments.create';

    public const ASSESSMENTS_UPDATE = 'assessments.update';

    public const ASSESSMENTS_DELETE = 'assessments.delete';

    public const ASSESSMENTS_RECORD_SCORES = 'assessments.record-scores';

    public const ASSESSMENTS_MANAGE_ALL = 'assessments.manage-all';

    public const RESULTS_VIEW = 'results.view';

    public const RESULTS_VIEW_ALL = 'results.view-all';

    public const FEES_VIEW = 'fees.view';

    public const FEES_MANAGE = 'fees.manage';

    public const FEE_TYPES_MANAGE = 'fee-types.manage';

    public const INVOICES_VIEW = 'invoices.view';

    public const INVOICES_GENERATE = 'invoices.generate';

    public const INVOICES_VOID = 'invoices.void';

    public const BALANCES_VIEW = 'balances.view';

    public const FINANCIAL_REPORTS_VIEW = 'financial-reports.view';

    public const RECEIPTS_PRINT = 'receipts.print';

    public const PAYMENTS_VOID = 'payments.void';

    public const PAYMENTS_VIEW = 'payments.view';

    public const PAYMENTS_RECORD = 'payments.record';

    public const EXPENSES_VIEW = 'expenses.view';

    public const EXPENSES_CREATE = 'expenses.create';

    public const EXPENSES_UPDATE = 'expenses.update';

    public const EXPENSES_DELETE = 'expenses.delete';

    public const EXPENSES_VOID = 'expenses.void';

    public const EXPENSE_CATEGORIES_MANAGE = 'expense-categories.manage';

    public const REPORTS_VIEW = 'reports.view';

    public const USERS_VIEW = 'users.view';

    public const USERS_CREATE = 'users.create';

    public const USERS_UPDATE = 'users.update';

    public const USERS_DELETE = 'users.delete';

    public const USERS_ASSIGN_ROLE = 'users.assign-role';

    public const USERS_RESET_PASSWORD = 'users.reset-password';

    public const USERS_CHANGE_STATUS = 'users.change-status';

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
            'Guardians' => [self::GUARDIANS_VIEW => 'View guardians', self::GUARDIANS_CREATE => 'Create guardians', self::GUARDIANS_UPDATE => 'Update guardians', self::GUARDIANS_DELETE => 'Delete guardians', self::GUARDIANS_LINK_STUDENT => 'Link guardians to students', self::GUARDIANS_UNLINK_STUDENT => 'Unlink guardians from students'],
            'Staff' => [self::STAFF_VIEW => 'View staff', self::STAFF_CREATE => 'Create staff', self::STAFF_UPDATE => 'Update staff', self::STAFF_DELETE => 'Delete staff', self::STAFF_MANAGE_STATUS => 'Change staff status', self::STAFF_MANAGE_USER_ACCOUNT => 'Link staff user accounts'],
            'Classes' => [self::CLASSES_VIEW => 'View classes', self::CLASSES_CREATE => 'Create classes', self::CLASSES_UPDATE => 'Update classes', self::CLASSES_DELETE => 'Delete classes'],
            'Subjects' => [self::SUBJECTS_VIEW => 'View subjects', self::SUBJECTS_CREATE => 'Create subjects', self::SUBJECTS_UPDATE => 'Update subjects', self::SUBJECTS_DELETE => 'Delete subjects'],
            'Attendance' => [self::ATTENDANCE_VIEW => 'View attendance', self::ATTENDANCE_RECORD => 'Record attendance', self::ATTENDANCE_EDIT => 'Edit attendance'],
            'Assessments' => [self::ASSESSMENTS_VIEW => 'View assessments', self::ASSESSMENTS_CREATE => 'Create assessments', self::ASSESSMENTS_UPDATE => 'Update assessments', self::ASSESSMENTS_DELETE => 'Delete assessments', self::ASSESSMENTS_RECORD_SCORES => 'Record scores', self::ASSESSMENTS_MANAGE_ALL => 'Manage assessments across all subjects'],
            'Results' => [self::RESULTS_VIEW => 'View results', self::RESULTS_VIEW_ALL => 'View results across all subjects'],
            'Fees' => [self::FEES_VIEW => 'View fees overview', self::FEES_MANAGE => 'Manage fee structures', self::FEE_TYPES_MANAGE => 'Manage fee types', self::INVOICES_VIEW => 'View invoices', self::INVOICES_GENERATE => 'Generate invoices', self::INVOICES_VOID => 'Void invoices', self::BALANCES_VIEW => 'View student balances', self::FINANCIAL_REPORTS_VIEW => 'View financial reports'],
            'Payments' => [self::PAYMENTS_VIEW => 'View payments', self::PAYMENTS_RECORD => 'Record payments', self::PAYMENTS_VOID => 'Void payments', self::RECEIPTS_PRINT => 'Print receipts'],
            'Expenses' => [self::EXPENSES_VIEW => 'View expenses', self::EXPENSES_CREATE => 'Create expenses', self::EXPENSES_UPDATE => 'Edit and record draft expenses', self::EXPENSES_DELETE => 'Delete draft expenses', self::EXPENSES_VOID => 'Void recorded expenses', self::EXPENSE_CATEGORIES_MANAGE => 'Manage expense categories'],
            'Reports' => [self::REPORTS_VIEW => 'View reports'],
            'Users' => [self::USERS_VIEW => 'View users', self::USERS_CREATE => 'Create users', self::USERS_UPDATE => 'Update users', self::USERS_DELETE => 'Delete users', self::USERS_ASSIGN_ROLE => 'Assign user roles', self::USERS_RESET_PASSWORD => 'Reset user passwords', self::USERS_CHANGE_STATUS => 'Change user status'],
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
        return [self::USERS_VIEW, self::USERS_UPDATE, self::USERS_ASSIGN_ROLE, self::USERS_CHANGE_STATUS, self::ROLES_UPDATE, self::PERMISSIONS_ASSIGN];
    }

    /** @return list<string> */
    public static function userManagement(): array
    {
        return [
            self::USERS_VIEW,
            self::USERS_CREATE,
            self::USERS_UPDATE,
            self::USERS_DELETE,
            self::USERS_ASSIGN_ROLE,
            self::USERS_RESET_PASSWORD,
            self::USERS_CHANGE_STATUS,
        ];
    }

    public static function isPowerful(string $permission): bool
    {
        return in_array($permission, [self::PERMISSIONS_ASSIGN, self::ROLES_UPDATE, self::SETTINGS_UPDATE, self::USERS_DELETE, self::USERS_ASSIGN_ROLE], true);
    }
}
