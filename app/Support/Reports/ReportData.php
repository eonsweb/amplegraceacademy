<?php

namespace App\Support\Reports;

use App\Models\User;
use Illuminate\Database\Query\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

final class ReportData
{
    /** @param array<string, string> $filters */
    public function __construct(public User $user, public string $report, public array $filters)
    {
        ReportCatalog::authorize($user, $report);
        $this->filters = ReportFilters::validate($user, $report, $filters);
    }

    private function query(): Builder
    {
        return match (ReportCatalog::get($this->report)['group']) {
            'Attendance' => AttendanceReports::query($this->user, $this->report, $this->filters),
            'Financial' => FinancialReports::query($this->report, $this->filters),
            default => in_array($this->report, ['enrollment', 'class-enrollment'], true)
                ? EnrollmentReports::query($this->user, $this->report, $this->filters)
                : AcademicReports::query($this->user, $this->report, $this->filters),
        };
    }

    /** @return array<string, int|string|null> */
    public function summary(): array
    {
        if (ReportCatalog::get($this->report)['group'] === 'Financial') {
            $labels = ['records' => 'Records', 'total' => 'Fees billed', 'paid' => 'Payments allocated', 'outstanding' => 'Outstanding balances',
                'income' => 'Payments received', 'expenses' => 'Recorded expenses', 'net' => 'Net result'];

            return collect(FinancialReports::totals($this->report, $this->filters))
                ->mapWithKeys(fn (string $value, string $key): array => [$labels[$key] => FinancialReports::format($key, $value)])->all();
        }
        if (ReportCatalog::get($this->report)['group'] === 'Attendance') {
            return AttendanceReports::summary($this->user, $this->filters);
        }
        if (in_array($this->report, ['enrollment', 'class-enrollment'], true)) {
            return EnrollmentReports::summary($this->user, $this->filters);
        }

        return ['Result rows' => $this->query()->getCountForPagination()];
    }

    /** @return LengthAwarePaginator<int, \stdClass> */
    public function page(int $perPage, int $page = 1): LengthAwarePaginator
    {
        if (in_array($this->report, ['income-expenses', 'financial-summary'], true)) {
            return new LengthAwarePaginator([(object) FinancialReports::totals($this->report, $this->filters)], 1, $perPage, 1);
        }
        $paginator = $this->query()->paginate($perPage, ['*'], 'page', $page);
        $paginator->setCollection($this->decorate($paginator->getCollection()));

        return $paginator;
    }

    /** @return \Generator<int, \stdClass> */
    public function rows(): \Generator
    {
        if (in_array($this->report, ['income-expenses', 'financial-summary'], true)) {
            yield (object) FinancialReports::totals($this->report, $this->filters);

            return;
        }
        foreach ($this->query()->lazy(200)->chunk(200) as $chunk) {
            foreach ($this->decorate(collect($chunk->all())) as $row) {
                yield $row;
            }
        }
    }

    /** @param Collection<int, \stdClass> $rows
     * @return Collection<int, \stdClass>
     */
    private function decorate(Collection $rows): Collection
    {
        return match (ReportCatalog::get($this->report)['group']) {
            'Financial' => FinancialReports::decorate($this->report, $rows),
            'Academic' => AcademicReports::decorate($this->user, $this->report, $this->filters, $rows),
            default => $rows,
        };
    }
}
