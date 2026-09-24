<?php

use App\Actions\Assessments\SaveAssessmentScores;
use App\EnrollmentStatus;
use App\Models\AcademicYear;
use App\Models\Assessment;
use App\Models\AssessmentScore;
use App\Models\ClassLevel;
use App\Models\ClassSubject;
use App\Models\Enrollment;
use App\Models\Student;
use App\Models\Subject;
use App\Models\Term;
use App\Models\User;
use App\Support\Academic\ResultCalculator;
use App\Support\Authorization\Permissions;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;

/** @param list<string> $permissions */
function resultActor(array $permissions = [Permissions::ASSESSMENTS_VIEW, Permissions::ASSESSMENTS_RECORD_SCORES, Permissions::ASSESSMENTS_MANAGE_ALL, Permissions::RESULTS_VIEW, Permissions::RESULTS_VIEW_ALL]): User
{
    foreach ($permissions as $permission) {
        Permission::findOrCreate($permission, 'web');
    }

    $user = User::factory()->create();
    $user->givePermissionTo($permissions);

    return $user;
}

/** @return array{AcademicYear, Term, ClassLevel, Subject, Assessment} */
function resultContext(?User $teacher = null, array $assessmentAttributes = []): array
{
    $year = AcademicYear::factory()->create();
    $term = Term::factory()->for($year)->create();
    $class = ClassLevel::factory()->create();
    $subject = Subject::factory()->create();
    ClassSubject::factory()->for($year)->for($class)->for($subject)->create(['staff_id' => $teacher?->id]);
    $assessment = Assessment::factory()->create([
        'academic_year_id' => $year->id,
        'term_id' => $term->id,
        'class_level_id' => $class->id,
        'subject_id' => $subject->id,
        ...$assessmentAttributes,
    ]);

    return [$year, $term, $class, $subject, $assessment];
}

test('score register loads only the assessment historical roster and existing scores', function () {
    [$year, $term, $class, $subject, $assessment] = resultContext(null, ['assessment_date' => today()]);
    $eligible = Enrollment::factory()->for($year)->for($class)->create(['enrollment_date' => today()->subDay()]);
    $future = Enrollment::factory()->for($year)->for($class)->create(['enrollment_date' => today()->addDay()]);
    $otherClass = Enrollment::factory()->for($year)->create();
    $otherYear = Enrollment::factory()->for($class)->create();
    AssessmentScore::factory()->for($assessment)->for($eligible)->create(['score' => '12.50']);

    Livewire::actingAs(resultActor())->test('pages::assessments.scores', ['assessment' => $assessment])
        ->assertSet('rows.'.$eligible->id.'.score', '12.50')
        ->assertSee($eligible->student->admission_number)
        ->assertDontSee($future->student->admission_number)
        ->assertDontSee($otherClass->student->admission_number)
        ->assertDontSee($otherYear->student->admission_number);
});

test('scores save in bulk update without duplication and preserve zero and missing', function () {
    [$year, $term, $class, $subject, $assessment] = resultContext();
    $enrollments = Enrollment::factory()->count(3)->for($year)->for($class)->create();
    AssessmentScore::factory()->for($assessment)->for($enrollments[0])->create(['score' => '5.00']);
    $rows = [
        ['enrollment_id' => $enrollments[0]->id, 'score' => '15'],
        ['enrollment_id' => $enrollments[1]->id, 'score' => '0'],
        ['enrollment_id' => $enrollments[2]->id, 'score' => ''],
    ];

    app(SaveAssessmentScores::class)->handle(resultActor(), $assessment->id, $rows);

    $this->assertDatabaseCount('assessment_scores', 2);
    $this->assertDatabaseHas('assessment_scores', ['assessment_id' => $assessment->id, 'enrollment_id' => $enrollments[0]->id, 'score' => 15]);
    $this->assertDatabaseHas('assessment_scores', ['assessment_id' => $assessment->id, 'enrollment_id' => $enrollments[1]->id, 'score' => 0]);
    $this->assertDatabaseMissing('assessment_scores', ['assessment_id' => $assessment->id, 'enrollment_id' => $enrollments[2]->id]);
});

test('clearing an existing score keeps an explicit missing record without converting it to zero', function () {
    [$year, $term, $class, $subject, $assessment] = resultContext();
    $enrollment = Enrollment::factory()->for($year)->for($class)->create();
    $score = AssessmentScore::factory()->for($assessment)->for($enrollment)->create(['score' => '8.00']);

    app(SaveAssessmentScores::class)->handle(resultActor(), $assessment->id, [['enrollment_id' => $enrollment->id, 'score' => '']]);

    expect($score->fresh()->score)->toBeNull();
});

