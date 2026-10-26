# Student Registration

Students can request accounts without the administrator creating 500 accounts manually.

1. Open the login page and choose `New student? Request an account`.
2. Submit the student's name, username, email, program, year level, optional section, and password.
3. The request is stored as `PENDING`. It cannot be used to sign in.
4. An administrator opens `Academic setup` and reviews `Pending student registrations`.
5. Approving a request creates an active student account. Rejecting it keeps the request out of the login system.

The administrator can later edit the student's section assignment from the Users tab. Student records are scoped to their assigned section by the existing authorization rules.

## Deployment

Deploy the updated `index.php`, `script.js`, `api.php`, `db.php`, `schema.postgres.sql`, and `security.php` together. The schema is applied automatically and adds the `pending_registrations` table without deleting existing data.
