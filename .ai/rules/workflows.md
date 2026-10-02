---
paths:
  - '{phpunit.xml,.github/workflows/tests.yml,tests/**}'
---

# Workflows

## Run dashboard and application tests on isolated MySQL
The dashboard uses MySQL MONTH() aggregation. The normal PHPUnit configuration forces MySQL and the dedicated amplegraceacademy_testing database, with DB_URL empty, so tests cannot inherit the school database from .env. Provision that empty test database locally using the existing MySQL credentials; CI provisions MySQL 8.4 and enables FEES_MYSQL_TESTS and EXPENSES_MYSQL_TESTS. Do not switch the suite to SQLite.
