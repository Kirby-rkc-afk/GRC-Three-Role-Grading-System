# Working on the GRC grading system

For every revision, inspect the connected frontend, UI behavior and backend before claiming it is finished. Use `docs/SYSTEM_ARCHITECTURE.md` for the current page map, grading IPO and verification boundaries.

## Product and design reference

- The owner's enrollment app is `C:\xampp\htdocs\SMS_WebSys`. Its dashboard, manage-students directory and student-information page are the visual reference. Read that app when needed; do not modify it as part of this grading project.
- Preserve the crimson GRC wordmark header, local Inter typography, sidebar account footer and one shared sign-in page.
- Use directories and dedicated detail pages for subjects, professors, classes, accounts and student records. Do not hide primary navigation or grade breakdowns inside dropdowns or accordions. Bounded form choices such as attendance status and department may use selects.
- Actions outside a table need visible names. Icons alone belong in the table's Action column and require accessible labels. Use consistent gaps and full button padding.
- Verify desktop and narrow mobile layouts, long names, empty/filter states, keyboard focus and navigation. Scroll wide records inside their table container; the whole page must fit the viewport.

## Data and grading

- Keep Midterm and Final criteria separate. Major exams have one canonical score sheet per subject. Keep missing, not graded, excused and scored zero distinct.
- Students see only their own released snapshots. Draft changes must not alter published results.
- Preserve ownership, CSRF, revision, transaction and audit protections when changing forms or routes. Preserve concurrent ID and class-offering protection.
- Use disposable databases for mutation tests. Never reset a real user's password, change live scores or run destructive seed corrections merely to test UI.
- Live Google OAuth integration is outside the agreed scope.

## Verification

Run PHP syntax checks for changed PHP files and JavaScript syntax checks for changed scripts. Select relevant existing tests under `tests/`; the architecture document maps suites to requirements. A visual change needs real browser inspection and interaction checks, not only a successful HTTP response. Keep screenshots and sensitive test sessions in the private artifact folder rather than the public web root. State any remaining verification limits accurately.
