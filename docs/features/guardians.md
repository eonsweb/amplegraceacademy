# Guardians / Parents

The Guardians module manages adult contact records independently from students and links the two through a many-to-many relationship. A guardian can support multiple students and each student can have multiple guardians.

## Schema and relationships

- `guardians` stores title, first/middle/last name, phone, email, and address. Its legacy nullable `relationship` column is retained for backward compatibility.
- `student_guardian` stores the authoritative student-specific `relationship`, `is_primary`, timestamps, and foreign keys.
- The unique `(student_id, guardian_id)` constraint prevents duplicate links. Existing indexes support guardian lookup and student/primary-link queries.
- `Guardian::students()` and `Student::guardians()` use the `StudentGuardian` custom pivot model. Both models also expose `studentGuardians()`.
- Guardian detail pages obtain current class information through `Student::currentEnrollment`; class data is never copied to the guardian.

## Permissions

The module uses Laravel policies backed by the central Spatie permission constants:

- `guardians.view`
- `guardians.create`
- `guardians.update`
- `guardians.delete`
- `guardians.link-student`
- `guardians.unlink-student`

Every Livewire action reauthorizes its operation. Seeded Admin users receive all permissions; Headmasters receive view/create/update/link/unlink permissions; Proprietors receive view access; Teachers receive none by default.

## Workflows

Authorized users can search, sort, and paginate guardians; create and edit contact records; inspect linked students; and navigate between Guardian and Student profiles. The Student profile provides server-driven search for linking an existing guardian and a one-step create-and-link form for a new guardian.

Relationship choices are Mother, Father, Grandmother, Grandfather, Aunt, Uncle, Sibling, Legal Guardian, Foster Parent, and Other. The value belongs to each Student–Guardian link, allowing the same adult to have different relationships to different students.

## Primary guardian rules

A student may have zero or one primary guardian. Linking a primary guardian or changing the primary guardian locks the student row and transactionally clears the previous primary link before setting the selected link. Transactions retry deadlocks up to five times. MySQL has no portable partial unique index for this rule, so serialization is enforced by the application and covered by tests.

Unlinking a primary guardian is allowed and leaves the student temporarily without a primary guardian; another guardian is never promoted silently.

## Duplicate prevention and deletion

Create workflows compare exact normalized email and trimmed phone values and warn users to reuse an existing record. Names alone never trigger an automatic merge. Guardian search is bounded and server-driven rather than loading every guardian into the browser.

“Unlink” deletes only `student_guardian`. Deleting a Guardian is a separate permission and is blocked while any student link exists. Student and Guardian foreign keys use restrictive delete behavior.

## Validation, performance, and extension points

All writes validate required names, contact fields, relationship choices, booleans, and referenced IDs. Livewire IDs used as component identity are locked, and relationship actions scope submitted link IDs to the displayed Student or Guardian.

Directory queries select only displayed columns, use `withCount('students')`, stable ordering, and configured server-side pagination. Search is debounced, limited to five terms, and uses relationship existence queries for Student matches. Detail pages eager load only current enrollment, academic year, and class level to prevent N+1 queries.

The independent Guardian record and custom pivot model leave room for future portals, emergency contacts, communication preferences, billing responsibility, and household grouping without implementing those features now.

The application currently has no audit-log storage or audit service. Guardian events should be added when that shared subsystem is implemented rather than introducing an isolated logging mechanism for this module.
