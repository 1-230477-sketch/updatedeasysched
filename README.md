# EasySched Defense Build

EasySched is a complete class scheduling application for New Sinai School and Colleges Sta. Rosa, Inc. This defense build uses PHP 8.2, PDO, server-side sessions, and a browser interface that can be hosted by XAMPP. PostgreSQL is required for all application data.

## What is included

- Conflict-aware automatic scheduling using MRV ordering and backtracking.
- Hard checks for room capacity, room type/features, room overlap, instructor overlap, section overlap, instructor daily hours, declared availability, and required meetings.
- Published schedule runs with diagnostics. A failed generation never replaces the last published run.
- Role-based accounts: `admin`, `scheduler`, `instructor`, and `student`.
- Server-side password hashes, session cookies, CSRF protection, prepared SQL statements, audit logs, security headers, and restrictive CSP.
- Login protection with independent IP/account throttles, exponential backoff, generic failure messages, failed-login audit events, and an adaptive arithmetic challenge after repeated failures.
- Weekly calendar, filters, print view, CSV export, master-data forms, validated CSV import of class assignments, reports, manual scheduling of required or extra meetings, account password changes, and OTP-verified email changes.

Set `EASYSCHED_DATABASE_URL` to a PostgreSQL connection URL in the server environment or private `.env` file. Email verification and password recovery are optional internet-connected features; see [`docs/EMAIL_OTP_SETUP.md`](docs/EMAIL_OTP_SETUP.md).

## Run with XAMPP

1. Install XAMPP with Apache and PHP 8.2 or newer. Enable `pdo_pgsql` in `php.ini`.
2. Copy this folder to `C:\xampp\htdocs\EasySched-Defense` (or another Apache document-root folder).
3. Start Apache in the XAMPP Control Panel.
4. Open `http://localhost/EasySched-Defense/`.
5. The first request applies the PostgreSQL schema, installs the cleaned subject/course catalog, and inserts other demonstration data only when the database is empty. Existing subject-linked class assignments are archived as inactive so historical schedule references remain intact.
6. Sign in with one of the accounts below, then change the password before using real records.

The Apache rewrite rules in `.htaccess` let you open the home page at `/`, sign-in at `/login`, registration at `/register`, password recovery at `/forgot-password`, and workspace sections at paths such as `/dashboard`, `/schedules`, `/data`, `/reports`, `/profile`, `/settings`, and `/help`. These paths are relative to the folder where EasySched is installed. Apache must allow `.htaccess` overrides and have `mod_rewrite` enabled for direct links and page refreshes to work.

For a quick local-only run without Apache, from this folder use:

