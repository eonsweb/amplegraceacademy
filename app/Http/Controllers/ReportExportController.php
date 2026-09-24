<?php

namespace App\Http\Controllers;

use App\Support\Reports\FinancialReports;
use App\Support\Reports\ReportCatalog;
use App\Support\Reports\ReportData;
use App\Support\Reports\ReportFilters;
use App\Support\Settings\SystemSettings;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReportExportController extends Controller
{
    public function __invoke(Request $request, string $report, string $format): StreamedResponse
    {
        abort_unless(in_array($format, ['csv', 'print'], true), 404);
        ReportCatalog::authorize($request->user(), $report);
        $input = $request->validate(['filters' => ['sometimes', 'array']]);
        $data = new ReportData($request->user(), $report, $input['filters'] ?? []);
        $definition = ReportCatalog::get($report);
        $settings = app(SystemSettings::class);
        $filters = ReportFilters::describe($request->user(), $report, $data->filters);
        $generated = now($settings->timezone())->format('Y-m-d H:i T');
        $stream = function () use ($data, $definition, $settings, $filters, $generated, $format): void {
            DB::transaction(function () use ($data, $definition, $settings, $filters, $generated, $format): void {
                $summary = $data->summary();
                if ($format === 'print') {
                    echo view('reports.print', compact('definition', 'settings', 'filters', 'generated', 'summary'))->render();
                    $hasRows = false;
                    foreach ($data->rows() as $row) {
                        $hasRows = true;
                        echo '<tr>';
                        foreach ($definition['columns'] as $column => $label) {
                            echo '<td>'.e(FinancialReports::format($column, $row->$column ?? null)).'</td>';
                        }
                        echo '</tr>';
                    }
                    if (! $hasRows) {
                        echo '<tr><td colspan="'.count($definition['columns']).'">No records found for these filters.</td></tr>';
                    }
                    echo '</tbody></table></main></body></html>';

                    return;
                }
                $output = fopen('php://output', 'w');
                if ($output === false) {
                    throw new \RuntimeException('Unable to open CSV output.');
                }
                $write = function (array $values) use ($output): void {
                    fputcsv($output, array_map(self::csvCell(...), $values), ',', '"', '');
                };
                $write([$settings->schoolName(), $definition['title']]);
                $write(['Generated', $generated, 'Currency', $settings->currency()]);
                foreach ($filters as $label => $value) {
                    $write([$label, $value]);
                }
                foreach ($summary as $label => $value) {
                    $write([$label, $value ?? '—']);
                }
                $write([]);
                $write(array_values($definition['columns']));
                foreach ($data->rows() as $row) {
                    $values = [];
                    foreach ($definition['columns'] as $column => $label) {
                        $values[] = FinancialReports::format($column, $row->$column ?? null);
                    }
                    $write($values);
                }
                fclose($output);
            });
        };

        return $format === 'csv'
            ? response()->streamDownload($stream, $report.'.csv', ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'private, no-store'])
            : response()->stream($stream, 200, ['Content-Type' => 'text/html; charset=UTF-8', 'Cache-Control' => 'private, no-store']);
    }

    private static function csvCell(mixed $value): string
    {
        $text = (string) $value;

        return preg_match('/^[\s]*[=+@-]|^[\t\r\n]/u', $text) ? "'".$text : $text;
    }
}
