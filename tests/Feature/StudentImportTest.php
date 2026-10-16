<?php

use App\Actions\Students\ImportStudents;
use App\Models\AcademicYear;
use App\Models\ClassLevel;
use App\Models\Enrollment;
use App\Models\Guardian;
use App\Models\SchoolSetting;
use App\Models\Student;
use App\Models\StudentImport;
use App\Models\User;
use App\Support\Authorization\Permissions;
use App\Support\Settings\SystemSettings;
use App\Support\StudentImportReader;
use App\Support\StudentImportValidator;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Spatie\Permission\Models\Permission;
use Symfony\Component\HttpKernel\Exception\HttpException;

function importActor(): User
{
    $actor = User::factory()->create();
    foreach ([Permissions::STUDENTS_IMPORT, Permissions::STUDENTS_CREATE, Permissions::STUDENTS_VIEW, Permissions::GUARDIANS_VIEW, Permissions::GUARDIANS_CREATE, Permissions::GUARDIANS_LINK_STUDENT] as $permission) {
        Permission::findOrCreate($permission, 'web');
        $actor->givePermissionTo($permission);
    }

    return $actor;
}

function importSetup(): User
{
    SchoolSetting::factory()->create(['id' => 1, 'school_initials' => 'AGA']);
    app(SystemSettings::class)->forget();
    AcademicYear::factory()->create(['name' => '2026/2027']);
    ClassLevel::factory()->create(['name' => 'Nursery 2', 'is_active' => true]);

    return importActor();
}

function importRow(array $changes = []): array
{
    return array_replace(array_fill_keys(StudentImportReader::HEADERS, ''), [
        'Admission Number' => 'AGA-2025-0001', 'First Name' => 'Synthetic', 'Middle Name' => 'Example', 'Last Name' => 'Student',
        'Gender' => 'Female', 'Date of Birth' => '2019-02-01', 'Admission Date' => '2025-09-15',
        'Academic Year' => '2026/2027', 'Class Level' => 'Nursery 2', 'Enrollment Status' => 'Active',
        'Student Address' => 'Test address', 'Religion' => 'Test religion', 'Medical Notes' => 'Synthetic notes only',
        'Guardian 1 First Name' => 'Example', 'Guardian 1 Last Name' => 'Parent', 'Guardian 1 Relationship' => 'Mother',
        'Guardian 1 Phone' => '0240000001', 'Guardian 1 Address' => 'Test household',
    ], $changes);
}

function importWorkbook(array $rows, ?Closure $modify = null, ?array $headers = null): UploadedFile
{
    $book = new Spreadsheet;
    $sheet = $book->getActiveSheet()->setTitle('Student Import');
    $headers ??= StudentImportReader::HEADERS;
    $sheet->fromArray($headers);
    foreach ($rows as $index => $row) {
        foreach ($headers as $column => $header) {
            $sheet->setCellValueExplicit([$column + 1, $index + 2], $row[$header] ?? '', DataType::TYPE_STRING);
        }
    }
    $book->createSheet()->setTitle('Analytics')->setCellValue('A1', '=1/0');
    $book->createSheet()->setTitle('Instructions')->setCellValue('A1', 'Not student records');
    $book->createSheet()->setTitle('Lookup Values');
    $book->createSheet()->setTitle('Family & Location');
    $modify?->__invoke($book);
    $path = tempnam(sys_get_temp_dir(), 'student-import-test-');
    try {
        (new Xlsx($book))->setPreCalculateFormulas(false)->save($path);

        return UploadedFile::fake()->createWithContent('students.xlsx', file_get_contents($path));
    } finally {
        unlink($path);
        $book->disconnectWorksheets();
    }
}

function finishImport(User $actor, StudentImport $batch): void
{
    $imports = app(ImportStudents::class);
    $imports->confirm($actor, $batch->id);
    while ($batch->fresh()->status === 'importing') {
        $imports->process($actor, $batch->id);
    }
}

