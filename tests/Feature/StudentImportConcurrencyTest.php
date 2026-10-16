<?php

use App\Actions\Students\ImportStudents;
use App\Models\AcademicYear;
use App\Models\ClassLevel;
use App\Models\SchoolSetting;
use App\Models\Student;
use App\Models\StudentImport;
use App\Models\User;
use App\Support\Authorization\Permissions;
use App\Support\Settings\SystemSettings;
use App\Support\StudentImportReader;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;

test('guests must sign in before accessing student import previews', function () {
    $this->get(route('students.import'))->assertRedirect(route('home'));
});

test('mysql serializes competing import batches and rechecks student and guardian identities', function () {
    if (! filter_var(getenv('STUDENT_IMPORT_MYSQL_TESTS'), FILTER_VALIDATE_BOOLEAN)) {
        $this->markTestSkipped('Set STUDENT_IMPORT_MYSQL_TESTS=1 for isolated MySQL contention tests.');
    }

    $originalConnection = DB::getDefaultConnection();
    $database = 'student_import_test_'.bin2hex(random_bytes(8));
    $server = array_replace(config('database.connections.mysql'), ['url' => null, 'database' => null]);
    config(['database.connections.import_server' => $server]);
    DB::connection('import_server')->statement("CREATE DATABASE `$database`");

    try {
        $connection = array_replace($server, ['database' => $database]);
        config(['database.connections.import_writer' => $connection, 'database.connections.import_contender' => $connection]);
        DB::setDefaultConnection('import_writer');
        expect(Artisan::call('migrate', ['--database' => 'import_writer', '--force' => true, '--no-interaction' => true]))->toBe(0);
        SchoolSetting::factory()->create(['id' => 1, 'school_initials' => 'AGA']);
        app(SystemSettings::class)->forget();
        AcademicYear::factory()->create(['name' => '2025/2026']);
        ClassLevel::factory()->create(['name' => 'Nursery 2', 'is_active' => true]);
        $actor = User::factory()->create();
        foreach ([Permissions::STUDENTS_IMPORT, Permissions::STUDENTS_CREATE, Permissions::STUDENTS_VIEW, Permissions::GUARDIANS_VIEW, Permissions::GUARDIANS_CREATE, Permissions::GUARDIANS_LINK_STUDENT] as $permission) {
            Permission::findOrCreate($permission, 'web');
            $actor->givePermissionTo($permission);
        }
        $payload = array_replace(array_fill_keys(StudentImportReader::HEADERS, ''), [
            'Admission Number' => 'AGA-2025-0001', 'First Name' => 'Synthetic', 'Last Name' => 'Student',
            'Gender' => 'female', 'Admission Date' => '2025-01-01', 'Academic Year' => '2025/2026',
            'Class Level' => 'Nursery 2', 'Enrollment Status' => 'active',
            'Guardian 1 First Name' => 'Synthetic', 'Guardian 1 Last Name' => 'Guardian',
            'Guardian 1 Relationship' => 'Mother', 'Guardian 1 Phone' => '0240000001',
        ]);
        $batches = [];
        foreach (range(1, 3) as $index) {
            $batch = StudentImport::query()->create(['user_id' => $actor->id, 'status' => 'preview', 'enrollment_date' => '2025-09-01', 'expires_at' => now()->addDay()]);
            $batch->rows()->create(['row_number' => 2, 'payload' => $index === 3 ? array_replace($payload, ['First Name' => 'Sibling', 'Admission Number' => 'AGA-2025-0002']) : $payload, 'status' => 'ready', 'messages' => []]);
            app(ImportStudents::class)->confirm($actor, $batch->id);
            $batches[] = $batch;
        }
        $writer = DB::connection('import_writer');
        $writer->beginTransaction();
        app(ImportStudents::class)->process($actor, $batches[0]->id);

        DB::setDefaultConnection('import_contender');
        DB::statement('SET SESSION innodb_lock_wait_timeout = 1');
        $contender = User::query()->findOrFail($actor->id);
        try {
            app(ImportStudents::class)->process($contender, $batches[1]->id);
            $this->fail('A second import must wait for the identity lock.');
        } catch (QueryException $exception) {
            expect($exception->errorInfo[1])->toBe(1205);
        }
        $writer->commit();

        app(ImportStudents::class)->process($contender, $batches[1]->id);
        app(ImportStudents::class)->process($contender, $batches[2]->id);
        app(ImportStudents::class)->process($contender, $batches[0]->id);
        expect(StudentImport::query()->findOrFail($batches[1]->id)->rows()->sole()->status)->toBe('exists');
        $this->assertDatabaseCount('students', 2);
        $this->assertDatabaseCount('guardians', 1);
        $this->assertDatabaseCount('enrollments', 2);
        $this->assertDatabaseCount('student_guardian', 2);
        expect(Student::query()->orderBy('id')->pluck('admission_number')->all())->toBe(['AGA-2025-0001', 'AGA-2025-0002']);
    } finally {
        foreach (['import_writer', 'import_contender'] as $name) {
            DB::purge($name);
        }
        DB::setDefaultConnection($originalConnection);
        DB::connection('import_server')->statement("DROP DATABASE `$database`");
        DB::purge('import_server');
        app(SystemSettings::class)->forget();
    }
});
