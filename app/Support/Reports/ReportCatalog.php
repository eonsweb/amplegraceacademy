<?php

namespace App\Support\Reports;

use App\Models\User;
use App\Support\Authorization\Permissions;
use Illuminate\Support\Facades\Gate;

final class ReportCatalog
{
    /** @return array<string, array{title: string, group: string, permission: string, filters: list<string>, columns: array<string, string>, note: string}> */
    public static function all(): array
    {
        $period = ['academic_year_id', 'term_id'];
        $roster = ['academic_year_id', 'class_level_id'];
        $dates = ['date_from', 'date_to'];
        $identity = ['admission_number' => 'Admission number', 'student_name' => 'Student', 'academic_year_name' => 'Academic year', 'class_name' => 'Class'];
        $attendance = ['records' => 'Days recorded', 'present' => 'Present', 'absent' => 'Absent', 'late' => 'Late', 'excused' => 'Excused', 'percentage' => 'Attendance %'];
        $money = ['total' => 'Billed', 'paid' => 'Valid payments', 'outstanding' => 'Outstanding'];
        $reports = [
            'enrollment' => ['Student Enrollment', 'Academic', Permissions::STUDENTS_VIEW, [...$roster, 'status', 'student'], [...$identity, 'status' => 'Enrollment status'], 'Historical placement comes from enrollment records, including former students.'],
            'class-enrollment' => ['Class Enrollment', 'Academic', Permissions::STUDENTS_VIEW, $roster, ['academic_year_name' => 'Academic year', 'class_name' => 'Class', 'students' => 'Students', 'records' => 'Enrollments', 'active' => 'Active', 'other' => 'Other statuses', 'male' => 'Male', 'female' => 'Female'], 'Students are counted distinctly within each class and academic year.'],
            'assessment-results' => ['Assessment Results', 'Academic', Permissions::RESULTS_VIEW, [...$period, 'class_level_id', 'subject_id', 'assessment_id', 'student'], [...$identity, 'assessment_name' => 'Assessment', 'subject_name' => 'Subject', 'score' => 'Score', 'maximum' => 'Maximum', 'percentage' => 'Percentage', 'status' => 'Completion'], 'Select an assessment. Missing scores remain incomplete; no grades or rankings are invented.'],
            'class-results' => ['Class Results', 'Academic', Permissions::RESULTS_VIEW, [...$period, 'class_level_id', 'subject_id', 'student'], [...$identity, 'subjects' => 'Subject performance', 'score' => 'Total earned', 'maximum' => 'Maximum', 'percentage' => 'Percentage', 'status' => 'Completion'], 'Select year, term and class. Totals use the existing Results calculator and your authorized subjects.'],
            'subject-performance' => ['Subject Performance', 'Academic', Permissions::RESULTS_VIEW, [...$period, 'class_level_id', 'subject_id', 'assessment_id'], ['subject_name' => 'Subject', 'students' => 'Students assessed', 'records' => 'Scores entered', 'average_score' => 'Mean score', 'highest' => 'Highest score', 'lowest' => 'Lowest score', 'percentage' => 'Mean assessment %'], 'Statistics cover entered scores only. The mean percentage normalizes each score by its assessment maximum; it is not a final class result.'],
            'attendance-daily' => ['Daily Attendance', 'Attendance', Permissions::ATTENDANCE_VIEW, [...$period, 'class_level_id', 'student', 'date', 'status'], [...$identity, 'term_name' => 'Term', 'date' => 'Date', 'status' => 'Status'], 'Only recorded attendance is shown. Unrecorded days are not treated as absences.'],
            'attendance-students' => ['Student Attendance', 'Attendance', Permissions::ATTENDANCE_VIEW, [...$period, 'class_level_id', 'student', 'status', ...$dates], [...$identity, 'term_name' => 'Term', 'date' => 'Date', 'status' => 'Status'], 'Select a student by exact admission number. Attendance % = (present + late) / all recorded days × 100; excused days remain in the denominator.'],
            'attendance-classes' => ['Class Attendance Summary', 'Attendance', Permissions::ATTENDANCE_VIEW, [...$period, 'class_level_id', ...$dates], ['academic_year_name' => 'Academic year', 'class_name' => 'Class', ...$attendance], 'Attendance % = (present + late) / all records × 100, including excused records in the denominator. No records gives no percentage.'],
            'fees' => ['Fees / Billing', 'Financial', Permissions::FINANCIAL_REPORTS_VIEW, [...$period, 'class_level_id', 'student', 'fee_type_id', 'status'], [...$identity, 'term_name' => 'Term', 'invoice_number' => 'Invoice', 'date' => 'Issued', ...$money, 'status' => 'Status'], 'Fee type selects invoices containing that fee. Amounts are whole-invoice totals. Voided invoices remain visible but are excluded from summary totals.'],
            'payments' => ['Payments', 'Financial', Permissions::FINANCIAL_REPORTS_VIEW, [...$period, 'student', ...$dates, 'payment_method', 'status'], ['receipt_number' => 'Receipt', 'date' => 'Payment date', 'student_name' => 'Student', 'admission_number' => 'Admission number', 'invoices' => 'Invoices', 'payment_method' => 'Method', 'amount' => 'Received', 'period_amount' => 'Selected-period amount', 'recorder' => 'Received by', 'status' => 'Status'], 'With academic filters, totals include only allocations to matching non-void invoices. Received shows the original payment amount. Voided payments are excluded from totals.'],
            'outstanding' => ['Outstanding Balances', 'Financial', Permissions::FINANCIAL_REPORTS_VIEW, [...$period, 'class_level_id', 'student', 'status'], [...$identity, ...$money], 'Balances are grouped by student and historical class/year. Only valid invoices and payments count.'],
            'expenses' => ['Expenses', 'Financial', Permissions::FINANCIAL_REPORTS_VIEW, [...$period, ...$dates, 'expense_category_id', 'payment_method', 'status', 'recorded_by_user_id'], ['id' => 'Expense', 'date' => 'Date', 'category_name' => 'Category', 'description' => 'Description', 'reference' => 'Reference', 'academic_year_name' => 'Academic year', 'term_name' => 'Term', 'payment_method' => 'Method', 'amount' => 'Amount', 'recorder' => 'Entered by', 'status' => 'Status'], 'Only recorded expenses count toward valid expense totals. Drafts and voids remain reportable.'],
            'income-expenses' => ['Income and Expenses', 'Financial', Permissions::FINANCIAL_REPORTS_VIEW, [...$period, ...$dates], ['income' => 'Payments received', 'expenses' => 'Recorded expenses', 'net' => 'Net result'], 'Dates filter payment dates and expense dates. Net is received income less expenses, not a bank balance.'],
            'financial-summary' => ['Financial Summary', 'Financial', Permissions::FINANCIAL_REPORTS_VIEW, $period, [...$money, 'income' => 'Payments received', 'expenses' => 'Recorded expenses', 'net' => 'Net cash result'], 'Billing and balances cover the selected academic period. Payments and expenses use the same Finance Overview calculation.'],
        ];
        $result = [];
        foreach ($reports as $key => [$title, $group, $permission, $filters, $columns, $note]) {
            $result[$key] = compact('title', 'group', 'permission', 'filters', 'columns', 'note');
        }

        return $result;
    }

    /** @return array{title: string, group: string, permission: string, filters: list<string>, columns: array<string, string>, note: string} */
    public static function get(string $report): array
    {
        abort_unless(isset(self::all()[$report]), 404);

        return self::all()[$report];
    }

    public static function authorize(User $user, string $report): void
    {
        $definition = self::get($report);
        Gate::forUser($user)->authorize(Permissions::REPORTS_VIEW);
        Gate::forUser($user)->authorize($definition['permission']);
    }
}
