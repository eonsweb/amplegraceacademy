<?php

namespace App\Support;

use App\AttendanceStatus;
use App\EnrollmentStatus;
use App\Models\AcademicYear;
use App\Models\ClassLevel;
use App\Models\Payment;
use App\Models\Staff;
use App\Models\Term;
use App\Models\User;
use App\StaffStatus;
use App\StudentStatus;
use App\Support\Academic\AcademicContext;
use App\Support\Authorization\Permissions;
use App\Support\Fees\FeeLedger;
use App\Support\Fees\Money;
use App\Support\Finance\FinanceSummary;
use App\Support\Reports\AttendanceReports;
use App\Support\Reports\EnrollmentReports;
use App\Support\Settings\SystemSettings;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

final class DashboardMetrics
{
    public function __construct(private AcademicContext $context, private SystemSettings $settings) {}

    /** @return array<string, mixed> */
    public function forUser(User $user, string $period = 'year'): array
    {
        Gate::forUser($user)->authorize(Permissions::DASHBOARD_VIEW);
        $today = now($this->settings->timezone())->startOfDay();
        $month = $today->copy()->startOfMonth();
        $previous = $month->copy()->subMonth();
        $yearId = $this->context->currentYearId();
        $termId = $this->context->currentTermId();
        $year = $yearId === null ? null : AcademicYear::query()->find($yearId, ['id', 'name']);
        $term = $termId === null ? null : Term::query()->where('academic_year_id', $yearId)->find($termId, ['id', 'name']);
        $data = ['today' => $today, 'academicYear' => $year?->name, 'academicTerm' => $term?->name,
            'students' => null, 'teachers' => null, 'classes' => null, 'finance' => null, 'attendance' => null,
            'recentPayments' => null, 'period' => $period];

        if ($user->can(Permissions::STUDENTS_VIEW)) {
            $row = $year === null ? null : EnrollmentReports::roster($user, ['academic_year_id' => (string) $yearId])
                ->where('enrollments.status', EnrollmentStatus::Active->value)->where('students.status', StudentStatus::Active->value)
                ->where('enrollment_date', '<=', $today->toDateString())
                ->selectRaw('COUNT(DISTINCT students.id) AS total, COUNT(DISTINCT CASE WHEN enrollment_date >= ? THEN students.id END) AS new_count', [$month->toDateString()])->first();
            $data['students'] = ['total' => (int) ($row->total ?? 0), 'new' => (int) ($row->new_count ?? 0)];
        }
        if ($user->can(Permissions::STAFF_VIEW)) {
            $row = Staff::query()->where('status', StaffStatus::Active)->whereRaw('LOWER(TRIM(role_title)) = ?', ['teacher'])->toBase()
                ->selectRaw('COUNT(*) AS total, COUNT(CASE WHEN employment_date BETWEEN ? AND ? THEN 1 END) AS new_count', [$month->toDateString(), $today->toDateString()])->first();
            $data['teachers'] = ['total' => (int) $row->total, 'new' => (int) $row->new_count];
        }
        if ($user->can(Permissions::CLASSES_VIEW)) {
            $data['classes'] = ClassLevel::query()->where('is_active', true)->count();
        }
        if ($user->can(Permissions::ATTENDANCE_VIEW)) {
            $summary = $year === null || $term === null ? [] : AttendanceReports::summary($user,
                ['academic_year_id' => (string) $yearId, 'term_id' => (string) $termId, 'date' => $today->toDateString()]);
            $records = (int) ($summary['Records'] ?? 0);
            $segments = [];
            $stops = [];
            $offset = 0;
            $colors = ['present' => 'var(--color-brand-700)', 'absent' => '#d4d4d8', 'late' => '#fbbf24', 'excused' => '#60a5fa'];
            foreach (AttendanceStatus::cases() as $status) {
                $count = (int) ($summary[$status->label()] ?? 0);
                $percentage = $records === 0 ? 0 : $count / $records * 100;
                $end = $offset + $percentage;
                $stops[] = $colors[$status->value].' '.$offset.'% '.$end.'%';
                $segments[] = ['label' => $status->label(), 'count' => $count, 'percentage' => round($percentage, 1), 'color' => $colors[$status->value]];
                $offset = $end;
            }
            $data['attendance'] = ['records' => $records, 'percentage' => $summary['Attendance %'] ?? null,
                'segments' => $segments, 'gradient' => $records === 0 ? '#e4e4e7' : 'conic-gradient('.implode(', ', $stops).')'];
        }
        if ($user->can(Permissions::FINANCIAL_REPORTS_VIEW)) {
            $payments = FinanceSummary::payments(['date_from' => $previous->toDateString(), 'date_to' => $today->toDateString()])->whereNull('payments.voided_at');
            $totals = DB::query()->fromSub($payments, 'collections')->selectRaw(
                'COALESCE(SUM(CASE WHEN payment_date >= ? THEN period_amount ELSE 0 END), 0) AS current_total, COALESCE(SUM(CASE WHEN payment_date < ? THEN period_amount ELSE 0 END), 0) AS previous_total',
                [$month->toDateString(), $month->toDateString()])->first();
            $current = (string) $totals->current_total;
            $last = (string) $totals->previous_total;
            $chartYear = $period === 'previous-year' ? $today->year - 1 : $today->year;
            $chartPayments = FinanceSummary::payments(['date_from' => $chartYear.'-01-01', 'date_to' => $chartYear === $today->year ? $today->toDateString() : $chartYear.'-12-31'])->whereNull('payments.voided_at');
            $monthly = DB::query()->fromSub($chartPayments, 'collections')->selectRaw('MONTH(payment_date) AS month, SUM(period_amount) AS amount')->groupByRaw('MONTH(payment_date)')->pluck('amount', 'month');
            $points = [];
            $months = $chartYear === $today->year ? $today->month : 12;
            for ($number = 1; $number <= $months; $number++) {
                $points[] = ['label' => $today->copy()->startOfYear()->addMonths($number - 1)->format('M'), 'amount' => Money::decimal(Money::minor((string) ($monthly[$number] ?? '0')))];
            }
            $maximum = max(100, ...array_map(fn (array $point): int => Money::minor($point['amount']), $points));
            $path = '';
            foreach ($points as $index => &$point) {
                $point['x'] = 58 + $index * 515 / max(1, $months - 1);
                $point['y'] = 230 - (float) (string) BigDecimal::of(Money::minor($point['amount']))->multipliedBy(210)->dividedBy($maximum, 3, RoundingMode::HalfUp);
                $path .= ($index === 0 ? 'M' : ' L').$point['x'].' '.$point['y'];
            }
            unset($point);
            $outstanding = $year === null ? null : FeeLedger::summary(FeeLedger::invoices()->where('invoices.academic_year_id', $yearId))['outstanding'];
            $data['finance'] = ['current' => $current, 'previous' => $last, 'trend' => self::trend($current, $last), 'outstanding' => $outstanding,
                'chartYear' => $chartYear, 'hasCollections' => $monthly->isNotEmpty(), 'points' => $points, 'path' => $path, 'maximum' => Money::decimal($maximum)];
        }
        if ($user->can(Permissions::PAYMENTS_VIEW)) {
            $data['recentPayments'] = Payment::query()->whereNull('voided_at')->where('payment_date', '<=', $today->toDateString())
                ->select(['id', 'student_id', 'receipt_number', 'amount', 'payment_date', 'payment_method'])
                ->with(['student:id,first_name,middle_name,last_name', 'allocations:id,payment_id,invoice_id', 'allocations.invoice:id,class_name,student_name'])
                ->orderByDesc('payment_date')->orderByDesc('id')->limit(5)->get();
        }

        return $data;
    }

    private static function trend(string $current, string $previous): string
    {
        if (Money::minor($previous) === 0) {
            return Money::minor($current) === 0 ? 'No collections in either month' : 'New this month';
        }
        $percentage = BigDecimal::of($current)->minus($previous)->multipliedBy(100)->dividedBy($previous, 1, RoundingMode::HalfUp);

        return ($percentage->isPositive() ? '+' : '').$percentage.'% vs previous month';
    }
}
