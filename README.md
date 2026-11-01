# KCA University Exam e-Sign Sheet

Students sign for each exam unit on a school-issued phone or tablet. The system pulls the student's cleared units from the KCA portal, validates their school email, and emails each student a **sealed PDF copy**, so the school is no longer the only holder of the record. Supervisors download a sealed per-unit sign sheet PDF.

Plain PHP 8.1+ (tested on 8.3) with SQLite. **No Composer packages**, so it drops into KCA's PHP environment. Needs extensions: `pdo_sqlite`, `gd`, `mbstring`, `openssl`, `curl`.

## Run it (development)

```bash
php bin/setup.php --demo          # creates .env, database, signing key, and an open demo exam session
php -S 0.0.0.0:8080 -t public public/router.php
php tests/smoke.php               # ~180 end-to-end API checks (throwaway database and server)
php tests/timetable.php           # ~130 checks of the timetable importer and assignment engine
```

Open http://localhost:8080. Dev demo accounts (mock portal only, refused when `APP_ENV=prod`) are in `data/mock_portal.json`.

## The flow

1. **Sessions come from the timetable.** A school coordinator uploads the timetable, the exam director approves it, and each sitting becomes an exam session (venue, time, papers, chief supervisor, invigilators with access). Only the **exam director** can add a one-off session by hand (`POST /api/sessions`, needs `supervisor_email` and at least one paper). Staff sign in with a `@kcau.ac.ke` portal account only.
2. **Chief supervisor** can grant other invigilators access until the session closes, switch **offline mode** on or off (the device keeps a roster and queues signatures if Wi-Fi drops), record **incidents**, review photo-only identity checks, and close the session.
3. **Invigilator** asks the student for their **full admission number** (no name search; five wrong numbers in two minutes lock the search for that staff member until unlocked via `/lookups/reset`). The portal photo appears so the invigilator can confirm it is the student, records how identity was checked (exam card + ID, card only, or photo only; photo-only goes to the supervisor for review), then hands over the device.
4. The student sees all their cleared units, but only the unit(s) in this session can be signed; the server rejects any other paper. Name, admission number, email and phone come from the portal and are locked. The student types the **booklet serial number** (unique within the session) and signs. Arrivals later than `late_after` minutes after opening are marked **late**.
5. The sealed PDF receipt is emailed to the portal email (allowed KCA domain only, exact match). Invigilators can only **flag** concerns; the chief supervisor records the **incident**, updates its status (history is kept) and a later re-sit sign sheet links back to the case.
6. **Roll call** lists registered students who have not signed (absent, or not cleared with the reason) and lets staff record when a student **exits**.
7. **Corrections:** the student can reopen the unit until the session closes (a new version; the old receipt is marked "superseded"). After that, only the **exam director** can reopen a form for a limited time.
8. **On close**, every unit sign sheet is sealed (signed rows with time, late marker, booklet, ID check, exit time and corrected flags; the absent / not-cleared list; incident cases with re-sit links). The chief supervisor or director can seal the **session pack** (cover page with totals and audit-chain status, followed by every unit sheet). Every sealed document carries the exam director's signature image and a security pattern derived from the seal key, and every use of the seal key is written to a custody log.

### Timetable upload (CSV)

Columns (any order, any capitalisation): `Unit, Date, Start, End, Venue` plus optional `Lecturer, Candidates`. Unit codes are matched to the portal ignoring case and spacing (`bsd4101` becomes `BSD 4101`); dates accept `03/11/2026` and `3 Nov 2026`; times accept `09:00`, `9am`, `09.00`. Papers sat in the same hall at the same time become one sitting. The planner then assigns a chief supervisor and invigilators with these rules: nobody is in two places at once (30 minutes allowed to settle in), a lecturer never invigilates their own paper, workload is spread evenly, staff of the same school are preferred, and the invigilator count follows the hall size (`candidates per invigilator`, default 40). Rules-based, no AI; an AI step can later be added in front of the importer to read PDFs and Excel layouts. Coordinators upload for their own school; the exam director approves.

### API additions and PDF routes

All routes are under `/api`, JSON, CSRF header on writes. See `demo/demo-backend.js` for the exact shapes. PDFs are sealed and verifiable at `/#/verify`; every PDF route returns `Content-Type: application/pdf`, `X-Document-Code`, shows inline by default and downloads with `?download=1`.

