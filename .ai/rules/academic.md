---
paths:
  - '{database/seeders/{RoleSeeder,RolePermissionSeeder,PermissionSeeder}.php,resources/views/pages/academic/**}'
---

# Academic

## Preserve academic access decisions during upgrades
Academic Setup requires academic-setup.view plus the existing Classes/Subjects domain permissions; years and fixed terms reuse Classes permissions. Recheck module/domain access on Livewire hydration as well as mutations. PermissionSeeder is the additive upgrade path; existing roles, including empty or explicitly revoked roles, must never receive defaults on rerun. Defaults apply only when a seeded role is created; an operator must explicitly bootstrap the first existing permission manager's new access before UI delegation.