test('xlsx import reuses atomic student admission and maps every supported field', function () {
    $actor = importSetup();
    $row = importRow([
        'Guardian 2 First Name' => 'Another', 'Guardian 2 Last Name' => 'Parent', 'Guardian 2 Relationship' => 'Father',
        'Guardian 2 Phone' => '+233240000002', 'Guardian 2 Address' => 'Other household',
    ]);
    $batch = app(ImportStudents::class)->prepare($actor, importWorkbook([$row]), '2026-09-01');
    $this->assertDatabaseCount('students', 0);
    expect($batch->rows()->first()->status)->toBe('ready');
    expect(DB::table('student_import_rows')->value('payload'))->not->toContain('Synthetic notes');

    finishImport($actor, $batch);

    $student = Student::query()->sole();
    expect($student->admission_number)->toBe('AGA-2025-0001');
    expect($student->only(['first_name', 'middle_name', 'last_name', 'gender', 'status', 'address', 'religion', 'medical_notes']))->toMatchArray([
        'first_name' => 'Synthetic', 'middle_name' => 'Example', 'last_name' => 'Student',
        'address' => 'Test address', 'religion' => 'Test religion', 'medical_notes' => 'Synthetic notes only',
    ]);
    expect($student->admission_date->toDateString())->toBe('2025-09-15');
    expect($student->date_of_birth->toDateString())->toBe('2019-02-01');
    expect($student->enrollments()->sole()->enrollment_date->toDateString())->toBe('2026-09-01');
    expect($student->enrollments()->sole()->academicYear->name)->toBe('2026/2027');
    expect($student->guardians()->count())->toBe(2);
    expect($student->primaryGuardians()->sole()->phone)->toBe('0240000001');
    expect($student->guardians()->where('first_name', 'Another')->first()->pivot->relationship)->toBe('Father');
    expect(DB::table('students')->value('medical_notes'))->not->toContain('Synthetic notes');
    expect($batch->fresh()->status)->toBe('completed');
    $this->assertDatabaseCount('attendances', 0);
    $this->assertDatabaseCount('invoices', 0);
});

test('optional missing birth date and second guardian do not block admission', function () {
    $actor = importSetup();
    $batch = app(ImportStudents::class)->prepare($actor, importWorkbook([importRow(['Date of Birth' => '', 'Religion' => '', 'Medical Notes' => '', 'Student Address' => ''])]), '2026-09-01');
    expect($batch->rows()->sole()->status)->toBe('warning');
    finishImport($actor, $batch);
    expect(Student::query()->sole()->date_of_birth)->toBeNull();
    $this->assertDatabaseCount('guardians', 1);
});

test('missing required information stays reviewable and other students remain importable', function (array $changes) {
    $actor = importSetup();
    $batch = app(ImportStudents::class)->prepare($actor, importWorkbook([
        importRow($changes),
        importRow(['First Name' => 'Other', 'Admission Number' => 'AGA-2025-0002']),
    ]), '2026-09-01');
    expect($batch->rows()->orderBy('row_number')->first()->status)->toBe('review');
    finishImport($actor, $batch);
    $this->assertDatabaseCount('students', 1);
    expect(Student::query()->sole()->first_name)->toBe('Other');
})->with([
    'admission date' => [['Admission Date' => '']],
    'guardian names' => [['Guardian 1 First Name' => '', 'Guardian 1 Last Name' => '']],
    'first name' => [['First Name' => '']],
    'gender' => [['Gender' => '']],
]);

test('preview correction supplies missing data without inventing an admission date', function () {
    $actor = importSetup();
    $imports = app(ImportStudents::class);
    $batch = $imports->prepare($actor, importWorkbook([importRow(['Admission Date' => '', 'Admission Number' => ''])]), '2026-09-01');
    $row = $batch->rows()->sole();
    $imports->correct($actor, $batch->id, $row->id, ['First Name' => 'Corrected'], true);
    expect($row->fresh()->status)->toBe('review');
    $this->assertDatabaseCount('students', 0);
    $imports->correct($actor, $batch->id, $row->id, ['Admission Date' => '2025-09-15'], true);
    finishImport($actor, $batch);
    expect(Student::query()->sole()->admission_number)->toBe('AGA/2025/0001');
});

test('duplicate numbers and duplicate student candidates within a workbook require review', function (array $changes) {
    $actor = importSetup();
    $batch = app(ImportStudents::class)->prepare($actor, importWorkbook([importRow(), importRow($changes)]), '2026-09-01');
    expect($batch->rows()->pluck('status')->all())->toBe(['review', 'review']);
    $this->assertDatabaseCount('students', 0);
})->with([
    'duplicate identifier' => [['First Name' => 'Another']],
    'separator alias' => [['First Name' => 'Another', 'Admission Number' => 'AGA/2025/0001']],
    'duplicate full name' => [['Admission Number' => 'AGA-2025-0002']],
]);

test('reimport and repeated processing cannot duplicate students or enrollments', function () {
    $actor = importSetup();
    $imports = app(ImportStudents::class);
    $batch = $imports->prepare($actor, importWorkbook([importRow()]), '2026-09-01');
    finishImport($actor, $batch);
    $imports->process($actor, $batch->id);
    $again = $imports->prepare($actor, importWorkbook([importRow()]), '2026-09-01');
    expect($again->rows()->sole()->status)->toBe('exists');
    $this->assertDatabaseCount('students', 1);
    $this->assertDatabaseCount('enrollments', 1);
    $this->assertDatabaseCount('student_guardian', 1);
});

