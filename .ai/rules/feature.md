---
paths:
  - '{app/Models/AcademicYear.php,app/Models/Term.php,app/Support/Academic/**,resources/views/pages/academic/{years,terms}/**,database/migrations/**,database/factories/{AcademicYear,Term}Factory.php,tests/Feature/AcademicSetupManagementTest.php}'
---

# Feature

## Academic years always contain three fixed terms
Academic years are labels in consecutive YYYY/YYYY format and have no date fields. Creating a year must transactionally create exactly 1st Term, 2nd Term, and 3rd Term with orders 1-3. Terms are structural: they cannot be added, renamed, reordered, or individually deleted. A current term must belong to the current academic year; creating a new current year selects 1st Term, while switching an existing current year clears the previous current term.
