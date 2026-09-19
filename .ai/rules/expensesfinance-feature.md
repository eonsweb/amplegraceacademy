---
paths:
  - '{app/Actions/Expenses/**,app/Models/Expense.php,app/Support/Finance/**,resources/views/pages/{expenses,finance}/**,tests/Feature/Expense*Test.php}'
---

# Expensesfinance Feature

## Preserve recorded expenses and allocate period income precisely
Expenses follow Draft → Recorded → Voided. Only drafts may be edited or deleted; record/void operations and their before/after financial_audits must commit together. Keep decimal(12,2) storage and reuse Fees Money for arithmetic. Finance income is existing non-void Payment money; academic filters sum matching payment allocations, never the entire multi-period payment. Snapshot expense category, academic labels and recorder names for historical display. EXPENSES_MYSQL_TESTS=1 runs locking and constraints against an isolated temporary database, never resets school data.

## Validate finance filters before the view renders
Resolve filter validation in render() before returning getProvidedView() so Flux receives the current error bag on the same request. Invalid filters must produce no rows or totals; validate numeric category IDs before querying MySQL to avoid coercion. Finance tests that customize settings must explicitly create the singleton SchoolSetting with id 1 because MySQL auto-increment counters do not roll back.