test('existing student candidates are not silently merged or renumbered', function () {
    $actor = importSetup();
    Student::factory()->create(['first_name' => 'Synthetic', 'middle_name' => 'Example', 'last_name' => 'Student', 'date_of_birth' => '2019-02-01', 'admission_number' => 'AGA/2020/0099']);
    $batch = app(ImportStudents::class)->prepare($actor, importWorkbook([importRow()]), '2026-09-01');
    expect($batch->rows()->sole()->status)->toBe('review');
    expect(Student::query()->sole()->admission_number)->toBe('AGA/2020/0099');
});

test('siblings link an exact existing guardian without changing contact details', function () {
    $actor = importSetup();
    $batch = app(ImportStudents::class)->prepare($actor, importWorkbook([
        importRow(), importRow(['First Name' => 'Sibling', 'Admission Number' => 'AGA-2025-0002', 'Guardian 1 Phone' => '+233 24 000 0001']),
    ]), '2026-09-01');
    finishImport($actor, $batch);
    $this->assertDatabaseCount('guardians', 1);
    $this->assertDatabaseCount('student_guardian', 2);
    expect($batch->rows()->sum('guardians_created'))->toBe('1');
    expect($batch->rows()->sum('guardians_linked'))->toBe('1');
});

test('ambiguous guardian identities and conflicting addresses block automatic linking', function (bool $duplicate) {
    $actor = importSetup();
    $attributes = ['first_name' => 'Example', 'middle_name' => null, 'last_name' => 'Parent', 'phone' => '0240000001', 'address' => $duplicate ? 'Test household' : 'Different'];
    Guardian::factory()->create($attributes);
    if ($duplicate) {
        Guardian::factory()->create($attributes);
    }
    $batch = app(ImportStudents::class)->prepare($actor, importWorkbook([importRow()]), '2026-09-01');
    expect($batch->rows()->sole()->status)->toBe('review');
    $this->assertDatabaseCount('students', 0);
})->with([true, false]);

test('shared phone alone never merges different guardians', function () {
    $actor = importSetup();
    Guardian::factory()->create(['first_name' => 'Someone', 'last_name' => 'Else', 'phone' => '0240000001']);
    $batch = app(ImportStudents::class)->prepare($actor, importWorkbook([importRow()]), '2026-09-01');
    finishImport($actor, $batch);
    $this->assertDatabaseCount('guardians', 2);
});

test('invalid dates enums and missing academic mappings require correction', function (array $changes) {
    $actor = importSetup();
    $batch = app(ImportStudents::class)->prepare($actor, importWorkbook([importRow($changes)]), '2026-09-01');
    expect($batch->rows()->sole()->status)->toBe('review');
})->with([
    'invalid date' => [['Admission Date' => '2025-02-30']],
    'bad gender' => [['Gender' => 'unknown']],
    'bad status' => [['Enrollment Status' => 'unknown']],
    'missing year' => [['Academic Year' => '2000/2001']],
    'missing class' => [['Class Level' => 'Unknown']],
    'future birth' => [['Date of Birth' => '2099-01-01']],
    'ambiguous sequence padding' => [['Admission Number' => 'AGA-2025-00001']],
]);

test('reader maps reordered headers ignores other sheets and skips formatted blank rows', function () {
    importSetup();
    $file = importWorkbook([importRow()], function (Spreadsheet $book): void {
        $book->getSheetByName('Student Import')->getStyle('A500:W500')->getFont()->setBold(true);
    }, array_reverse(StudentImportReader::HEADERS));
    $rows = app(StudentImportReader::class)->read($file);
    expect($rows)->toHaveCount(1);
    expect($rows[0]['First Name'])->toBe('Synthetic');
});

test('reader refuses missing duplicated and unsupported headers', function (string $header) {
    $headers = StudentImportReader::HEADERS;
    $headers[0] = $header;
    expect(fn () => app(StudentImportReader::class)->read(importWorkbook([importRow()], headers: $headers)))->toThrow(ValidationException::class);
})->with(['', 'First Name', 'Unsupported']);

test('reader refuses invalid content and spoofed extensions', function () {
    expect(fn () => app(StudentImportReader::class)->read(UploadedFile::fake()->createWithContent('students.xlsx', '<html>bad</html>')))->toThrow(ValidationException::class);
    $file = importWorkbook([importRow()]);
    expect(fn () => app(StudentImportReader::class)->read(new UploadedFile($file->getPathname(), 'students.xls', test: true)))->toThrow(ValidationException::class);
});

