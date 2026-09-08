# Staff Management

## Purpose

The Staff module stores school employee records and supports authorized listing, creation, viewing, editing, employment-status changes, safe deletion, photo management, and optional application-account linking.

Staff and users are separate concepts:

- A `Staff` record represents an employee and does not require login access.
- A `User` record represents an authenticated application account.
- A staff member may have zero or one linked user. Application roles never derive automatically from the staff member's employment position.

## Schema

The `staff` table contains:

- `staff_number`: immutable, unique, automatically generated as `STF000001`.
- `first_name`, `last_name`: required identity fields.
- `gender`, `date_of_birth`, `photo`: optional personal fields.
- `email`, `phone`, `address`: optional contact fields. Staff email is not globally unique.
- `role_title`: required employment position.
- `department`, `employment_type`, `employment_date`: optional employment fields.
- `status`: `active` or `inactive`.
- timestamps.

`staff_number_sequences` holds the locked sequence used to allocate staff numbers without coupling them to database IDs. The `users.staff_id` column is nullable, unique, and foreign-key constrained to `staff.id`. Staff deletion is restricted while a user remains linked; deleting a user does not delete the staff record.

Indexes support the permanent staff number, status/name directory ordering, department/status filtering, and the one-to-one user link. Contact fields use bounded substring search and are not indexed because leading-wildcard matching would not benefit from ordinary B-tree indexes.

## Model Relationships

- `Staff::user()` is a `hasOne` relationship.
- `User::staff()` is a nullable `belongsTo` relationship.

Teachers are Staff records with an appropriate `role_title`; there is no duplicate Teacher entity.

## Permissions and Authorization

The module uses `StaffPolicy` and the central Spatie permission catalog:

- `staff.view`
- `staff.create`
- `staff.update`
- `staff.delete`
- `staff.manage-status`
- `staff.manage-user-account`

Account creation also requires `users.create` and `users.assign-role`. Linking and unlinking existing accounts also requires `users.update`. Navigating to User Management requires `users.view`. Permissions are checked again inside every Livewire mutation, so hidden controls are not the security boundary.

## Validation

Names and `role_title` are required and length limited. Gender, employment type, and status use backed enums. Dates must be valid and cannot be in the future. Email, phone, address, department, and other strings have bounded lengths. Photos are optional images restricted to JPEG, PNG, or WebP and 2 MB.

Creation checks exact name plus matching phone/email to flag an obvious possible duplicate. This is advisory validation rather than a global uniqueness rule for staff contact details. Database uniqueness remains authoritative for `staff_number` and `users.staff_id`.

## Account Linking

The profile's Application Access section can create a managed user or link an existing unlinked user. New accounts use the same `CreateManagedUser` action as User Management, including:

- normalized unique username and email;
- hashed temporary password `password`;
- `must_change_password = true`;
- delegation-safe Spatie role assignment;
- existing user-management audit event dispatch.

Employment `role_title` and Spatie roles are intentionally independent. Unlinking clears only `users.staff_id`; it does not deactivate or delete the user, remove roles, or change credentials.

## Status and Deletion Rules

Employment status is independent of user account status. Deactivating staff does not modify the linked user or historical records.

Permanent deletion is available only when all of the following are true:

- the actor has `staff.delete`;
- the staff record is inactive;
- no user account is linked;
- no current or future foreign-key dependency blocks deletion.

When a database dependency exists, the UI directs the operator to retain the inactive record. No academic history is cascade-deleted.

## Performance Strategy

The directory uses server-side pagination with the configured page size (25 by default), URL-backed filters, 400 ms debounced search, stable ordering, and selective columns. Application access is rendered with `withExists('user')`, so the index does not hydrate users or produce an N+1 query. Department options are distinct, ordered, and capped. Existing-user lookup requires two characters and returns at most eight rows.

The profile selectively eager loads one user and that user's roles. No staff list is cached or loaded in full.

## Security and Audit Events

Every route requires authentication, verified email, completion of the mandatory password change, and the relevant permission. Livewire identifiers are locked, mutation targets are re-queried, output uses Blade escaping, dynamic query identifiers are not accepted, and uploaded files use Laravel's generated storage names.

`StaffManagementChanged` emits meaningful created, updated, activated, deactivated, linked, unlinked, and deleted events with actor and staff IDs plus minimal metadata. Account operations also emit the existing `UserManagementChanged` event. Passwords and sensitive snapshots are never included in event metadata.

## Future Extension Points

Future attendance, assessment ownership, payroll, leave, timetable, documents, or employment history should reference `staff.id`. These workflows are intentionally outside the current module, and class sections are not reintroduced.

Academic Setup already has a working teacher-assignment workflow whose `class_subjects.staff_id` foreign key points to `users.id`. This Staff implementation leaves that existing workflow unchanged. A linked staff member can therefore be resolved through their User account; supporting subject assignment for staff without login access should be handled as a deliberate Academic Setup migration in that future module rather than silently changing existing assignment semantics here.
