<?php

namespace App\Support;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Reader\IReadFilter;
use PhpOffice\PhpSpreadsheet\Reader\Xlsx;
use PhpOffice\PhpSpreadsheet\Shared\Date;
use Throwable;
use ZipArchive;

final class StudentImportReader
{
    public const MAX_ROWS = 1000;

    public const HEADERS = [
        'Admission Number', 'First Name', 'Middle Name', 'Last Name', 'Gender', 'Date of Birth',
        'Admission Date', 'Academic Year', 'Class Level', 'Enrollment Status', 'Student Address', 'Religion',
        'Guardian 1 First Name', 'Guardian 1 Last Name', 'Guardian 1 Relationship', 'Guardian 1 Phone', 'Guardian 1 Address',
        'Guardian 2 First Name', 'Guardian 2 Last Name', 'Guardian 2 Relationship', 'Guardian 2 Phone', 'Guardian 2 Address', 'Medical Notes',
    ];

    /** @return list<array<string, mixed>> */
    public function read(UploadedFile $file): array
    {
        Validator::make(['workbook' => $file], ['workbook' => ['required', 'file', 'extensions:xlsx', 'mimes:xlsx', 'max:5120']])->validate();
        $path = $file->getPathname();
        $this->checkArchive($path);
        $reader = new Xlsx;
        $reader->setReadDataOnly(true)->setReadEmptyCells(false)->setLoadSheetsOnly(['Student Import']);
        $reader->setReadFilter(new class implements IReadFilter
        {
            public function readCell(string $columnAddress, int $row, string $worksheetName = ''): bool
            {
                return $worksheetName === 'Student Import' && $row <= StudentImportReader::MAX_ROWS + 1
                    && Coordinate::columnIndexFromString($columnAddress) <= 24;
            }
        });
        $book = null;
        $calendar = Date::getExcelCalendar();
        try {
            $info = collect($reader->listWorksheetInfo($path))->firstWhere('worksheetName', 'Student Import');
            if ($info === null || $info['totalRows'] > self::MAX_ROWS + 1 || $info['totalColumns'] > 23) {
                $this->fail('Use Student Import with the 23 template columns and at most 1,000 data rows. Remove formatted rows beyond row 1001.');
            }
            $book = $reader->load($path);
            $sheet = $book->getSheetByName('Student Import');
            if ($sheet === null) {
                $this->fail('The workbook must contain a Student Import worksheet.');
            }
            Date::setExcelCalendar($book->getExcelCalendar());
            $headers = [];
            $canonical = array_combine(array_map('strtolower', self::HEADERS), self::HEADERS);
            for ($column = 1; $column <= 23; $column++) {
                $value = strtolower(trim((string) $sheet->getCell([$column, 1])->getValue()));
                if (! isset($canonical[$value]) || in_array($canonical[$value], $headers, true)) {
                    $this->fail('Headers must contain each of the 23 supported labels exactly once; a header is missing, duplicated or unsupported.');
                }
                $headers[$column] = $canonical[$value];
            }
            $rows = [];
            $yearCounts = [];
            $admissionColumn = Coordinate::stringFromColumnIndex((int) array_search('Admission Date', $headers, true));
            for ($number = 2; $number <= $info['totalRows']; $number++) {
                $values = array_fill_keys(self::HEADERS, '');
                $errors = [];
                $formula = null;
                foreach ($headers as $column => $header) {
                    $cell = $sheet->getCell([$column, $number]);
                    $value = $cell->getValue();
                    if ($cell->isFormula()) {
                        if ($header === 'Admission Number') {
                            $formula = (string) $value;
                            $value = $cell->getOldCalculatedValue();
                        } else {
                            $errors[$header] = $header.': formulas are not supported; enter a literal value.';
                            $value = '';
                        }
                    }
                    if (! is_scalar($value) && $value !== null) {
                        $errors[$header] = $header.': unsupported cell value.';
                        $value = '';
                    }
                    $values[$header] = trim((string) $value);
                    if (mb_strlen($values[$header]) > 5000) {
                        $errors[$header] = $header.': exceeds 5,000 characters. The preview is truncated; replace it with a shorter value.';
                        $values[$header] = mb_substr($values[$header], 0, 5000);
                    }
                }
                $content = $values;
                unset($content['Admission Number']);
                if (count(array_filter($content, fn (string $value): bool => $value !== '')) === 0 && $errors === []) {
                    continue;
                }
                foreach (['Date of Birth', 'Admission Date'] as $header) {
                    $values[$header] = $this->date($values[$header]);
                }
                $year = substr($values['Admission Date'], 0, 4);
                $yearCounts[$year] = ($yearCounts[$year] ?? 0) + 1;
                if ($formula !== null) {
                    $reference = $admissionColumn.$number;
                    $range = '$'.$admissionColumn.'$2:'.$reference;
                    $expected = '=IF('.$reference.'="","","AGA-"&YEAR('.$reference.')&"-"&TEXT(COUNTIFS('.$range.',">="&DATE(YEAR('.$reference.'),1,1),'.$range.',"<"&DATE(YEAR('.$reference.')+1,1,1)),"0000"))';
                    $cached = sprintf('AGA-%s-%04d', $year, $yearCounts[$year]);
                    if ($formula !== $expected || $values['Admission Number'] !== $cached || ! preg_match('/^\d{4}-\d{2}-\d{2}$/D', $values['Admission Date'])) {
                        $values['Admission Number'] = '';
                        $values['_number_issue'] = 'Admission formula has no reliable cached result. Enter a literal number or explicitly request generation.';
                    }
                }
                $values['_row'] = $number;
                $values['_errors'] = $errors;
                $rows[] = $values;
            }
            if ($rows === []) {
                $this->fail('The Student Import worksheet contains no student records.');
            }

            return $rows;
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (Throwable) {
            $this->fail('The workbook could not be read safely. Save an unencrypted .xlsx copy in Excel and try again.');
        } finally {
            $book?->disconnectWorksheets();
            Date::setExcelCalendar($calendar);
        }
    }

    private function date(string $value): string
    {
        if ($value === '') {
            return '';
        }
        if (ctype_digit($value) && (int) $value > 0 && (int) $value < 100000 && ! (Date::getExcelCalendar() === 1900 && (int) $value === 60)) {
            return Date::excelToDateTimeObject((int) $value)->format('Y-m-d');
        }
        foreach (['Y-m-d', 'd/m/Y'] as $format) {
            $date = \DateTimeImmutable::createFromFormat('!'.$format, $value);
            if ($date !== false && $date->format($format) === $value) {
                return $date->format('Y-m-d');
            }
        }

        return 'invalid-date';
    }

    private function checkArchive(string $path): void
    {
        $zip = new ZipArchive;
        if ($zip->open($path) !== true) {
            $this->fail('Upload a valid .xlsx workbook.');
        }
        try {
            $total = 0;
            if ($zip->numFiles > 2000) {
                $this->fail('The workbook has too many archive entries.');
            }
            for ($index = 0; $index < $zip->numFiles; $index++) {
                $entry = $zip->statIndex($index);
                if ($entry === false) {
                    $this->fail('Invalid workbook archive.');
                }
                $name = $entry['name'];
                $total += $entry['size'];
                if ($entry['size'] > 8 * 1024 * 1024 || $total > 20 * 1024 * 1024
                    || str_contains($name, '..') || str_contains($name, '\\') || str_starts_with($name, '/')
                    || str_contains(strtolower($name), 'vbaproject') || str_starts_with($name, 'xl/externalLinks/')) {
                    $this->fail('Workbook exceeds safe expanded-size limits or contains unsupported external links, macros or paths.');
                }
            }
        } finally {
            $zip->close();
        }
    }

    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['workbook' => $message]);
    }
}
