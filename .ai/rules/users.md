---
paths:
  - '{app/Support/Authorization/**,resources/views/pages/settings/roles/**,resources/views/pages/settings/users/**,routes/**}'
---

# Users

## Keep authorization permission-driven and delegation-safe
Use the web-guard Spatie roles and permissions defined centrally in App\Support\Authorization\Permissions. Feature access must check permissions, not seeded role names. Authorization mutations must prevent self-editing, preserve at least one complete authorization manager, and never let an actor grant effective permissions they do not hold.
