---
paths:
  - '{app/Models/User.php,app/Providers/FortifyServiceProvider.php,app/Http/Middleware/**,app/Http/Responses/**,resources/views/pages/auth/**,resources/views/pages/settings/users/**}'
---

# Settings Users

## Enforce managed-account lifecycle
Administrator-created and administrator-reset accounts use the hashed temporary credential `password` with `must_change_password = true`. Inactive accounts must fail Fortify authentication generically, and temporary-password users must be routed through the dedicated password-change screen before protected application routes. Never expose password hashes or include credentials in audit event metadata.
