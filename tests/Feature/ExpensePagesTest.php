<?php

use App\Actions\Expenses\ManageExpenseCategory;
use App\Actions\Expenses\SaveExpense;
use App\Models\AcademicYear;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\SchoolSetting;
use App\Models\Term;
use App\Models\User;
use App\Support\Authorization\Permissions;
use App\Support\Authorization\Roles;
use App\Support\Fees\Money;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;

/** @param list<string>|null $permissions */
function expensePageUser(?array $permissions = null): User
{
    $permissions ??= Permissions::all();
    foreach ($permissions as $permission) {
        Permission::findOrCreate($permission, 'web');
    }
    $user = User::factory()->create();
    $user->givePermissionTo($permissions);

    return $user;
}

test('expense and finance pages require authentication and their own permission', function (string $route, string $permission) {
    Permission::findOrCreate($permission, 'web');
    $this->get(route($route))->assertRedirect(route('home'));
    $this->actingAs(expensePageUser([]))->get(route($route))->assertForbidden();
    $this->actingAs(expensePageUser([$permission]))->get(route($route))->assertSuccessful();
})->with([
    ['expenses.index', Permissions::EXPENSES_VIEW],
    ['expenses.create', Permissions::EXPENSES_CREATE],
    ['expenses.categories', Permissions::EXPENSE_CATEGORIES_MANAGE],
    ['finance.overview', Permissions::FINANCIAL_REPORTS_VIEW],
]);

test('expense detail and draft editing enforce permissions and record state', function () {
    $draft = Expense::factory()->draft()->create();
    $recorded = Expense::factory()->create();
    $this->get(route('expenses.show', $recorded))->assertRedirect(route('home'));
    $this->get(route('expenses.edit', $draft))->assertRedirect(route('home'));
    $this->actingAs(expensePageUser([]))->get(route('expenses.show', $recorded))->assertForbidden();
    $this->actingAs(expensePageUser([]))->get(route('expenses.edit', $draft))->assertForbidden();
    $this->actingAs(expensePageUser([Permissions::EXPENSES_VIEW]))->get(route('expenses.show', $recorded))->assertSee('Teaching materials');
    $this->actingAs(expensePageUser([Permissions::EXPENSES_UPDATE]))->get(route('expenses.edit', $draft))->assertSee('Edit Draft Expense');
    $this->actingAs(expensePageUser())->get(route('expenses.edit', $recorded))->assertForbidden();
});

test('expense form defaults to current period and records only once with inline validation', function () {
    $this->actingAs($actor = expensePageUser([Permissions::EXPENSES_CREATE]));
    $year = AcademicYear::factory()->create(['is_current' => true]);
    $term = Term::factory()->create(['academic_year_id' => $year->id, 'is_current' => true]);
    $category = ExpenseCategory::factory()->create();
    $this->freezeTime();

    $page = Livewire::test('pages::expenses.form')->assertSet('form.academic_year_id', (string) $year->id)
        ->assertSet('form.term_id', (string) $term->id)
        ->call('save')->assertHasErrors(['form.expense_category_id', 'form.amount', 'form.description'])
        ->set('form.expense_category_id', (string) $category->id)->set('form.amount', '35.50')
        ->set('form.description', 'Exercise books')->call('save')->assertHasNoErrors()->assertSee('Expense recorded.')
        ->call('save');

    $this->assertDatabaseCount('expenses', 1);
    $this->assertDatabaseHas('expenses', ['amount' => '35.50', 'description' => 'Exercise books', 'recorded_by_user_id' => $actor->id, 'status' => 'recorded']);
});

test('draft form can save edit and record while constraining terms', function () {
    $this->actingAs(expensePageUser());
    $category = ExpenseCategory::factory()->create();
    Livewire::test('pages::expenses.form')->set('form.expense_category_id', (string) $category->id)
        ->set('form.amount', '50.00')->set('form.description', 'Electricity')->call('save', false)->assertHasNoErrors();
    $draft = Expense::query()->sole();
    $term = Term::factory()->create();

    Livewire::test('pages::expenses.form', ['expense' => $draft])
        ->set('form.academic_year_id', (string) $term->academic_year_id)->set('form.term_id', (string) $term->id)
        ->set('form.academic_year_id', '')->assertSet('form.term_id', '')
        ->set('form.amount', '55.35')->call('save')->assertHasNoErrors();

    $this->assertDatabaseHas('expenses', ['id' => $draft->id, 'amount' => '55.35', 'status' => 'recorded']);
});