| What | Route | Who |
|---|---|---|
| Unit sign sheet | `GET /api/sessions/{id}/sheet/{unit}.pdf` | supervisor, invigilator (with access), director |
| Session pack (cover + all sheets) | `GET /api/sessions/{id}/pack.pdf` (or `POST /api/sessions/{id}/pack` for `{code, pdf}`) | supervisor, director |
| Any stored sealed original (receipt, sheet, pack) | `GET /api/documents/{code}/pdf` (alias `/api/documents/{code}.pdf`), listed by `GET /api/records` | director, session supervisor; receipts and sheets also invigilators with access |
| Student receipt | emailed on signing; `email.pdf` in the save response is `/api/documents/{code}/pdf` | as above |

Other routes: `/timetable*` (delegates to `src/Timetable.php`), `/portal/units`, `/sessions/{id}/lookups/reset`, `/rollcall`, `/offline`, `/offline-pack`, `/incidents`, `/flags`, `/incidents/{id}/update`, `/signatures/{id}/exit`, `/signatures/{id}/review`, `/records`, `/director/security`, `/director/signature`, `/audit/events`, `/audit/verify`.

## Security model

| Threat | Control |
|---|---|
| Edited PDF | Every PDF is sealed: SHA-256 of the canonical record, RSA-2048 signature, and a stored hash of the exact file. `/#/verify` checks a code or an uploaded PDF; one changed byte fails. |
| School quietly alters or deletes records | Audit log is HMAC-chained (needs `APP_SECRET`) and append-only (DB triggers). Edit history and issued documents are immutable. The director can run an integrity check. |
| Students emailing copies to outside addresses | Domain allow-list, and the address must equal the portal's email on file. |
| Stolen or shared logins | Portal staff accounts, per-session invigilator grants that expire at close, login rate limiting, idle timeout. |
| Web attacks | Prepared statements, CSRF token on every write, `HttpOnly` + `SameSite=Strict` cookies, strict CSP, no `innerHTML` with data, signature PNGs re-encoded server-side. |
| Student reads other students' data on the shared device | Only name, admission number and unit list are shown; the form auto-closes after 3 idle minutes. |

## What KCA ICT must do before real use

1. **Implement `src/Portal/PortalAdapter.php`.** `HttpPortal.php` is a template whose endpoints are assumptions. Needed: staff login (ideally portal SSO), student lookup by admission number, clearance and email, cleared units per exam period, student photo, units on the timetable, registered students per unit, and the staff list (see the interface docblocks). Use a read-only service account. Then set `PORTAL_DRIVER=http` and `APP_ENV=prod`.
2. **Confirm the school email domain(s)** in `ALLOWED_EMAIL_DOMAINS` (older docs show `students.kca.ac.ke`, newer `kcau.ac.ke`).
3. **Configure real mail:** `MAIL_MODE=smtp` plus SMTP settings (SPF/DKIM on the sending domain). In `outbox` mode emails are only written to `storage/outbox`.
4. **Serve over HTTPS** (nginx/Apache + php-fpm) with `public/` as the web root. Keep `storage/` and `.env` outside the web root, mode 0600/0700.
5. **Protect the signing key** (`storage/keys/signing.pem`): move it to an HSM or vault, back it up, and publish the public key. Losing it means old documents can no longer be re-verified by signature.
6. **Managed devices:** put the school phones/iPads in kiosk mode (Guided Access / Android lock task) so students cannot leave the page.
7. **Back up** `storage/` (database and `docs/`) nightly; run `php tests/smoke.php` after any change.

## Known limits

- The PDF seal is a detached RSA signature printed in the document plus server-side verification. It is **not** a PAdES signature that Adobe Reader validates on its own; that needs a PDF-signing library and an institutional certificate.
- The PDF shows the verification code and URL but **no QR code** yet. It does show the exam director's signature image and a security pattern derived from the seal key (an appearance aid; the RSA seal is what makes a document unforgeable).
- The offline roster covers students registered for the session's papers only (the portal adapter has no "all students" call); a student outside those papers is told to ask the invigilator when the device is offline.
- The director's signature must be uploaded as PNG or JPG (SVG cannot be rendered into the PDF).
- Student identity at signing is the invigilator's recorded check (portal photo shown, exam card / ID / photo-only choice stored and sealed); there is no ID scan.
- The mailbox check is a DNS MX lookup, not a test message.
- SQLite suits one hall server; for many concurrent halls move to PostgreSQL/MySQL (all SQL is plain).
