<?php

use App\Actions\Assessments\DeleteAssessment;
use App\Actions\Assessments\SaveAssessment;
use App\Models\AcademicYear;
use App\Models\Assessment;
use App\Models\AssessmentScore;
use App\Models\ClassLevel;
use App\Models\ClassSubject;
use App\Models\Subject;
use App\Models\Term;
use App\Models\User;
use App\Policies\AssessmentPolicy;
use App\Support\Authorization\Permissions;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;

/** @param list<string> $permissions */
function assessmentActor(array $permissions): User
{
    foreach ($permissions as $permission) {
        Permission::findOrCreate($permission, 'web');
    }

    $user = User::factory()->create();
    $user->givePermissionTo($permissions);

    return $user;
}

/** @return array{AcademicYear, Term, ClassLevel, Subject, ClassSubject} */
function assessmentContext(?User $teacher = null): array
{
    $year = AcademicYear::factory()->create();
    $term = Term::factory()->for($year)->create();
    $class = ClassLevel::factory()->create();
    $subject = Subject::factory()->create();
    $assignment = ClassSubject::factory()->for($year)->for($class)->for($subject)->create(['staff_id' => $teacher?->id]);

    return [$year, $term, $class, $subject, $assignment];
}

/** @return array<string, mixed> */
function assessmentData(AcademicYear $year, Term $term, ClassLevel $class, Subject $subject): array
{
    return [
        'academic_year_id' => $year->id,
        'term_id' => $term->id,
        'class_level_id' => $class->id,
        'subject_id' => $subject->id,
        'name' => 'Mid-term test',
        'type' => 'test',
        'maximum_score' => '20',
        'assessment_date' => today()->toDateString(),
        'status' => 'open',
    ];
}

test('assessment routes require authentication and their specific permissions', function () {
    $assessment = Assessment::factory()->create();

    $this->get(route('assessments.index'))->assertRedirect(route('home'));
    $this->actingAs(User::factory()->create())->get(route('assessments.index'))->assertForbidden();
    $this->actingAs(assessmentActor([Permissions::ASSESSMENTS_VIEW]))->get(route('assessments.index'))->assertSee('Assessments');
    $this->get(route('assessments.create'))->assertForbidden();
    $this->get(route('assessments.edit', $assessment))->assertForbidden();
});

test('assessment policy keeps management permission and assignment scoped', function () {
    $teacher = assessmentActor([Permissions::ASSESSMENTS_VIEW, Permissions::ASSESSMENTS_CREATE, Permissions::ASSESSMENTS_UPDATE, Permissions::ASSESSMENTS_DELETE, Permissions::ASSESSMENTS_RECORD_SCORES]);
    [$year, $term, $class, $subject] = assessmentContext($teacher);
    $assessment = Assessment::factory()->create(assessmentData($year, $term, $class, $subject));
    $other = Assessment::factory()->create();
    $policy = new AssessmentPolicy;

    expect($policy->viewAny($teacher))->toBeTrue()
        ->and($policy->create($teacher))->toBeTrue()
        ->and($policy->view($teacher, $assessment))->toBeTrue()
        ->and($policy->update($teacher, $assessment))->toBeTrue()
        ->and($policy->delete($teacher, $assessment))->toBeTrue()
        ->and($policy->recordScores($teacher, $assessment))->toBeTrue()
        ->and($policy->view($teacher, $other))->toBeFalse()
        ->and($policy->update($teacher, $other))->toBeFalse();
});

test('authorized users create and update assessments in assigned academic contexts', function () {
    $teacher = assessmentActor([Permissions::ASSESSMENTS_VIEW, Permissions::ASSESSMENTS_CREATE, Permissions::ASSESSMENTS_UPDATE]);
    [$year, $term, $class, $subject] = assessmentContext($teacher);

    $assessment = app(SaveAssessment::class)->handle($teacher, assessmentData($year, $term, $class, $subject));
    $updated = app(SaveAssessment::class)->handle($teacher, [...assessmentData($year, $term, $class, $subject), 'name' => 'Revised test', 'maximum_score' => '30'], $assessment->id);

    expect($updated->name)->toBe('Revised test')->and($updated->maximum_score)->toBe('30.00');
    $this->assertDatabaseCount('assessments', 1);
});

