---
paths:
  - '{app/Actions/Students/**,app/Support/StudentImport*,tests/Feature/StudentImport*}'
---

# Support Feature

## Preserve import identities and original admission dates
Student imports create new students only; never infer missing admission dates or overwrite existing admission numbers. Preserve accepted hyphenated numbers and reserve their sequence in the existing student_admission counter before generating numbers. Acquire the import identity lock before any transactional snapshot read so competing MySQL imports revalidate committed student and guardian identities. Enrollment placement dates use the existing enrollment_date field; incomplete rows stay in preview for correction. Contention tests use STUDENT_IMPORT_MYSQL_TESTS=1 and isolated temporary MySQL databases.
