---
paths:
  - '{app/Models/{Guardian,Student,StudentGuardian}.php,app/Actions/Guardians/**,resources/views/{components/⚡student-guardians.blade.php,pages/guardians/**},database/migrations/**}'
---

# Migrations

## Store guardian relationship on the student link
The student_guardian.relationship value is authoritative because one guardian may relate differently to different students. Keep guardians.relationship nullable only for backward compatibility. Primary-guardian changes must lock the student row and transactionally clear the previous primary link.
