---
paths:
  - '{app/Actions/Fees/**,app/Support/Fees/**,app/Models/{Invoice,InvoiceItem,Payment,PaymentAllocation}.php,resources/views/pages/fees/**,tests/Feature/Fee*Test.php}'
---

# Fees Feature

## Preserve the allocation ledger and verify MySQL locking
Read balances and invoice status through FeeLedger; valid payment allocations are authoritative. Preserve invoice charge and enrollment snapshots, and correct transactions through audited void actions. Monetary input must be decimal strings, with Money minor-unit arithmetic. RecordPayment, VoidPayment and VoidInvoice serialize on invoice locks. FeeConcurrencyTest uses FEES_MYSQL_TESTS=1 to migrate an isolated temporary MySQL database and verify lock contention, idempotency, constraints and DATE-based daily totals; it must never reset the application's database.
