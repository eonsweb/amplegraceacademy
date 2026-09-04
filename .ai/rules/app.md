---
paths:
  - 'resources/views/{dashboard.blade.php,components/app/**,layouts/app/**}'
---

# App

## Keep the dashboard presentation-only
The dashboard shell and overview widgets use static presentation data until their domain modules exist. Do not add dashboard database queries, controller business logic, or chart/table dependencies merely to populate this page; keep reusable UI in Blade components and prefer inline SVG/CSS for simple visualizations.