test('expense component actions reauthorize after permissions are removed', function () {
    $actor = expensePageUser();
    $this->actingAs($actor);
    $category = ExpenseCategory::factory()->create();
    $page = Livewire::test('pages::expenses.form')->set('form.expense_category_id', (string) $category->id)
        ->set('form.amount', '25.00')->set('form.description', 'Books');
    $actor->revokePermissionTo(Permissions::EXPENSES_CREATE);

    $page->call('save')->assertForbidden();

    $this->assertDatabaseCount('expenses', 0);
});

test('read only expense viewers cannot void delete drafts or manage categories', function () {
    $this->actingAs(expensePageUser([Permissions::EXPENSES_VIEW]));
    $recorded = Expense::factory()->create();
    $draft = Expense::factory()->draft()->create();

    Livewire::test('pages::expenses.show', ['expense' => $recorded])->call('openVoid')->assertForbidden();
    Livewire::test('pages::expenses.show', ['expense' => $recorded])->set('reason', 'Wrong expense')->call('voidExpense')->assertForbidden();
    Livewire::test('pages::expenses.show', ['expense' => $draft])->call('deleteDraft')->assertForbidden();
    Livewire::test('pages::expenses.categories')->assertForbidden();
    $this->assertModelExists($draft);
    $this->assertDatabaseHas('expenses', ['id' => $recorded->id, 'status' => 'recorded']);
});

test('expense details support confirmation reason audited voiding and draft deletion', function () {
    $this->actingAs($actor = expensePageUser());
    $recorded = Expense::factory()->create();

    Livewire::test('pages::expenses.show', ['expense' => $recorded])->call('openVoid')->assertSet('showVoid', true)
        ->call('voidExpense')->assertHasErrors('reason')
        ->set('reason', 'Incorrect supplier receipt')->call('voidExpense')->assertHasNoErrors()
        ->assertSee('Incorrect supplier receipt')->assertSee('Expense voided');

    $this->assertDatabaseHas('expenses', ['id' => $recorded->id, 'voided_by_user_id' => $actor->id, 'status' => 'voided']);
    $draft = Expense::factory()->draft()->create();
    Livewire::test('pages::expenses.show', ['expense' => $draft])->call('deleteDraft')->assertRedirect(route('expenses.index'));
    $this->assertModelMissing($draft);
});

test('category page supports validation editing deactivation and safe deletion', function () {
    $this->actingAs(expensePageUser());
    $page = Livewire::test('pages::expenses.categories')->call('create')->call('save')->assertHasErrors('name')
        ->set('name', '  Utilities ')->call('save')->assertHasNoErrors();
    $category = ExpenseCategory::query()->sole();
    $page->call('create')->set('name', 'utilities')->call('save')->assertHasErrors('normalized_name');
    Expense::factory()->create(['expense_category_id' => $category->id]);

    $page->call('edit', $category->id)->set('isActive', false)->call('save')->assertHasNoErrors()
        ->call('delete', $category->id)->assertHasErrors('delete');

    $this->assertDatabaseHas('expense_categories', ['id' => $category->id, 'is_active' => false]);
    $unused = ExpenseCategory::factory()->create();
    $page->call('delete', $unused->id)->assertHasNoErrors('delete');
    $this->assertModelMissing($unused);
});

test('expense history searches filters dates methods categories and period', function () {
    $this->actingAs(expensePageUser());
    $term = Term::factory()->create();
    $first = Expense::factory()->create(['academic_year_id' => $term->academic_year_id, 'term_id' => $term->id,
        'description' => 'Library shelving', 'reference' => 'SUP-123', 'expense_date' => '2025-09-01']);
    $other = Expense::factory()->create(['description' => 'School repainting', 'expense_date' => '2025-09-02']);
    $page = Livewire::test('pages::expenses.index')->assertSee('Library shelving')->assertSee('School repainting')
        ->set('search', 'SUP-')->assertSee('Library shelving')->assertDontSee('School repainting')
        ->set('search', '')->set('categoryId', (string) $first->expense_category_id)->assertDontSee('School repainting')
        ->set('categoryId', '')->set('academicYearId', (string) $term->academic_year_id)->set('termId', (string) $term->id)
        ->assertSee('Library shelving')->assertDontSee('School repainting')
        ->set('academicYearId', '')->assertSet('termId', '')
        ->set('dateFrom', '2025-09-02')->assertDontSee('Library shelving')->assertSee('School repainting')
        ->set('dateFrom', '')->set('dateTo', '2025-09-01')->assertSee('Library shelving')->assertDontSee('School repainting')
        ->set('dateTo', '')->set('method', 'bank_transfer')->assertDontSee('Library shelving')
        ->set('method', 'cash')->assertSee('Library shelving')->set('status', 'voided')->assertDontSee('Library shelving');
    expect(Money::minor($page->get('recordedTotal')))->toBe(0);
});

