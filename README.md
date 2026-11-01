# KCA University Exam e-Sign Sheet

Students sign for each exam unit on a school-issued phone or tablet. The system pulls the student's cleared units from the KCA portal, validates their school email, and emails each student a **sealed PDF copy**, so the school is no longer the only holder of the record. Supervisors download a sealed per-unit sign sheet PDF.

Plain PHP 8.1+ (tested on 8.3) with SQLite. **No Composer packages**, so it drops into KCA's PHP environment. Needs extensions: `pdo_sqlite`, `gd`, `mbstring`, `openssl`, `curl`.

## Run it (development)

```bash
php bin/setup.php --demo          # creates .env, database, signing key, and an open demo exam session
php -S 0.0.0.0:8080 -t public public/router.php
php tests/smoke.php               # 53 end-to-end API checks (uses a throwaway database)
```

Open http://localhost:8080. Dev demo accounts (mock portal only, refused when `APP_ENV=prod`) are in `data/mock_portal.json`.

## The flow

1. **Chief supervisor** signs in with a portal staff account, loads the exam session (period, venue, open/close time) on the school device, and can grant other invigilators' accounts access until the session closes (revocable).
2. **Invigilator** hands the device to the student. The student types a name or admission number and taps their name. The portal returns whether they are cleared and which units they may sit this semester.
3. The student taps a unit and fills: phone, booklet serial number, school email, signature. Full name and admission number come from the portal and cannot be edited.
4. **Save & send email copy** is blocked unless the email (a) is well formed, (b) is on an allowed KCA domain (exact match), (c) matches the email on the student's portal record, and optionally (d) the domain has MX records.
5. The sealed PDF is built, emailed, and the device returns to the sign sheet home.
6. **Corrections:** the student can reopen the unit until the session closes (a new version; the old one is kept and its receipt is marked "superseded"). After that, only the **exam director** can reopen a form for a limited time.

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

1. **Implement `src/Portal/PortalAdapter.php`.** `HttpPortal.php` is a template whose endpoints are assumptions. Needed: staff login (ideally portal SSO), student search, student clearance and email, and cleared units per exam period. Use a read-only service account. Then set `PORTAL_DRIVER=http` and `APP_ENV=prod`.
2. **Confirm the school email domain(s)** in `ALLOWED_EMAIL_DOMAINS` (older docs show `students.kca.ac.ke`, newer `kcau.ac.ke`).
3. **Configure real mail:** `MAIL_MODE=smtp` plus SMTP settings (SPF/DKIM on the sending domain). In `outbox` mode emails are only written to `storage/outbox`.
4. **Serve over HTTPS** (nginx/Apache + php-fpm) with `public/` as the web root. Keep `storage/` and `.env` outside the web root, mode 0600/0700.
5. **Protect the signing key** (`storage/keys/signing.pem`): move it to an HSM or vault, back it up, and publish the public key. Losing it means old documents can no longer be re-verified by signature.
6. **Managed devices:** put the school phones/iPads in kiosk mode (Guided Access / Android lock task) so students cannot leave the page.
7. **Back up** `storage/` (database and `docs/`) nightly; run `php tests/smoke.php` after any change.

## Known limits

- The PDF seal is a detached RSA signature printed in the document plus server-side verification. It is **not** a PAdES signature that Adobe Reader validates on its own; that needs a PDF-signing library and an institutional certificate.
- The PDF shows the verification code and URL but **no QR code** yet.
- Student identity at signing is "the person the invigilator handed the device to". Add a photo-on-record display or ID scan if the exam office wants stronger proof.
- The mailbox check is a DNS MX lookup, not a test message.
- SQLite suits one hall server; for many concurrent halls move to PostgreSQL/MySQL (all SQL is plain).