```text
C:\xampp\php\php.exe -S 127.0.0.1:8080 -t .
Never import old plaintext passwords. Provision new accounts and set password hashes through the application or a controlled server-side script.

## Typical defense demonstration

1. Sign in as `admin` and show the dashboard metrics.
2. Open **Academic setup** to search and manage the cleaned subject/course-code catalog, then explain the related programs, sections, faculty, rooms, and class assignments. Administrators can also import validated class assignments from CSV; see [`docs/ONLINE_DEPLOYMENT.md`](docs/ONLINE_DEPLOYMENT.md).
3. Use **Auto generate** to build the full active-term timetable, or **Manual generate** to select programs, sections, subjects, and/or instructors. Manual generation replaces matching offerings and preserves all other published classes; selections across filter groups must all match. The result shows assignments, search nodes, warnings, problems, and the hard constraints checked.
4. Open **Weekly schedule**, filter by section/instructor/room, then print or export CSV.
5. Attempt a conflicting manual edit. The server rejects it and leaves the published entry unchanged.
6. Sign in as `instructor` and `student` to demonstrate server-side scope restrictions. Changing an identifier in a request does not broaden either role's schedule view.
7. Open **Reports** to show room utilization and constraint evidence.
8. As an administrator, open **Security & Audit Logs** to review authenticated actions, failed logins, denied access, scheduling activity, and other recorded events. Use the bounded filters, detail view, or CSV export for investigation.

## Architecture

`index.php` is the server-rendered shell. `script.js` requests JSON from `api.php` and renders pages without a frontend framework. `db.php` requires PostgreSQL from `EASYSCHED_DATABASE_URL`, applies `schema.postgres.sql`, and seeds an empty database. `api.php` contains authentication, authorization, validation, CRUD, generation, manual-edit validation, export, and audit logging.

The core data model is:

```text
academic_terms -> sections -> course_offerings <- subjects
programs ------/                         \        instructors
rooms + time_slots -> schedule_entries -> schedule_occupancy
users -> audit_logs; schedule_runs preserve publication history
```

Academic setup opens on the subject/course-code catalog. Class assignment subject choices display subject names only. Subject names are unique after case and formatting normalization, while course codes may be shared by genuinely different subjects. Catalog replacement archives prior class assignments as inactive to retain historical schedule references.

The database schema uses foreign keys, check constraints, unique keys, indexes, and triggers. The occupancy table has a unique key on `(run_id, resource_type, resource_id, day_of_week, slot_id)`, which is the final database-level guard against double booking a room, instructor, or section in a published run.

## Scheduling method

The generator expands each offering into one task per required meeting. It computes every legal room/day/start-slot candidate, rejects candidates that violate hard constraints, and uses minimum-remaining-values ordering to schedule the most constrained task first. Backtracking rolls back occupancy and daily-hour state when a branch fails. Candidate costs prefer capacity fit, declared instructor times, different days for repeated meetings, and avoiding the first/last slot; correctness always takes priority. A node limit prevents runaway searches and returns diagnostics instead of publishing a partial timetable.

Instructor, room, and section availability can be stored in `instructor_availability`, `room_availability`, and `section_availability`; rows marked unavailable are honored by the solver. Instructor time preferences in `instructor_time_preferences` influence candidate order but never override a hard constraint. The solver compacts each section's classes into adjacent blocks using real clock time, including the lunch boundary, and prefers distributing those blocks across Monday through Friday with about four instructional hours per day. This daily load target is a preference; room, instructor, availability, and conflict constraints still take priority.

## Security notes

- Passwords are stored only as `password_hash` values using PHP's `password_hash`/`password_verify`.
- Sessions are HTTP-only, SameSite cookies; IDs are regenerated after login.
- Mutating requests require a per-session CSRF token.
- All database values are passed through prepared statements. User-facing errors do not expose SQL details.
- Every protected action checks the session role on the server. UI hiding is not the authorization boundary.
- CSP, frame, MIME-sniffing, referrer, permissions, and no-cache headers are sent by the application.
- CSV export prefixes formula-like values to reduce spreadsheet injection risk.
- Security and audit logs are available only to the `admin` role. Dashboard and CSV timestamps use Philippine Time (`Asia/Manila`, PHT). The application records safe, structured metadata without passwords, tokens, session identifiers, or raw request bodies; application append-only behavior is not database-level tamper protection.
- Under Apache, deny the `data` directory at the reverse proxy/web-server layer in production.

Session protection and security headers remain enabled on localhost. Keep `.env` out of source control.


## Database and migration

The schema is created idempotently from `schema.postgres.sql`. PostgreSQL is the only supported database. The migration is complete, and prior PostgreSQL tables remain archived in a separate schema. Use `pg_dump` and test restoring it before maintenance. See [`docs/MIGRATION.md`](docs/MIGRATION.md) for importing reviewed legacy browser data. Never import old plaintext passwords. Provision new accounts and set password hashes through the application or a controlled server-side script.

Use `pg_dump` and test restoring it before maintenance. See [`docs/MIGRATION.md`](docs/MIGRATION.md) for importing reviewed legacy browser data. Never import old plaintext passwords.

## Verification

Run the static checks:

```text
C:\xampp\php\php.exe -l api.php
C:\xampp\php\php.exe -l db.php
C:\xampp\php\php.exe -l index.php
node --check script.js
C:\xampp\php\php.exe tests\postgres_database.php
```

The PostgreSQL check requires `EASYSCHED_DATABASE_URL` and verifies the cleaned catalog, subject add/edit/delete and normalization behavior, code/name uniqueness, generated IDs, and rollback behavior without leaving test rows behind.

## Known boundaries and next production steps

- Time slots and availability tables are supported by the solver but do not yet have dedicated UI editors; configure them through a controlled SQL/admin workflow.
- PostgreSQL is the only supported database; run one scheduling generation at a time unless generation is serialized.
- Add automated browser tests (Playwright), centralized monitoring, account recovery, and a formal data-retention policy before production use.

## Defense talking points

Emphasize that the system is a constraint solver, not a random timetable generator: tasks are ordered by constraint tightness, candidates are checked before assignment, occupancy is tracked for all three shared resources, failed branches are undone, and publication is transactional. The normalized schema and unique occupancy key provide a second line of defense. Diagnostics make impossible inputs explainable, while the role checks and scoped queries demonstrate that authorization is enforced by the backend.