test('formula admission numbers are never executed and missing caches require explicit resolution', function () {
    $actor = importSetup();
    $file = importWorkbook([importRow()], function (Spreadsheet $book): void {
        $book->getSheetByName('Student Import')->setCellValue('A2', '=HYPERLINK("https://invalid.test","AGA-2025-0001")');
    });
    $batch = app(ImportStudents::class)->prepare($actor, $file, '2026-09-01');
    expect($batch->rows()->sole()->payload['Admission Number'])->toBe('');
    expect($batch->rows()->sole()->status)->toBe('review');
});

test('non admission formulas are rejected rather than executed', function () {
    $actor = importSetup();
    $file = importWorkbook([importRow()], fn (Spreadsheet $book) => $book->getSheetByName('Student Import')->setCellValue('B2', '=1+1'));
    $batch = app(ImportStudents::class)->prepare($actor, $file, '2026-09-01');
    expect($batch->rows()->sole()->status)->toBe('error');
});

test('reserved hyphenated numbers advance the authoritative historical sequence', function () {
    $actor = importSetup();
    $batch = app(ImportStudents::class)->prepare($actor, importWorkbook([
        importRow(['Admission Number' => '', 'First Name' => 'Generated']),
        importRow(['Admission Number' => 'AGA-2025-0090']),
    ]), '2026-09-01');
    $row = $batch->rows()->orderBy('row_number')->first();
    app(ImportStudents::class)->correct($actor, $batch->id, $row->id, [], true);
    finishImport($actor, $batch);
    expect(Student::query()->where('first_name', 'Generated')->sole()->admission_number)->toBe('AGA/2025/0091');
    expect(Student::query()->where('first_name', 'Synthetic')->sole()->admission_number)->toBe('AGA-2025-0090');
});

test('each request processes at most five students and resumes safely', function () {
    $actor = importSetup();
    $rows = [];
    for ($i = 1; $i <= 7; $i++) {
        $rows[] = importRow(['First Name' => 'Child'.$i, 'Admission Number' => sprintf('AGA-2025-%04d', $i)]);
    }
    $imports = app(ImportStudents::class);
    $batch = $imports->prepare($actor, importWorkbook($rows), '2026-09-01');
    $imports->confirm($actor, $batch->id);
    $imports->confirm($actor, $batch->id);
    $imports->process($actor, $batch->id);
    $this->assertDatabaseCount('students', 5);
    expect($batch->fresh()->status)->toBe('importing');
    $imports->process($actor, $batch->id);
    $this->assertDatabaseCount('students', 7);
    expect($batch->fresh()->status)->toBe('completed');
});

test('student level failures roll back guardians enrollment and student but keep earlier successes', function () {
    $actor = importSetup();
    $batch = app(ImportStudents::class)->prepare($actor, importWorkbook([
        importRow(), importRow(['First Name' => 'Fails', 'Admission Number' => 'AGA-2025-0002', 'Guardian 1 First Name' => 'Newparent']),
    ]), '2026-09-01');
    Enrollment::creating(function (Enrollment $enrollment): void {
        if ($enrollment->student->first_name === 'Fails') {
            throw new RuntimeException('Synthetic persistence failure');
        }
    });
    try {
        finishImport($actor, $batch);
        $this->assertDatabaseCount('students', 1);
        $this->assertDatabaseCount('guardians', 1);
        $this->assertDatabaseCount('enrollments', 1);
        expect($batch->rows()->orderByDesc('row_number')->first()->status)->toBe('failed');
    } finally {
        Enrollment::flushEventListeners();
        Enrollment::clearBootedModels();
    }
});

test('preview matching uses bounded queries as spreadsheet rows grow', function () {
    importSetup();
    $rows = array_map(fn (int $i): array => importRow(['First Name' => 'Child'.$i, 'Admission Number' => sprintf('AGA-2025-%04d', $i)]), range(1, 100));
    app(SystemSettings::class)->schoolInitials();
    DB::enableQueryLog();
    app(StudentImportValidator::class)->validate($rows, '2026-09-01');
    $queries = DB::getQueryLog();
    DB::disableQueryLog();
    expect($queries)->toHaveCount(4);
});

test('import page and every server action reject unauthorized and cross owner access', function () {
    $actor = importSetup();
    $outsider = User::factory()->create();
    $this->actingAs($outsider)->get(route('students.import'))->assertForbidden();
    $batch = app(ImportStudents::class)->prepare($actor, importWorkbook([importRow()]), '2026-09-01');
    $other = importActor();
    $this->actingAs($other)->get(route('students.import', ['batch' => $batch->id]))->assertNotFound();
    expect(fn () => Livewire::actingAs($other)->test('pages::students.import')->set('batch', $batch->id))
        ->toThrow(ModelNotFoundException::class);
    $actor->revokePermissionTo(Permissions::STUDENTS_IMPORT);
    expect(fn () => app(ImportStudents::class)->confirm($actor, $batch->id))->toThrow(AuthorizationException::class);
});

