# PostgreSQL Deployment

PostgreSQL is required for all application data. Startup fails clearly if `EASYSCHED_DATABASE_URL` is missing or invalid.

## Setup

1. Create a dedicated PostgreSQL database and store its connection URL in a server-side environment variable or an untracked `.env` file.
2. Enable PHP `pdo_pgsql` on the hosting server. Docker builds install the PostgreSQL PDO driver.
3. Set `EASYSCHED_DATABASE_URL` to a PostgreSQL URL, for example `postgresql://user:password@host:5432/database?sslmode=require`.
4. Open the application and sign in. The app applies its PostgreSQL schema and startup normalizations. Change demonstration passwords before using real records.

Keep the connection URL out of source control and logs. For Supabase or Neon, use the provider's recommended pooled URL and `sslmode=require`.

For Railway HTTPS, set `EASYSCHED_FORCE_HTTPS=1`, `EASYSCHED_TRUST_PROXY=1`, and `EASYSCHED_PUBLIC_URL=https://www.easysched.tech` in the EasySched web service's Variables, then redeploy. The public URL must be the canonical HTTPS origin without a path or port; it prevents redirects from exposing Railway's internal port. The Docker image stores PHP sessions outside the public web root so the `/data` application route does not collide with a physical directory or trigger Apache's directory-slash redirect.

## Importing class assignments

1. Update the localhost app to the latest GitHub code, then sign in as an administrator and open **Academic setup → Class assignments**.
2. Click **Download this database's assignments**. The app downloads a CSV for the local active academic term.
3. In Railway's deployed app, sign in as an administrator, open the same page, select that CSV, and click **Add missing assignments**.

The Railway app imports into the database configured by Railway's `EASYSCHED_DATABASE_URL`; it does not connect to your computer. This transfer is additive and does not need a Railway backup: existing matching Railway assignments are left unchanged, and only missing assignments are added. You can upload the same CSV again safely; already-present rows are skipped. The entire CSV is validated before changes are saved, and an invalid row prevents partial import.

Required terms, subjects, sections, and instructors must already exist in Railway. The import matches terms by year/semester, subjects by course code and name, and sections by code within a term. Instructors are matched by employee number first, then by exact name if employee numbers differ between databases. If neither identifies exactly one active faculty record in Railway, create or correct that faculty record there and retry. IDs are not used because they can differ between databases. The CSV includes only active assignments for the local active term; archived/inactive assignments are not exported. Download a fresh CSV after updating the localhost code so it includes the instructor-name column; an older file cannot match faculty whose employee numbers differ.

The CSV export and blank template use these columns:

```text
academic_year,semester,subject_code,subject_name,section_code,instructor_employee_no,instructor_name,enrollment,required_meetings
```

Use `YYYY-YYYY` for academic year and `First Semester`, `Second Semester`, or `Summer` for semester. Older CSV files without `instructor_name` remain supported when employee numbers match. Do not use database IDs: they may differ between local and Railway databases.

## Production checklist

- HTTPS enabled
- `pdo_pgsql` enabled
- Database URL stored only in hosting secrets
- PostgreSQL backups and a restore test configured
- Seed accounts rotated or disabled
- PHP error display disabled; logs retained privately
- One online generation worker or queue if multiple schedulers may generate concurrently
