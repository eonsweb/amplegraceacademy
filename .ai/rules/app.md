---
paths:
  - 'resources/views/{dashboard.blade.php,components/app/**,layouts/app/**}'
---

# App

## Keep the dashboard presentation-only
The dashboard shell and overview widgets use static presentation data until their domain modules exist. Do not add dashboard database queries, controller business logic, or chart/table dependencies merely to populate this page; keep reusable UI in Blade components and prefer inline SVG/CSS for simple visualizations.

## Dashboard domain modules now supply real metrics
The dashboard data implementation supersedes the earlier presentation-only placeholder restriction. Preserve its Blade layout and SVG/CSS charts; DashboardMetrics supplies permission-gated aggregates with no polling or long-lived metric cache. Academic metrics use AcademicContext; calendar-month collections and the calendar-year chart use FinanceSummary payment queries. Notices, events and canteen have no authoritative source and remain empty/unavailable.
