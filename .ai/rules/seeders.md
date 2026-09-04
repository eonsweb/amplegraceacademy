---
paths:
  - '{app/Support/Settings/**,app/Models/SchoolSetting.php,resources/views/**,database/seeders/SchoolSettingSeeder.php}'
---

# Seeders

## Resolve global settings through the cached service
Keep global school settings in the single school_settings row and read/write them through App\Support\Settings\SystemSettings so layouts and formatters never query the model directly. Branding uploads live on the public disk under branding/ and only their paths are stored. Appearance is per-user via users.theme; persisted user preference overrides guest localStorage before Flux paints.