test('invalid report dates and mismatched academic filters show errors without unfiltered totals', function () {
    $this->actingAs(expensePageUser());
    Expense::factory()->create();
    $term = Term::factory()->create();
    $other = AcademicYear::factory()->create();
    $page = Livewire::test('pages::finance.overview')->set('dateFrom', '2025-10-01')->set('dateTo', '2025-09-01')
        ->assertHasErrors('dateTo');
    expect($page->get('totals')['expenses'])->toBe('0.00');
    $page->set('dateFrom', '')->set('dateTo', '')->assertHasNoErrors()
        ->set('academicYearId', (string) $other->id)->set('termId', (string) $term->id)->assertHasErrors('termId');
    expect($page->get('totals')['expenses'])->toBe('0.00');
});

test('finance period errors appear immediately and clear after correction', function (string $component) {
    $this->actingAs(expensePageUser());
    $message = 'The date to field must be a date after or equal to date from.';

    Livewire::withQueryParams(['dateFrom' => '2025-10-01', 'dateTo' => '2025-09-01'])->test($component)
        ->assertHasErrors('dateTo')->assertSee($message)
        ->set('dateTo', '2025-10-02')->assertHasNoErrors()->assertDontSee($message);
})->with(['pages::expenses.index', 'pages::finance.overview']);

test('invalid expense filters show errors and cannot produce misleading history or totals', function (string $field, string $invalid, string $message) {
    $this->actingAs(expensePageUser());
    $expense = Expense::factory()->create(['description' => 'Filtered library purchase']);
    $value = $invalid === 'malformed-id' ? $expense->expense_category_id.'invalid' : $invalid;
    $page = Livewire::withQueryParams([$field => $value])->test('pages::expenses.index')
        ->assertHasErrors($field)->assertSee($message)->assertDontSee('Filtered library purchase');

    expect(Money::minor($page->get('recordedTotal')))->toBe(0);

    $page->set($field, '')->assertHasNoErrors()->assertDontSee($message)->assertSee('Filtered library purchase');
    expect(Money::minor($page->get('recordedTotal')))->toBe(12575);
})->with([
    'malformed category' => ['categoryId', 'malformed-id', 'Select a valid expense category.'],
    'missing category' => ['categoryId', '999999', 'Select a valid expense category.'],
    'unknown method' => ['method', 'unknown', 'Select a valid payment method.'],
    'unknown status' => ['status', 'cancelled', 'Select a valid expense status.'],
]);

test('history survives period switches category changes and inactive recording users', function () {
    $actor = expensePageUser();
    $originalName = $actor->name;
    $term = Term::factory()->create(['is_current' => true]);
    $category = ExpenseCategory::factory()->create(['name' => 'Original Category']);
    $expense = app(SaveExpense::class)->handle($actor, ['expense_category_id' => $category->id,
        'academic_year_id' => $term->academic_year_id, 'term_id' => $term->id, 'amount' => '42.25',
        'expense_date' => '2025-03-01', 'description' => 'Historical expense', 'submission_key' => (string) Str::uuid()]);
    app(ManageExpenseCategory::class)->save($actor, $category->id, 'New category name', '', false);
    $actor->update(['name' => 'Renamed recorder', 'is_active' => false]);
    $term->update(['is_current' => false]);
    AcademicYear::factory()->create(['is_current' => true]);
    $this->actingAs(expensePageUser());

    Livewire::test('pages::expenses.index')->set('academicYearId', (string) $term->academic_year_id)
        ->set('termId', (string) $term->id)->assertSee('Historical expense')->assertSee('Original Category')->assertSee($originalName);
    $this->get(route('expenses.show', $expense))->assertSee('Original Category')->assertSee($originalName);
    expect(Livewire::test('pages::finance.overview')->set('academicYearId', (string) $term->academic_year_id)->get('totals')['expenses'])->toBe('42.25');
});

