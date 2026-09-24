---
paths:
  - '{app/Support/Reports/**,app/Http/Controllers/ReportExportController.php,resources/views/pages/reports/**,tests/Feature/ReportsTest.php}'
---

# Reports Feature

## Keep reports on shared calculations and validate dates before comparing them
Reports use ReportData for screen, CSV and print; reuse FeeLedger, FinanceSummary and ResultCalculator with the existing domain permissions and assignment scopes. Validate all filter values before date-range comparisons: after_or_equal can throw on an array-valued date_from even when that field has its own date_format rule. Keep assessment options bounded and searchable, retaining the authorized selected assessment so historical selections remain usable.
