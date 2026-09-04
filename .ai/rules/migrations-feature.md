---
paths:
  - '{app/Models/{ClassLevel,ClassSubject}.php,resources/views/{components/academic/layout.blade.php,pages/academic/{class-levels,class-subjects}/**},routes/web.php,database/{migrations/**,factories/ClassSubjectFactory.php},tests/Feature/AcademicSetup*Test.php}'
---

# Migrations Feature

## Class subjects attach directly to class levels
This school does not use class sections. Academic Setup must not expose a Class Sections route, page, model, factory, permission, or navigation item. ClassSubject belongs directly to ClassLevel through class_level_id, and assignments are unique per academic year, class level, and subject. Class levels are displayed by level_order, never alphabetically.