test('server rejects invalid scores and enrollment injection', function (mixed $score, bool $injectEnrollment = false) {
    [$year, $term, $class, $subject, $assessment] = resultContext();
    $eligible = Enrollment::factory()->for($year)->for($class)->create();
    $other = Enrollment::factory()->for($year)->create();
    $rows = [['enrollment_id' => $injectEnrollment ? $other->id : $eligible->id, 'score' => $score]];

    expect(fn () => app(SaveAssessmentScores::class)->handle(resultActor(), $assessment->id, $rows))->toThrow(ValidationException::class);
    $this->assertDatabaseCount('assessment_scores', 0);
})->with([
    'negative' => [-0.01],
    'above maximum' => [20.01],
    'not numeric' => ['absent'],
    'excess precision' => ['1.999'],
    'different class enrollment' => [10, true],
]);

test('teacher assignment is rechecked when scores are saved', function () {
    $teacher = resultActor([Permissions::ASSESSMENTS_VIEW, Permissions::ASSESSMENTS_RECORD_SCORES]);
    [$year, $term, $class, $subject, $assessment] = resultContext($teacher);
    $enrollment = Enrollment::factory()->for($year)->for($class)->create();
    ClassSubject::query()->where('staff_id', $teacher->id)->delete();

    expect(fn () => app(SaveAssessmentScores::class)->handle($teacher, $assessment->id, [['enrollment_id' => $enrollment->id, 'score' => 10]]))->toThrow(AuthorizationException::class);
    $this->assertDatabaseCount('assessment_scores', 0);
});

test('database prevents duplicate assessment and enrollment score pairs', function () {
    [$year, $term, $class, $subject, $assessment] = resultContext();
    $enrollment = Enrollment::factory()->for($year)->for($class)->create();
    AssessmentScore::factory()->for($assessment)->for($enrollment)->create();

    expect(fn () => AssessmentScore::factory()->for($assessment)->for($enrollment)->create())->toThrow(QueryException::class);
});

test('subject results separate terms years and subjects and report incomplete scores', function () {
    [$year, $term, $class, $subject, $first] = resultContext(null, ['maximum_score' => '20']);
    $second = Assessment::factory()->create(['academic_year_id' => $year->id, 'term_id' => $term->id, 'class_level_id' => $class->id, 'subject_id' => $subject->id, 'maximum_score' => '30']);
    $otherTerm = Term::factory()->for($year)->second()->create();
    $wrongTerm = Assessment::factory()->create(['academic_year_id' => $year->id, 'term_id' => $otherTerm->id, 'class_level_id' => $class->id, 'subject_id' => $subject->id, 'maximum_score' => '100']);
    $otherSubject = Subject::factory()->create();
    ClassSubject::factory()->for($year)->for($class)->for($otherSubject)->create();
    $wrongSubject = Assessment::factory()->create(['academic_year_id' => $year->id, 'term_id' => $term->id, 'class_level_id' => $class->id, 'subject_id' => $otherSubject->id, 'maximum_score' => '100']);
    $enrollment = Enrollment::factory()->for($year)->for($class)->create();
    AssessmentScore::factory()->for($first)->for($enrollment)->create(['score' => '15']);
    AssessmentScore::factory()->for($wrongTerm)->for($enrollment)->create(['score' => '99']);
    AssessmentScore::factory()->for($wrongSubject)->for($enrollment)->create(['score' => '90']);

    $result = app(ResultCalculator::class)->forClass(resultActor(), $year->id, $term->id, $class->id, collect([$enrollment]));
    $subjectResult = $result[$enrollment->id]['subjects'][$subject->id];

    expect($subjectResult['earned'])->toEqual(15.0)
        ->and($subjectResult['maximum'])->toEqual(50.0)
        ->and($subjectResult['missing'])->toBe(1)
        ->and($subjectResult['percentage'])->toBeNull()
        ->and($result[$enrollment->id]['complete'])->toBeFalse();
});

test('complete subject and class percentages use assessment maximum totals', function () {
    [$year, $term, $class, $subject, $first] = resultContext(null, ['maximum_score' => '20']);
    $second = Assessment::factory()->create(['academic_year_id' => $year->id, 'term_id' => $term->id, 'class_level_id' => $class->id, 'subject_id' => $subject->id, 'maximum_score' => '30']);
    $enrollment = Enrollment::factory()->for($year)->for($class)->create();
    AssessmentScore::factory()->for($first)->for($enrollment)->create(['score' => '15']);
    AssessmentScore::factory()->for($second)->for($enrollment)->create(['score' => '20']);

    $result = app(ResultCalculator::class)->forClass(resultActor(), $year->id, $term->id, $class->id, collect([$enrollment]))[$enrollment->id];

    expect($result['earned'])->toEqual(35.0)
        ->and($result['maximum'])->toEqual(50.0)
        ->and($result['percentage'])->toEqual(70.0)
        ->and($result['complete'])->toBeTrue();
});

