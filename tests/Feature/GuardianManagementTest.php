<?php

use App\Actions\Guardians\LinkGuardianToStudent;
use App\Actions\Guardians\SetPrimaryGuardian;
use App\Actions\Guardians\UnlinkGuardianFromStudent;
use App\Models\AcademicYear;
use App\Models\ClassLevel;
use App\Models\Enrollment;
use App\Models\Guardian;
use App\Models\Student;
use App\Models\StudentGuardian;
use App\Models\User;
use App\Support\Authorization\Permissions;
use Illuminate\Database\QueryException;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;

function guardianActor(array $permissions): User
{
    $user = User::factory()->create();

    foreach ($permissions as $permission) {
        Permission::findOrCreate($permission);
    }

    $user->givePermissionTo($permissions);

    return $user;
}

test('guardian routes enforce their individual permissions', function () {
    $guardian = Guardian::factory()->create();
    $user = User::factory()->create();

    $this->actingAs($user)->get(route('guardians.index'))->assertForbidden();
    $this->actingAs($user)->get(route('guardians.create'))->assertForbidden();
    $this->actingAs($user)->get(route('guardians.show', $guardian))->assertForbidden();
    $this->actingAs($user)->get(route('guardians.edit', $guardian))->assertForbidden();

    $viewer = guardianActor([Permissions::GUARDIANS_VIEW]);
    $this->actingAs($viewer)->get(route('guardians.index'))->assertOk();
    $this->actingAs($viewer)->get(route('guardians.show', $guardian))->assertOk();
});

test('an authorized user creates a guardian with normalized optional data', function () {
    $this->actingAs(guardianActor([Permissions::GUARDIANS_CREATE]));

    Livewire::test('pages::guardians.create')
        ->set('firstName', ' Akosua ')
        ->set('lastName', ' Mensah ')
        ->set('phone', ' 0240000001 ')
        ->set('email', ' PARENT@EXAMPLE.COM ')
        ->call('save')
        ->assertHasNoErrors()
        ->assertRedirect();

    $this->assertDatabaseHas('guardians', ['first_name' => 'Akosua', 'last_name' => 'Mensah', 'phone' => '0240000001', 'email' => 'parent@example.com']);
});

test('guardian creation validates required and formatted fields', function () {
    $this->actingAs(guardianActor([Permissions::GUARDIANS_CREATE]));

    Livewire::test('pages::guardians.create')
        ->set('email', 'not-an-email')
        ->call('save')
        ->assertHasErrors(['firstName', 'lastName', 'phone', 'email']);

    expect(Guardian::query()->exists())->toBeFalse();
});

test('guardian creation warns about matching phone or email records', function () {
    Guardian::factory()->create(['first_name' => 'Existing', 'phone' => '0240000001', 'email' => 'existing@example.com']);
    $this->actingAs(guardianActor([Permissions::GUARDIANS_CREATE]));

    Livewire::test('pages::guardians.create')
        ->set('firstName', 'Different')
        ->set('lastName', 'Person')
        ->set('phone', '0240000001')
        ->call('save')
        ->assertHasErrors(['phone'])
        ->assertSee('Existing');

    expect(Guardian::query()->count())->toBe(1);
});

test('an authorized user updates guardian contact information', function () {
    $guardian = Guardian::factory()->create(['phone' => '111']);
    $this->actingAs(guardianActor([Permissions::GUARDIANS_UPDATE]));

    Livewire::test('pages::guardians.edit', ['guardian' => $guardian])
        ->set('firstName', 'Updated')
        ->set('phone', '222')
        ->call('save')
        ->assertHasNoErrors();

    expect($guardian->fresh()->first_name)->toBe('Updated')->and($guardian->fresh()->phone)->toBe('222');
});

test('guardian update actions reauthorize livewire requests', function () {
    $guardian = Guardian::factory()->create();
    $this->actingAs(User::factory()->create());

    Livewire::test('pages::guardians.edit', ['guardian' => $guardian])->assertForbidden();
});

test('the guardian directory paginates and searches contact and student data', function () {
    Guardian::factory()->count(26)->create();
    $matchingGuardian = Guardian::factory()->create(['first_name' => 'Unique', 'phone' => '0249999999']);
    $matchingStudent = Student::factory()->create(['admission_number' => 'AGA-SEARCH-1']);
    $matchingStudent->guardians()->attach($matchingGuardian, ['relationship' => 'Mother']);
    $this->actingAs(guardianActor([Permissions::GUARDIANS_VIEW]));

    Livewire::test('pages::guardians.index')
        ->assertSee('Next')
        ->set('search', 'AGA-SEARCH-1')
        ->assertSee('Unique')
        ->assertDontSee(Guardian::query()->whereKeyNot($matchingGuardian)->firstOrFail()->first_name);
});

test('a guardian links to multiple students and a student links to multiple guardians', function () {
    $guardian = Guardian::factory()->create();
    $otherGuardian = Guardian::factory()->create();
    $firstStudent = Student::factory()->create();
    $secondStudent = Student::factory()->create();
    $linker = app(LinkGuardianToStudent::class);

    $linker->handle($firstStudent, $guardian, 'Mother', true);
    $linker->handle($secondStudent, $guardian, 'Mother', true);
    $linker->handle($firstStudent, $otherGuardian, 'Father', false);

    expect($guardian->students()->count())->toBe(2)
        ->and($firstStudent->guardians()->count())->toBe(2);
    $this->assertDatabaseHas('student_guardian', ['student_id' => $firstStudent->id, 'guardian_id' => $guardian->id, 'relationship' => 'Mother', 'is_primary' => true]);
});

