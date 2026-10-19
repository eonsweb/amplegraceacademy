---
paths:
  - '{app/Console/Commands/GrantAcademicSetupAccess.php,app/Support/Authorization/**,resources/views/pages/settings/roles/**}'
---

# Roles

## Recover newly introduced academic access explicitly
There is no trusted Super Admin bypass; the topbar label is display text only. For an approved existing active authorization manager, the operator may run authorization:grant-academic-setup <username>; it grants only web academic-setup.view directly and preserves role customizations. Never use role seeding to restore revoked permissions. Role renaming and permission synchronization must commit or roll back together.