test('import form submits and scopes loading to the non reserved preview action', function () {
    $actor = importSetup();

    Livewire::actingAs($actor)->test('pages::students.import')
        ->assertSeeHtml('wire:submit="validateAndPreview"')
        ->assertSeeHtml('wire:target="validateAndPreview,workbook"')
        ->assertSeeHtml('wire:target="workbook,validateAndPreview"')
        ->assertDontSeeHtml('wire:submit="upload"');
});

test('preview validation failures clear the temporary workbook and allow a successful retry', function () {
    $actor = importSetup();
    $headers = StudentImportReader::HEADERS;
    $headers[0] = 'Unsupported';

    $component = Livewire::actingAs($actor)->test('pages::students.import')
        ->set('enrollmentDate', '2026-09-01')
        ->set('workbook', importWorkbook([importRow()], headers: $headers))
        ->call('validateAndPreview')
        ->assertHasErrors('workbook')
        ->assertSet('workbook', null)
        ->assertSet('batch', '')
        ->assertSeeHtml('wire:submit="validateAndPreview"');
    $this->assertDatabaseCount('student_imports', 0);

    $component->set('workbook', importWorkbook([importRow()]))
        ->call('validateAndPreview')->assertHasNoErrors()
        ->assertSet('workbook', null)->assertSee('Confirm import');
    $this->assertDatabaseCount('student_imports', 1);
    $this->assertDatabaseCount('students', 0);
    $this->assertDatabaseCount('guardians', 0);
    $this->assertDatabaseCount('enrollments', 0);
});

test('livewire upload preview correction confirmation and downloads work together', function () {
    $actor = importSetup();
    $component = Livewire::actingAs($actor)->test('pages::students.import')
        ->set('enrollmentDate', '2026-09-01')->set('workbook', importWorkbook([importRow(['Guardian 1 First Name' => ''])]))
        ->call('validateAndPreview')->assertHasNoErrors()->assertSet('workbook', null)->assertSee('Requires Review');
    $batch = StudentImport::query()->sole();
    $row = $batch->rows()->sole();
    $component->call('editRow', $row->id)->set('fields.12', 'Example')->call('saveRow')->assertHasNoErrors()
        ->call('confirmImport')->assertHasErrors('confirmed')
        ->set('confirmed', true)->call('confirmImport')->assertHasNoErrors()->call('processChunk')
        ->assertSee('Processing finished')->call('errorReport')->assertFileDownloaded('student-import-results.csv')
        ->call('template')->assertFileDownloaded('Student_Import_Template.xlsx');
    $this->assertDatabaseCount('students', 1);
});

test('expired previews cannot be accessed and cleanup preserves imported students', function () {
    $actor = importSetup();
    $batch = app(ImportStudents::class)->prepare($actor, importWorkbook([importRow()]), '2026-09-01');
    finishImport($actor, $batch);
    $batch->update(['expires_at' => now()->subMinute()]);
    $this->actingAs($actor)->get(route('students.import', ['batch' => $batch->id]))
        ->assertRedirect(route('students.import'))->assertSessionHas('importRecovery');
    $again = app(ImportStudents::class)->prepare($actor, importWorkbook([importRow()]), '2026-09-01');
    expect($again->rows()->sole()->status)->toBe('exists');
    $again->update(['expires_at' => now()->subMinute()]);
    $this->artisan('students:prune-imports')->assertSuccessful();
    $this->assertDatabaseCount('student_imports', 0);
    $this->assertDatabaseCount('student_import_rows', 0);
    $this->assertDatabaseCount('students', 1);
});

test('import requires each permission used to create and link student records', function (string $permission) {
    $actor = importSetup();
    $actor->revokePermissionTo($permission);

    expect(fn () => app(ImportStudents::class)->prepare($actor, importWorkbook([importRow()]), '2026-09-01'))
        ->toThrow(AuthorizationException::class);
    $this->assertDatabaseCount('student_imports', 0);
    $this->assertDatabaseCount('students', 0);
})->with([
    Permissions::STUDENTS_IMPORT, Permissions::STUDENTS_CREATE, Permissions::STUDENTS_VIEW,
    Permissions::GUARDIANS_VIEW, Permissions::GUARDIANS_CREATE, Permissions::GUARDIANS_LINK_STUDENT,
]);