test('historical results remain attached to the original enrollment after promotion', function () {
    [$year, $term, $class, $subject, $assessment] = resultContext();
    $student = Student::factory()->create();
    $historical = Enrollment::factory()->for($student)->for($year)->for($class)->create();
    AssessmentScore::factory()->for($assessment)->for($historical)->create(['score' => '18']);
    $historical->update(['status' => EnrollmentStatus::Promoted]);
    Enrollment::factory()->for($student)->create();

    $result = app(ResultCalculator::class)->forClass(resultActor(), $year->id, $term->id, $class->id, collect([$historical]))[$historical->id];

    expect($result['earned'])->toEqual(18.0)
        ->and($historical->fresh()->class_level_id)->toBe($class->id)
        ->and($historical->assessmentScores()->count())->toBe(1);
});

test('historical enrollments remain visible when their results have no scores', function () {
    [$year, $term, $class] = resultContext();
    $historical = Enrollment::factory()->for($year)->for($class)->create(['status' => EnrollmentStatus::Promoted]);

    Livewire::actingAs(resultActor())->test('pages::results.index')
        ->set('academicYearId', (string) $year->id)
        ->set('termId', (string) $term->id)
        ->set('classLevelId', (string) $class->id)
        ->assertSee($historical->student->admission_number)
        ->assertSee('1 missing scores');
});

test('class and individual result filters use the selected historical enrollment', function () {
    [$year, $term, $class, $subject, $assessment] = resultContext();
    $enrollment = Enrollment::factory()->for($year)->for($class)->create();
    AssessmentScore::factory()->for($assessment)->for($enrollment)->create(['score' => '10']);
    $user = resultActor();

    Livewire::actingAs($user)->test('pages::results.index')
        ->set('academicYearId', (string) $year->id)
        ->set('termId', (string) $term->id)
        ->set('classLevelId', (string) $class->id)
        ->assertSee($enrollment->student->admission_number)
        ->assertSee('10 / 20');
    Livewire::test('pages::results.student', ['student' => $enrollment->student])
        ->set('academicYearId', (string) $year->id)
        ->set('termId', (string) $term->id)
        ->set('enrollmentId', (string) $enrollment->id)
        ->assertSee($class->name)
        ->assertSee('10 / 20');
});

test('bulk score persistence uses bounded queries and one upsert', function () {
    [$year, $term, $class, $subject, $assessment] = resultContext();
    $enrollments = Enrollment::factory()->count(40)->for($year)->for($class)->create();
    $rows = $enrollments->map(fn (Enrollment $enrollment): array => ['enrollment_id' => $enrollment->id, 'score' => 10])->all();
    $user = resultActor();
    DB::enableQueryLog();
    DB::flushQueryLog();

    try {
        app(SaveAssessmentScores::class)->handle($user, $assessment->id, $rows);
        $queries = collect(DB::getQueryLog());
    } finally {
        DB::disableQueryLog();
    }

    expect($queries->count())->toBeLessThan(20)
        ->and($queries->filter(fn (array $query): bool => str_starts_with($query['query'], 'insert into '.DB::connection()->getQueryGrammar()->wrapTable('assessment_scores')))->count())->toBe(1);
    $this->assertDatabaseCount('assessment_scores', 40);
});

test('score roster and class results avoid per student queries', function () {
    [$year, $term, $class, $subject, $assessment] = resultContext();
    $enrollments = Enrollment::factory()->count(20)->for($year)->for($class)->create();
    foreach ($enrollments as $enrollment) {
        AssessmentScore::factory()->for($assessment)->for($enrollment)->create();
    }
    $user = resultActor();
    $this->actingAs($user);
    DB::enableQueryLog();
    DB::flushQueryLog();

    try {
        Livewire::test('pages::assessments.scores', ['assessment' => $assessment])->assertSee($enrollments->first()->student->admission_number);
        $rosterQueries = collect(DB::getQueryLog());
        DB::flushQueryLog();
        app(ResultCalculator::class)->forClass($user, $year->id, $term->id, $class->id, $enrollments);
        $resultQueries = collect(DB::getQueryLog());
    } finally {
        DB::disableQueryLog();
    }

    expect($rosterQueries->filter(fn (array $query): bool => str_contains($query['query'], 'from "students"'))->count())->toBeLessThanOrEqual(2)
        ->and($resultQueries->count())->toBeLessThan(12);
});