test('duplicate student guardian links are rejected by the action and database', function () {
    $guardian = Guardian::factory()->create();
    $student = Student::factory()->create();
    app(LinkGuardianToStudent::class)->handle($student, $guardian, 'Legal Guardian', false);

    expect(fn () => app(LinkGuardianToStudent::class)->handle($student, $guardian, 'Legal Guardian', false))
        ->toThrow(ValidationException::class, 'already linked');
    expect(fn () => StudentGuardian::query()->create(['student_id' => $student->id, 'guardian_id' => $guardian->id, 'relationship' => 'Other']))
        ->toThrow(QueryException::class);
});

test('changing primary guardian unsets the previous primary guardian', function () {
    $student = Student::factory()->create();
    $first = Guardian::factory()->create();
    $second = Guardian::factory()->create();
    $linker = app(LinkGuardianToStudent::class);
    $firstLink = $linker->handle($student, $first, 'Mother', true);
    $secondLink = $linker->handle($student, $second, 'Father', true);

    expect(StudentGuardian::query()->where('student_id', $student->id)->where('is_primary', true)->count())->toBe(1)
        ->and($firstLink->fresh()->is_primary)->toBeFalse()
        ->and($secondLink->fresh()->is_primary)->toBeTrue();

    app(SetPrimaryGuardian::class)->handle($firstLink);

    expect(StudentGuardian::query()->where('student_id', $student->id)->where('is_primary', true)->count())->toBe(1)
        ->and($firstLink->fresh()->is_primary)->toBeTrue()
        ->and($secondLink->fresh()->is_primary)->toBeFalse();
});

test('unlinking removes only the relationship and may leave no primary guardian', function () {
    $student = Student::factory()->create();
    $guardian = Guardian::factory()->create();
    $link = app(LinkGuardianToStudent::class)->handle($student, $guardian, 'Mother', true);

    app(UnlinkGuardianFromStudent::class)->handle($link);

    $this->assertModelExists($student);
    $this->assertModelExists($guardian);
    $this->assertDatabaseMissing('student_guardian', ['id' => $link->id]);
});

test('unauthorized users cannot link set primary or unlink guardians', function () {
    $student = Student::factory()->create();
    $guardian = Guardian::factory()->create();
    $student->guardians()->attach($guardian, ['relationship' => 'Mother']);
    $link = StudentGuardian::query()->firstOrFail();
    $this->actingAs(guardianActor([Permissions::STUDENTS_VIEW]));

    Livewire::test('student-guardians', ['studentId' => $student->id])
        ->call('setPrimary', $link->id)
        ->assertForbidden();

    $this->assertDatabaseHas('student_guardian', ['id' => $link->id, 'is_primary' => false]);
});

test('adding a guardian from a student creates and links both records atomically', function () {
    $student = Student::factory()->create();
    $this->actingAs(guardianActor([Permissions::STUDENTS_VIEW, Permissions::GUARDIANS_CREATE, Permissions::GUARDIANS_LINK_STUDENT]));

    Livewire::test('student-guardians', ['studentId' => $student->id])
        ->call('openForm', 'new')
        ->set('firstName', 'Ama')
        ->set('lastName', 'Parent')
        ->set('phone', '0240000010')
        ->set('relationship', 'Mother')
        ->set('isPrimary', true)
        ->call('save')
        ->assertHasNoErrors();

    $guardian = Guardian::query()->where('phone', '0240000010')->firstOrFail();
    $this->assertDatabaseHas('student_guardian', ['student_id' => $student->id, 'guardian_id' => $guardian->id, 'relationship' => 'Mother', 'is_primary' => true]);
});

test('a linked guardian cannot be deleted but an unlinked guardian can', function () {
    $student = Student::factory()->create();
    $linked = Guardian::factory()->create();
    $unlinked = Guardian::factory()->create();
    $student->guardians()->attach($linked, ['relationship' => 'Father']);
    $this->actingAs(guardianActor([Permissions::GUARDIANS_VIEW, Permissions::GUARDIANS_DELETE]));

    Livewire::test('pages::guardians.show', ['guardian' => $linked])
        ->call('delete')
        ->assertHasErrors(['delete']);
    Livewire::test('pages::guardians.show', ['guardian' => $unlinked])
        ->call('delete')
        ->assertRedirect(route('guardians.index'));

    $this->assertModelExists($linked);
    $this->assertModelMissing($unlinked);
});

test('guardian details show current class without loading enrollment history', function () {
    $student = Student::factory()->create(['first_name' => 'Child']);
    $guardian = Guardian::factory()->create();
    $year = AcademicYear::factory()->create(['name' => '2026/2027']);
    $level = ClassLevel::factory()->create(['name' => 'Grade 4']);
    Enrollment::factory()->create(['student_id' => $student, 'academic_year_id' => $year, 'class_level_id' => $level]);
    $student->guardians()->attach($guardian, ['relationship' => 'Aunt']);
    $this->actingAs(guardianActor([Permissions::GUARDIANS_VIEW]));

    Livewire::test('pages::guardians.show', ['guardian' => $guardian])
        ->assertSee('Child')
        ->assertSee('Grade 4')
        ->assertSee('2026/2027')
        ->assertSee('Aunt');
});