test('generation never replaces a supplied number until it is explicitly cleared', function () {
    $actor = importSetup();
    $imports = app(ImportStudents::class);
    $batch = $imports->prepare($actor, importWorkbook([importRow(['Admission Number' => 'LEGACY-12'])]), '2026-09-01');
    $row = $batch->rows()->sole();

    $imports->correct($actor, $batch->id, $row->id, [], true);
    expect($row->fresh()->status)->toBe('review');
    expect($row->fresh()->payload['Admission Number'])->toBe('LEGACY-12');
    $imports->correct($actor, $batch->id, $row->id, ['Admission Number' => ''], true);
    finishImport($actor, $batch);

    expect(Student::query()->sole()->admission_number)->toBe('AGA/2025/0001');
});

test('corrected rejected rows can be imported after the valid rows have finished', function () {
    $actor = importSetup();
    $imports = app(ImportStudents::class);
    $batch = $imports->prepare($actor, importWorkbook([
        importRow(), importRow(['First Name' => 'Correctable', 'Admission Number' => 'AGA-2025-0002', 'Admission Date' => '']),
    ]), '2026-09-01');
    finishImport($actor, $batch);
    $row = $batch->rows()->where('status', 'review')->sole();

    $imports->correct($actor, $batch->id, $row->id, ['Admission Date' => '2025-09-15'], false);
    finishImport($actor, $batch);

    $this->assertDatabaseCount('students', 2);
    $this->assertDatabaseCount('guardians', 1);
    $this->assertDatabaseCount('enrollments', 2);
    expect($batch->rows()->where('status', 'imported')->count())->toBe(2);
});

test('large unicode preview values remain correctable without blocking valid students', function () {
    $actor = importSetup();
    $longText = str_repeat('😀', 5001);
    $batch = app(ImportStudents::class)->prepare($actor, importWorkbook([
        importRow(['Student Address' => $longText, 'Religion' => $longText, 'Medical Notes' => $longText]),
        importRow(['First Name' => 'Valid', 'Admission Number' => 'AGA-2025-0002']),
    ]), '2026-09-01');

    $row = $batch->rows()->orderBy('row_number')->first();
    expect($row->status)->toBe('error');
    expect(mb_strlen($row->payload['Medical Notes']))->toBe(5000);
    finishImport($actor, $batch);

    expect(Student::query()->sole()->first_name)->toBe('Valid');
    $this->assertDatabaseCount('guardians', 1);
});

test('review styling pagination and correction keep the correct row and a valid filtered page', function () {
    $actor = importSetup();
    $rows = array_map(fn (int $i): array => importRow([
        'First Name' => 'ReviewChild'.$i, 'Admission Number' => sprintf('AGA-2025-%04d', $i), 'Admission Date' => '',
    ]), range(1, 21));
    $batch = app(ImportStudents::class)->prepare($actor, importWorkbook($rows), '2026-09-01');
    $last = $batch->rows()->orderByDesc('row_number')->first();
    $component = Livewire::actingAs($actor)->test('pages::students.import')->set('batch', $batch->id)
        ->set('filter', 'review')->assertSee('ReviewChild1')->assertDontSee('ReviewChild21')
        ->assertSeeHtml('text-amber-700')->assertSeeHtml('dark:text-amber-200')
        ->call('setPage', 2)->assertSee('ReviewChild21');
    expect($component->get('rows')->total())->toBe(21);
    expect($component->get('summary')['Requires Review'])->toBe(21);

    $document = new DOMDocument;
    @$document->loadHTML($component->html());
    $buttons = new DOMXPath($document);
    $button = $buttons->query('//*[@*[name()="wire:click"]="editRow('.$last->id.')"]')->item(0);
    expect($button)->not->toBeNull();
    expect($button->getAttribute('class'))->toContain('[--color-accent:var(--color-amber-400)]', 'dark:[--color-accent-foreground:var(--color-amber-950)]', 'disabled:opacity-50');

    $component->call('editRow', $last->id)->assertSet('editingRow', $last->id)
        ->assertSet('fields.1', 'ReviewChild21')->assertSee('Excel row 22')->assertSee('Validation messages')
        ->call('saveRow')->assertSet('showEditor', true)->assertSee('remaining messages')
        ->set('fields.6', '2025-09-15')->call('saveRow')->assertHasNoErrors()
        ->assertSet('showEditor', false)->assertSet('paginators.page', 1)->assertSet('filter', 'review')
        ->assertSee('Ready to import after confirmation');
    expect($last->fresh()->status)->toBe('ready');
    expect($component->get('rows')->total())->toBe(20);
    expect($component->get('summary')['Requires Review'])->toBe(20);
    $this->assertDatabaseCount('students', 0);
    $this->assertDatabaseCount('guardians', 0);
    $this->assertDatabaseCount('enrollments', 0);
    $component->set('filter', '')->call('setPage', 2)->set('filter', 'review')->assertSet('paginators.page', 1);
});