test('assessment form rejects invalid academic combinations and maximum scores', function (string $field, Closure $invalidValue) {
    $user = assessmentActor([Permissions::ASSESSMENTS_VIEW, Permissions::ASSESSMENTS_CREATE, Permissions::ASSESSMENTS_MANAGE_ALL]);
    [$year, $term, $class, $subject] = assessmentContext();
    $data = assessmentData($year, $term, $class, $subject);
    $data[$field] = $invalidValue();

    expect(fn () => app(SaveAssessment::class)->handle($user, $data))->toThrow(ValidationException::class);
    $this->assertDatabaseCount('assessments', 0);
})->with([
    'term from another academic year' => ['term_id', fn (): int => Term::factory()->create()->id],
    'unassigned subject' => ['subject_id', fn (): int => Subject::factory()->create()->id],
    'zero maximum' => ['maximum_score', fn (): int => 0],
    'negative maximum' => ['maximum_score', fn (): int => -1],
    'excess precision' => ['maximum_score', fn (): string => '10.999'],
]);

test('teacher cannot create or manage an assessment outside an assigned subject', function () {
    $teacher = assessmentActor([Permissions::ASSESSMENTS_VIEW, Permissions::ASSESSMENTS_CREATE, Permissions::ASSESSMENTS_UPDATE]);
    [$year, $term, $class, $subject] = assessmentContext();
    $assessment = Assessment::factory()->create(assessmentData($year, $term, $class, $subject));

    expect(fn () => app(SaveAssessment::class)->handle($teacher, assessmentData($year, $term, $class, $subject)))->toThrow(ValidationException::class)
        ->and(fn () => app(SaveAssessment::class)->handle($teacher, assessmentData($year, $term, $class, $subject), $assessment->id))->toThrow(AuthorizationException::class);
});

test('revoked teacher assignment immediately prevents assessment updates', function () {
    $teacher = assessmentActor([Permissions::ASSESSMENTS_VIEW, Permissions::ASSESSMENTS_UPDATE]);
    [$year, $term, $class, $subject, $assignment] = assessmentContext($teacher);
    $assessment = Assessment::factory()->create(assessmentData($year, $term, $class, $subject));
    $assignment->delete();

    expect(fn () => app(SaveAssessment::class)->handle($teacher, assessmentData($year, $term, $class, $subject), $assessment->id))->toThrow(AuthorizationException::class);
});

test('assessments with scores cannot be deleted or have result context changed', function () {
    $user = assessmentActor([Permissions::ASSESSMENTS_VIEW, Permissions::ASSESSMENTS_UPDATE, Permissions::ASSESSMENTS_DELETE, Permissions::ASSESSMENTS_MANAGE_ALL]);
    $assessment = Assessment::factory()->create();
    AssessmentScore::factory()->for($assessment)->create();

    expect(fn () => app(DeleteAssessment::class)->handle($user, $assessment->id))->toThrow(ValidationException::class)
        ->and(fn () => $assessment->update(['maximum_score' => '50']))->toThrow(ValidationException::class);
    $this->assertModelExists($assessment);
});

test('scoreless assessments can be safely deleted by an authorized user', function () {
    $user = assessmentActor([Permissions::ASSESSMENTS_VIEW, Permissions::ASSESSMENTS_DELETE, Permissions::ASSESSMENTS_MANAGE_ALL]);
    $assessment = Assessment::factory()->create();

    app(DeleteAssessment::class)->handle($user, $assessment->id);

    $this->assertModelMissing($assessment);
});

test('assessment index filters by historical academic context', function () {
    $user = assessmentActor([Permissions::ASSESSMENTS_VIEW, Permissions::ASSESSMENTS_MANAGE_ALL]);
    $assessment = Assessment::factory()->create(['name' => 'Visible mathematics test']);
    Assessment::factory()->create(['name' => 'Other assessment']);

    Livewire::actingAs($user)->test('pages::assessments.index')
        ->set('academicYearId', (string) $assessment->academic_year_id)
        ->set('termId', (string) $assessment->term_id)
        ->set('classLevelId', (string) $assessment->class_level_id)
        ->set('subjectId', (string) $assessment->subject_id)
        ->assertSee('Visible mathematics test')
        ->assertDontSee('Other assessment');
});