test('expense screens use configured currency and escape free text', function () {
    $this->actingAs(expensePageUser());
    SchoolSetting::factory()->create(['id' => 1, 'currency_code' => 'USD']);
    $expense = Expense::factory()->create(['description' => '<script>alert(1)</script>', 'notes' => '<img src=x onerror=alert(2)>']);

    $this->get(route('expenses.show', $expense))->assertSee('$125.75')
        ->assertSee('<script>alert(1)</script>')->assertDontSee('<script>alert(1)</script>', false)
        ->assertDontSee('<img src=x onerror=alert(2)>', false);
    Livewire::test('pages::finance.overview')->assertSee('$125.75');
});

test('finance navigation is permission aware and seeded teachers have no finance access', function () {
    $this->seed([PermissionSeeder::class, RoleSeeder::class, RolePermissionSeeder::class]);
    $teacher = User::factory()->create()->assignRole(Roles::TEACHER);
    $this->actingAs($teacher)->get(route('dashboard'))->assertDontSee('Financial Overview')->assertDontSee('Record Expense');
    expect($teacher->can(Permissions::EXPENSES_VIEW))->toBeFalse();
    $admin = User::factory()->create()->assignRole(Roles::ADMIN);
    expect($admin->can(Permissions::EXPENSES_VOID))->toBeTrue();
    expect($admin->can(Permissions::EXPENSE_CATEGORIES_MANAGE))->toBeTrue();
    $this->actingAs($admin)->get(route('expenses.index'))->assertSee('Financial Overview')->assertSee('Record Expense');
    $owner = User::factory()->create()->assignRole(Roles::PROPRIETOR);
    expect($owner->can(Permissions::EXPENSES_VIEW))->toBeTrue();
    expect($owner->can(Permissions::EXPENSES_VOID))->toBeFalse();
    $this->actingAs($owner)->get(route('expenses.index'))->assertDontSee('Record Expense')->assertSee('Financial Overview');
});

test('expense index paginates at the configured page size and filters reset the page', function () {
    $this->actingAs(expensePageUser());
    SchoolSetting::factory()->create(['id' => 1, 'records_per_page' => 10]);
    Expense::factory()->count(12)->create();
    $page = Livewire::test('pages::expenses.index');
    expect($page->get('expenses')->count())->toBe(10);

    $page->call('setPage', 2);
    expect($page->get('expenses')->count())->toBe(2);
    $page->set('search', 'Teaching')->assertSet('paginators.page', 1);
});

test('finance screen queries remain bounded as records grow', function (string $component) {
    $this->actingAs(expensePageUser());
    Expense::factory()->create();
    Livewire::test($component);
    DB::enableQueryLog();
    DB::flushQueryLog();
    Livewire::test($component);
    $small = count(DB::getQueryLog());
    DB::disableQueryLog();
    Expense::factory()->count(12)->create();
    DB::enableQueryLog();
    DB::flushQueryLog();

    Livewire::test($component);

    $large = count(DB::getQueryLog());
    DB::disableQueryLog();
    expect($large)->toBeLessThanOrEqual($small + 2);
})->with(['pages::expenses.index', 'pages::expenses.categories', 'pages::finance.overview']);

test('finance reports paginate independently and reset both pages when the period changes', function () {
    $this->actingAs(expensePageUser());
    Expense::factory()->count(12)->sequence(fn ($sequence) => [
        'expense_date' => '2025-09-'.str_pad((string) ($sequence->index + 1), 2, '0', STR_PAD_LEFT),
    ])->create();
    $page = Livewire::test('pages::finance.overview');

    $page->call('setPage', 2, 'categoriesPage');

    expect($page->get('categoryTotals')->count())->toBe(2);
    expect($page->get('dailyTotals')->currentPage())->toBe(1);
    $page->call('setPage', 2, 'datesPage');
    expect($page->get('dailyTotals')->count())->toBe(2);
    expect($page->get('categoryTotals')->currentPage())->toBe(2);
    $page->set('dateFrom', '2025-09-11')
        ->assertSet('paginators.categoriesPage', 1)->assertSet('paginators.datesPage', 1);
    expect($page->get('totals'))->toBe(['income' => '0.00', 'expenses' => '251.50', 'net' => '-251.50']);
    expect($page->get('categoryTotals')->total())->toBe(2);
    expect($page->get('dailyTotals')->total())->toBe(2);
});