test('review retry preserves forty imported students and their guardian links and enrollments', function () {
    $actor = importSetup();
    $rows = array_map(fn (int $i): array => importRow([
        'First Name' => 'BatchChild'.$i, 'Admission Number' => sprintf('AGA-2025-%04d', $i),
        'Admission Date' => $i <= 40 ? '2025-09-15' : '',
    ]), range(1, 51));
    $imports = app(ImportStudents::class);
    $batch = $imports->prepare($actor, importWorkbook($rows), '2026-09-01');
    finishImport($actor, $batch);
    $students = DB::table('students')->orderBy('id')->get()->toJson();
    $links = DB::table('student_guardian')->orderBy('id')->get()->toJson();
    $enrollments = DB::table('enrollments')->orderBy('id')->get()->toJson();
    $guardians = DB::table('guardians')->orderBy('id')->get()->toJson();
    $studentIds = Student::query()->pluck('id');
    $row = $batch->rows()->where('status', 'review')->orderBy('row_number')->first();

    $component = Livewire::actingAs($actor)->test('pages::students.import')->set('batch', $batch->id)->set('filter', 'review');
    expect($component->get('summary'))->toMatchArray(['Rows examined' => 51, 'Students created' => 40, 'Requires Review' => 11, 'Enrollments created' => 40, 'Guardians created' => 1, 'Existing guardians linked' => 39]);
    expect($component->get('rows')->total())->toBe(11);
    $component->call('editRow', $row->id)->set('fields.6', '2025-09-15')->call('saveRow')->assertHasNoErrors();
    expect(Student::query()->count())->toBe(40);
    $component->set('confirmed', true)->call('confirmImport')->call('processChunk')->call('processChunk');
    expect($component->get('summary'))->toMatchArray(['Rows examined' => 51, 'Students created' => 41, 'Requires Review' => 10, 'Enrollments created' => 41]);
    expect(DB::table('students')->whereIn('id', $studentIds)->orderBy('id')->get()->toJson())->toBe($students);
    expect(DB::table('student_guardian')->whereIn('student_id', $studentIds)->orderBy('id')->get()->toJson())->toBe($links);
    expect(DB::table('enrollments')->whereIn('student_id', $studentIds)->orderBy('id')->get()->toJson())->toBe($enrollments);
    expect(DB::table('guardians')->orderBy('id')->get()->toJson())->toBe($guardians);
    $again = $imports->prepare($actor, importWorkbook($rows), '2026-09-01');
    expect($again->rows()->where('status', 'exists')->count())->toBe(41);
    $this->assertDatabaseCount('students', 41);
    $this->assertDatabaseCount('enrollments', 41);
});

test('unresolved filtering separates review warnings from errors and imported success', function () {
    $actor = importSetup();
    $file = importWorkbook([
        importRow(),
        importRow(['First Name' => 'NeedsReview', 'Admission Number' => 'AGA-2025-0002', 'Admission Date' => '']),
        importRow(['First Name' => 'BadFormula', 'Admission Number' => 'AGA-2025-0003']),
    ], fn (Spreadsheet $book) => $book->getSheetByName('Student Import')->setCellValue('B4', '=1+1'));
    $batch = app(ImportStudents::class)->prepare($actor, $file, '2026-09-01');
    finishImport($actor, $batch);

    $component = Livewire::actingAs($actor)->test('pages::students.import')->set('batch', $batch->id)
        ->assertSeeHtml('text-green-800')->assertSeeHtml('text-red-700')->assertSeeHtml('dark:text-red-200');
    expect($component->get('summary'))->toMatchArray(['Students created' => 1, 'Requires Review' => 1, 'Errors / failed' => 1]);
    $component->set('filter', 'review')->assertSee('NeedsReview')->assertDontSee('formulas are not supported');
    expect($component->get('rows')->total())->toBe(1);
    $component->set('filter', 'unresolved')->assertSee('NeedsReview')->assertSee('formulas are not supported');
    expect($component->get('rows')->total())->toBe(2);
    $imported = $batch->rows()->where('status', 'imported')->sole();
    expect(fn () => app(ImportStudents::class)->correct($actor, $batch->id, $imported->id, ['First Name' => 'Changed'], false))
        ->toThrow(HttpException::class);
    expect(Student::query()->sole()->first_name)->toBe('Synthetic');
});

