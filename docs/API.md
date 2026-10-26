# EasySched API

The frontend calls `api.php?action=...` on the same origin. Protected actions require a server session; mutating actions also require the CSRF token returned by login/bootstrap.

| Action | Method | Access | Purpose |
| --- | --- | --- | --- |
| `login` | POST | Public | Start a session with per-IP/account throttling and an adaptive challenge |
| `health` | GET | Public | Confirm the API and database connection are available |
| `logout` | POST | Authenticated | End the session |
| `bootstrap` | GET | Authenticated | Load role-scoped application state |
| `generate` | POST | Admin/Scheduler | Generate and publish a validated schedule |
| `generate_selected` | POST | Admin/Scheduler | Regenerate selected offerings and preserve the rest of the published schedule |
| `save_schedule` | POST | Admin/Scheduler | Move a class after validation |
| `add_schedule` | POST | Admin/Scheduler | Add a required or extra meeting using entered start/end times after validation |
| `delete_schedule` | POST | Admin/Scheduler | Cancel a meeting while preserving history |
| `save_master` | POST | Admin/Scheduler | Manage academic records |
| `change_password` | POST | Authenticated | Change the current password |
| `save_settings` | POST | Admin | Change the active term |
| `available_rooms` | POST | Instructor | Find available rooms for a class or an `OTHER` room request; the exact weekday date must be at least one day ahead and in the current year |
| `request_schedule` | POST | Instructor | Request a room for a weekday at least one day ahead within the current year; `request_type: "OTHER"` requires a non-empty `purpose` |
| `review_schedule_request` | POST | Admin | Approve or reject a pending room or schedule-change request |
| `export` | GET | Authenticated | Download a role-scoped CSV |

The API is intentionally same-origin and session-based. Do not expose a database provider's table REST endpoint directly to the browser.

`generate_selected` accepts `term_id` and optional non-empty ID arrays named `program_ids`, `section_ids`, `subject_ids`, and `instructor_ids`. At least one filter must be selected. Non-empty filter groups are combined with AND semantics; choices within a group are combined with OR semantics. All required meetings for matching offerings are generated, while all other published schedule entries, overrides, cancellations, and occupancy are copied into the new run. The operation fails without replacing the current published run if the selected offerings cannot be scheduled conflict-free.

`add_schedule` accepts `offering_id`, `room_id`, `day_of_week`, `start_time`, `start_period`, `end_time`, and `end_period`. Times use `HH:MM` with `AM`/`PM`; the duration may differ from the subject's configured meeting length but must fit within the timetable. Manual additions may use a room that does not meet capacity, room-type, or feature requirements. Availability, schedule conflicts, lunch breaks, and instructor daily teaching-hour limits remain enforced. Conflict and availability checks use the configured timetable slots, so two custom intervals that share an hourly slot may be treated as conflicting even when their exact minutes do not overlap.
