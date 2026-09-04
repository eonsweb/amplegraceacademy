---
paths:
  - '{app/Models/User.php,config/fortify.php,routes/web.php,resources/views/pages/auth/**}'
---

# Auth

## Authenticate school users by normalized username
Public authentication uses a trimmed, lowercase, unique `username`; email remains for password reset/contact only. Guests enter through `/`, authenticated users go to `/dashboard`, and public registration/passkey login stay disabled.