test('ordinary corrections keep a supplied number even after an earlier incompatible generation request', function (int $field, string $value) {
    $actor = importSetup();
    $imports = app(ImportStudents::class);
    $batch = $imports->prepare($actor, importWorkbook([importRow()]), '2026-09-01');
    $row = $batch->rows()->sole();
    $imports->correct($actor, $batch->id, $row->id, [], true);
    expect($row->fresh()->status)->toBe('review');

    Livewire::actingAs($actor)->test('pages::students.import')->set('batch', $batch->id)
        ->call('editRow', $row->id)->assertSet('generateNumber', false)
        ->set('fields.'.$field, $value)->call('saveRow')->assertHasNoErrors()->assertSet('showEditor', false);

    expect($row->fresh()->payload['Admission Number'])->toBe('AGA-2025-0001');
    expect($row->fresh()->generate_number)->toBeFalse();
    expect($row->fresh()->payload[StudentImportReader::HEADERS[$field]])->toBe($value);
    finishImport($actor, $batch);
    expect(Student::query()->sole()->admission_number)->toBe('AGA-2025-0001');
})->with(['guardian details' => [12, 'Correctedparent'], 'optional details' => [22, 'Verified medical note']]);

test('generation explicitly clears the editor only after the original admission date is verified', function () {
    $actor = importSetup();
    $batch = app(ImportStudents::class)->prepare($actor, importWorkbook([importRow(['Admission Number' => 'AGA-2025-0090'])]), '2026-09-01');
    $row = $batch->rows()->sole();
    $component = Livewire::actingAs($actor)->test('pages::students.import')->set('batch', $batch->id)->call('editRow', $row->id)
        ->assertSeeHtml('wire:confirm="Clear the admission number')
        ->call('requestNumberGeneration')->assertHasErrors('admissionDateVerified')->assertSet('fields.0', 'AGA-2025-0090')
        ->set('admissionDateVerified', true)->set('fields.6', '')
        ->call('requestNumberGeneration')->assertHasErrors('fields.6')->assertSet('generateNumber', false)
        ->set('fields.6', '2025-09-15')->call('requestNumberGeneration')->assertHasNoErrors()
        ->assertSet('generateNumber', true)->assertSet('fields.0', '');
    expect($row->fresh()->payload['Admission Number'])->toBe('AGA-2025-0090');
    $this->assertDatabaseCount('students', 0);

    $component->call('saveRow')->assertHasNoErrors();
    expect($row->fresh()->payload['Admission Number'])->toBe('');
    expect($row->fresh()->generate_number)->toBeTrue();
    finishImport($actor, $batch);
    expect(Student::query()->sole()->admission_number)->toBe('AGA/2025/0001');
});

test('generation requires reverification after changing the date and can be cancelled without losing other corrections', function () {
    $actor = importSetup();
    $batch = app(ImportStudents::class)->prepare($actor, importWorkbook([importRow()]), '2026-09-01');
    $row = $batch->rows()->sole();
    Livewire::actingAs($actor)->test('pages::students.import')->set('batch', $batch->id)->call('editRow', $row->id)
        ->set('admissionDateVerified', true)->call('requestNumberGeneration')
        ->set('fields.6', '2024-09-15')->call('saveRow')->assertHasErrors('fields.6')
        ->set('fields.6', '2025-09-15')->set('fields.22', 'Verified correction')
        ->call('cancelNumberGeneration')->assertSet('generateNumber', false)
        ->assertSet('fields.0', 'AGA-2025-0001')->assertSet('fields.22', 'Verified correction')
        ->call('saveRow')->assertHasNoErrors();
    expect($row->fresh()->payload['Admission Number'])->toBe('AGA-2025-0001');
    expect($row->fresh()->generate_number)->toBeFalse();
    $this->assertDatabaseCount('students', 0);
});

test('correcting a number cannot claim an imported students admission number', function () {
    $actor = importSetup();
    $imports = app(ImportStudents::class);
    $batch = $imports->prepare($actor, importWorkbook([importRow()]), '2026-09-01');
    finishImport($actor, $batch);
    $other = $imports->prepare($actor, importWorkbook([importRow(['First Name' => 'Differentchild', 'Admission Number' => 'AGA-2025-0002'])]), '2026-09-01');
    $row = $other->rows()->sole();
    Livewire::actingAs($actor)->test('pages::students.import')->set('batch', $other->id)->call('editRow', $row->id)
        ->set('fields.0', 'AGA/2025/0001')->call('saveRow')->assertSet('showEditor', true)
        ->assertSee('Admission number belongs to a different student candidate');
    expect($row->fresh()->status)->toBe('review');
    expect(fn () => $imports->confirm($actor, $other->id))->toThrow(ValidationException::class);
    expect(Student::query()->sole()->admission_number)->toBe('AGA-2025-0001');
});
