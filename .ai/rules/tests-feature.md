---
paths:
  - 'tests/Feature/Expense*Test.php'
---

# Tests Feature

## Keep audit rollback tests safe on MySQL
Do not rename or alter tables to simulate an audit failure inside RefreshDatabase transactions: MySQL DDL implicitly commits. Inject a failure on the financial_audits insert using a query listener, then assert both the expense state and audit rows rolled back. Run finance feature tests against an isolated temporary MySQL database as well as the default suite.
