# Project Rules Index

Before planning or editing, find the row whose globs match the file's path and read that rule file.

| Applies to | Rule file |
| --- | --- |
| resources/views/{dashboard.blade.php,components/app/**,layouts/app/**} | .ai/rules/app.md |
| {app/Models/User.php,config/fortify.php,routes/web.php,resources/views/pages/auth/**} | .ai/rules/auth.md |
| {app/Models/AcademicYear.php,app/Models/Term.php,app/Support/Academic/**,resources/views/pages/academic/{years,terms}/**,database/migrations/**,database/factories/{AcademicYear,Term}Factory.php,tests/Feature/AcademicSetupManagementTest.php} | .ai/rules/feature.md |
| {app/Models/{ClassLevel,ClassSubject}.php,resources/views/{components/academic/layout.blade.php,pages/academic/{class-levels,class-subjects}/**},routes/web.php,database/{migrations/**,factories/ClassSubjectFactory.php},tests/Feature/AcademicSetup*Test.php} | .ai/rules/migrations-feature.md |
| {app/Models/{Guardian,Student,StudentGuardian}.php,app/Actions/Guardians/**,resources/views/{components/⚡student-guardians.blade.php,pages/guardians/**},database/migrations/**} | .ai/rules/migrations.md |
| {app/Support/Settings/**,app/Models/SchoolSetting.php,resources/views/**,database/seeders/SchoolSettingSeeder.php} | .ai/rules/seeders.md |
| {app/Models/User.php,app/Providers/FortifyServiceProvider.php,app/Http/Middleware/**,app/Http/Responses/**,resources/views/pages/auth/**,resources/views/pages/settings/users/**} | .ai/rules/settings-users.md |
| {app/Support/Authorization/**,resources/views/pages/settings/roles/**,resources/views/pages/settings/users/**,routes/**} | .ai/rules/users.md |
