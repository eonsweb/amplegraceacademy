---
paths:
  - 'tests/**'
---

# Tests

## Run MySQL test processes sequentially
Normal test runs share amplegraceacademy_testing and RefreshDatabase migrates that schema. Never overlap separate test commands against it; wait for each process to finish. Parallel execution requires explicitly isolated worker databases.
