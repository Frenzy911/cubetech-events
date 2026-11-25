# CubeTech Events: Event Registration with QR Check-in

A web app where an organizer creates events with a set capacity, attendees register and get a QR code ticket, and door staff scan the QR code (or type the ticket code) to check people in.

**Level reached:** Level 3. All of Level 1 and Level 2, plus Level 3 items 12, 13, 14, 15 and 16 (5 of 6). Item 17 (deployment) is not done.

## Tech stack

| Part | Used |
|---|---|
| Frontend | HTML, CSS (mobile-first, no framework), vanilla JavaScript |
| Backend | PHP 8.1+ (tested on PHP 8.3), no Composer packages |
| Database | MySQL / MariaDB through **mysqli**: `config.php` creates `$conn` and every query is a prepared statement |
| Server | XAMPP (Apache + MariaDB) |
| QR codes | Built-in PHP generator (`includes/qrcode.php`, SVG output, works offline) |
| Camera scanning | Browser `BarcodeDetector` when available, otherwise [jsQR](https://github.com/cozmo/jsQR) loaded from a CDN on demand |

## Setup and run (XAMPP on Windows)

1. Copy the `cubetech` folder to `C:\xampp\htdocs\cubetech`.
2. Open the XAMPP Control Panel and start **Apache** and **MySQL**.
3. Install the database and demo data with one of these:
   * **One click:** open <http://localhost/cubetech/setup.php> and click **Install database**, or
   * **phpMyAdmin:** <http://localhost/phpmyadmin> → Import → choose `database.sql` → Go.
4. Open <http://localhost/cubetech/>.

On first run `config.php` creates `.env` from `.env.example` with a random `APP_KEY`. Change the `DB_*` values in `.env` if your MySQL user or password isn't the XAMPP default (`root` with no password).

### Loading or resetting the seed data

`database.sql` holds the schema and the seed data. **Importing it again wipes everything** and reloads the demo. A signed-in organizer can also reset from `setup.php`.

Demo event times are **relative to the moment you import**: *Cebu Dev Meetup 2026* and *Cebu Startup Pitch Night* are always happening today, so check-in works right away. If you test the next day, reset the demo data or edit the event dates.

### Demo staff logins

| Role | Username | Password | Can do |
|---|---|---|---|
| Organizer | `admin` | `admin123` | Everything: events, attendees, waitlist, CSV, staff accounts, audit log, email outbox, check-in |
| Door staff | `door` | `door123` | Check-in screen only |

Staff sign-in: <http://localhost/cubetech/staff/login.php> (also linked in the footer). Change these passwords before using the app for real.

### Demo tickets for testing

| Event | Ticket code | Name / email | Status |
|---|---|---|---|
| Cebu Dev Meetup 2026 | `CDM-GBU8-2MEC-Z4UN` | Andrea Villanueva / andrea.villanueva@example.com | Confirmed |
| Cebu Dev Meetup 2026 | `CDM-NA8H-9H84-FMKD` | Carlo Mendoza / carlo.mendoza@example.com | Confirmed |
| Cebu Dev Meetup 2026 | `CDM-42WK-9CKY-TURJ` | Patricia Lim | Checked in |
| Cebu Dev Meetup 2026 | `CDM-KFAZ-6VKM-H2D7` | Rafael Gomez | Cancelled |
| Cebu Startup Pitch Night (**full**, 10/10) | `CSP-2UUK-XU67-SRWQ` | Kristine Ramos / kristine.ramos@example.com | Confirmed |
| Cebu Startup Pitch Night | `CSP-AY7U-5Z2X-2EM2` | Bianca Torres | Waitlisted #1 |
| Cebu Startup Pitch Night | `CSP-BGVF-5VJK-4DPK` | Enzo Morales | Waitlisted #2 |
| Intro to Laravel Workshop (in 14 days) | `ITL-TJQK-V5W5-PH8R` | Grace Flores | Confirmed |

Also seeded: *Cebu Hackathon 2027* (Draft, hidden from attendees) and *Cebu JS Night* (ended last week, shown as "Ended").

## Data model

```
staff_users    id, name, username (unique), password_hash (bcrypt), role (organizer|door_staff), is_active, created_at, last_login_at
events         id, title, description, venue, starts_at, ends_at, capacity (>= 1), status (draft|published|closed), created_by, created_at, updated_at
registrations  id, event_id, full_name, email, mobile, ticket_code (unique), status (confirmed|checked_in|waitlisted|cancelled),
               registered_at, confirmed_at, checked_in_at, checked_in_by, cancelled_at, cancelled_by,
               checkin_undone_at, checkin_undone_by,
               active_email (generated: email, or NULL when cancelled), UNIQUE (event_id, active_email)
audit_logs     id, staff_user_id, actor, action, target_type, target_id, details, ip_address, created_at
rate_limits    id, bucket, ident, created_at
email_outbox   id, registration_id, to_email, subject, body_html, status (sent|failed|logged), error, created_at
```

* All times are stored and shown in Cebu time (Asia/Manila, UTC+8). PHP's timezone and the MySQL connection (`SET time_zone = '+08:00'`) are both set in `config.php`. Times on screen use the 12-hour clock with AM/PM.
* Capacity counts `confirmed` + `checked_in`. `waitlisted` and `cancelled` don't count.

## Project structure

```
config.php              .env loader + MySQL connection ($conn) + session
database.sql            schema + seed data
setup.php               one-click installer / demo reset
index.php               event list (upcoming + past "Ended")
event.php               event details + Register / Full / Join waitlist
register.php            registration form (server + client validation)
ticket.php              ticket page with QR, waitlist position, attendee cancel
lookup.php              find my ticket (code + email or mobile)
qr.php                  QR image for emails (signed link)
staff/                  login, dashboard, events, event form, attendees, CSV export,
                        check-in screen + JSON API, staff accounts, audit log, email outbox
includes/               functions, business rules (registrations.php), QR generator, mailer, layout
assets/                 style.css, app.js (form validation), checkin.js (scanner)
```

## Completed features

**Level 1**
* Event list with title, date, start and end time (Cebu time), venue and "12 of 30 spots left". Full events show "Full". Past events are shown as "Ended".
* Details page with description and a Register button that is disabled and shows "Full" when the event is full.
* Registration form (full name, email, PH mobile `09XXXXXXXXX`) with error messages next to each field, checked in the browser **and** on the server.
* Unique ticket codes such as `CDM-7K3P-9QXW-M2RT`. The same email can't register twice for the same event. Capacity is re-checked on the server while saving.
* Mobile-friendly ticket page with event, date, time, venue, name, ticket code and QR code. It can be printed or saved as a PDF.
* Ticket lookup with ticket code + email **or** mobile. A wrong combination shows "Ticket not found" and no details.
* Staff sign-in with bcrypt hashes. Staff pages are protected on the server: signed-out visitors are redirected to sign-in, and door staff get 403 on organizer pages.
* Check-in screen: pick an event, type or scan a code, and get one clear result with text and colour: **Checked in** (green, with name), **Already checked in** (yellow, with first check-in time), **Invalid ticket** (red).
* Attendee list with name, email, mobile, ticket code, registered at and status. Shows registered/capacity and checked-in/registered totals. Search by name, ticket code or email.

**Level 2**
* Event management (organizer): create, edit and close events. Statuses are Draft, Published and Closed. Only Published events are visible to attendees, and Closed events stop new registrations.
* Validation: end must be after start, and capacity must be at least 1. Capacity can't go below the current registrations (checked inside a row lock).
* Waitlist: on a full event attendees join the waitlist and see "You are #3 on the waitlist". There's no QR until they're confirmed. Scanning a waitlisted ticket shows "On waitlist — not confirmed". The organizer promotes the first person when a spot is free.
* Cancellation by the attendee (from the ticket page) or by staff (from the attendee list). It frees a spot, and a scan then shows "Cancelled". Checked-in registrations can't be cancelled.
* Dashboard per event: capacity, confirmed, waitlisted, cancelled, checked in, and no-shows (only after the event starts).
* Live "18 / 25 checked in" counter. It updates after each scan and refreshes every 5 seconds for scans from other devices.
* CSV export per event. It opens in Excel with UTF-8 characters intact and keeps the leading `0` on mobile numbers.
* Undo check-in from the check-in screen or the attendee list. Who undid it and when is saved on the registration and in the audit log.

**Level 3 (5 of 6)**
* **12 Camera scanning.** Start the camera on the check-in screen. Each result shows for about 2 seconds, then the scanner is ready for the next ticket on its own. After a code is read, the same code is ignored for 4 seconds. If it is still held in front of the camera after that, it is read again and shows "Already checked in". Typing the code by hand (or using a USB/Bluetooth scanner) still works if the camera is blocked.
* **13 Race conditions.** See [Race conditions](#race-conditions-item-13) below.
* **14 Secure QR content.** Ticket codes have 12 random characters from a 31-character alphabet (about 59 bits, made with `random_int`), so sequential or edited codes never match. Ticket page links carry an HMAC-SHA256 signature (`APP_KEY`), so knowing a code doesn't reveal the ticket details. Lookups, check-in scans and sign-ins are rate limited (see [Rate limits](#rate-limits)).
* **15 Email confirmation.** An email is sent on registration, on joining the waitlist and on promotion from the waitlist. It includes the ticket details, a QR image and a ticket link. Every email is saved in **Staff → Email outbox**. Put SMTP sandbox credentials (for example Mailtrap) in `.env` to also deliver them. Credentials are never in the code.
* **16 Roles and audit log.** Organizers manage events and staff; door staff can only use check-in. The audit log records event create/edit (with what changed), cancellation, waitlist promotion, check-in, undo check-in and staff account changes, each with who, what and when.

### Race conditions (item 13)

* **Last spot:** registration runs in a transaction that first locks the event row (`SELECT ... FROM events WHERE id = ? FOR UPDATE`). Concurrent registrations for the same event wait in line, and each one re-counts `confirmed + checked_in` and re-checks the email before inserting. Only one request can take the last spot; the rest get "Full" and can join the waitlist. A unique index on `(event_id, active_email)` is a second guard against duplicate emails. Promotion and capacity edits use the same row lock.
* **Double scan:** check-in is one atomic statement, `UPDATE registrations SET status='checked_in' ... WHERE id = ? AND status = 'confirmed'`. Only the request that changes the row (`affected_rows = 1`) shows "Checked in". Every other device gets "Already checked in" with the first time.
* Tested: 12 simultaneous registrations for 1 remaining spot gave exactly 1 confirmed and 11 "Full". 8 simultaneous scans of one ticket gave exactly 1 "Checked in" and 7 "Already checked in".

### Rate limits

Attempts are stored in the `rate_limits` table, so the limits hold across browsers and devices. Each limit is a sliding window: an attempt counts until it is older than the window.

| What | Counted | Limit | When the limit is reached |
|---|---|---|---|
| Ticket lookup (`lookup.php`) | **Failed lookups only**, per IP address | 10 in 10 minutes | Every lookup from that IP, correct ones included, shows "Too many attempts" until the oldest failure is more than 10 minutes old |
| Check-in scans | Every scan, per staff account | 60 per minute | The scan shows a red "Too many scans" result |
| Staff sign-in | Failed sign-ins only, per IP address | 10 in 15 minutes | Sign-in from that IP is refused until the window passes |

Successful lookups are never counted. At a venue where many phones share one Wi-Fi IP address, any number of attendees can open their tickets. Only repeated wrong code/email combinations from that IP can trigger the limit.

## Rules enforced on the server

* Capacity counts Confirmed and Checked in only.
* One active registration per email per event (case-insensitive). Cancelled registrations don't block registering again.
* Check-in runs two checks in order: (1) does the code belong to the selected event (if not, "Invalid ticket"), then (2) its status. Only a Confirmed ticket is checked in, and only on the event day; otherwise the screen says "Not the event day".
* A ticket checks in once. A second scan shows "Already checked in" with the first time.
* All SQL uses prepared statements. Every form has a CSRF token. All output is HTML-escaped. `.env`, `database.sql`, `includes/` and this README are blocked from the web by `.htaccess`.

## Email setup (optional)

With `MAIL_HOST` empty, emails are only saved to the outbox page. To deliver them to a Mailtrap sandbox inbox, fill these in `.env`:

```
MAIL_HOST=sandbox.smtp.mailtrap.io
MAIL_PORT=2525
MAIL_ENCRYPTION=tls
MAIL_USERNAME=your-mailtrap-username
MAIL_PASSWORD=your-mailtrap-password
```

The outbox shows whether each email was sent and the SMTP error if it failed.

## Known limitations

* **Not deployed** (Level 3 item 17). Phone cameras need HTTPS, so on a phone opening the app at `http://<your-PC-IP>/cubetech` the camera is blocked. Typing the code still works. Camera scanning works on `http://localhost` and on any HTTPS deployment.
* Lookup limits are per IP address. On shared venue Wi-Fi, 10 wrong code/email combinations from anyone on that network block lookups for everyone on it for up to 10 minutes (correct lookups never count toward the limit). If the deployed site sits behind a proxy or CDN that hides the visitor's address, all visitors look like one IP, so the host must pass the real client IP to PHP.
* Camera scanning loads jsQR from a CDN (jsDelivr, falling back to unpkg) when the browser has no built-in `BarcodeDetector`, so it needs internet in that case. Manual entry and USB scanners work offline.
* The QR image in emails points to `APP_URL`. Email clients outside your PC can't load `http://localhost`, and Gmail doesn't show SVG images. Set `APP_URL` to the public address after deploying. The "View your ticket" link always works on the same network.
* The SMTP client was tested against a local test server (AUTH LOGIN, plain connection). STARTTLS against Mailtrap was not tested because it needs real credentials.
* One date + start/end time per event. Recurring events and paid tickets are out of scope.

## Acceptance checklist

Tested on Nov 25, 2026 with XAMPP (PHP 8.3, MariaDB 10.11): an automated HTTP test script (55/55 passed) plus manual checks in a Chromium browser at phone size (375×812).

Checks that need a real phone, the deployed HTTPS site or a sandbox inbox stay **Pending** until they have been done. Their results go in the [Phone and email test record](#phone-and-email-test-record) below.

**Level 1**

| Check | Result |
|---|---|
| Register for Cebu Dev Meetup 2026 on a phone-sized screen and see a ticket with a QR code | Pass |
| Scan the QR with a phone QR app: it shows the same ticket code | **Pending** (no physical phone test yet). Locally, software decoders read the same code as the printed ticket: jsQR 91/91 random codes and sizes; html5-qrcode and OpenCV read real tickets. |
| Refresh, then find the ticket again with ticket code + email | Pass |
| Wrong ticket code or wrong email shows "Ticket not found" and no details | Pass |
| Same email twice for one event is blocked | Pass |
| Bad email + 10-digit mobile both show errors | Pass (in the browser and on the server) |
| Seeded full event shows "Full" and the Register button is disabled | Pass |
| Staff page while signed out redirects to sign-in | Pass |
| Valid ticket: green "Checked in" with the name | Pass |
| Same ticket again: yellow "Already checked in" with the first time | Pass |
| Made-up code and real code from another event: "Invalid ticket" | Pass |
| Attendee list shows correct registered and checked-in totals | Pass |

**Level 2**

| Check | Result |
|---|---|
| Draft event is hidden from attendees; publishing makes it appear | Pass |
| End time before start time is blocked | Pass |
| Lowering capacity below registered is blocked with a message | Pass |
| Join the waitlist of a full event and see your position | Pass |
| Scan a waitlisted ticket: "On waitlist — not confirmed" | Pass |
| Cancel a confirmed registration: spots left goes up by 1; scan shows "Cancelled" | Pass |
| Promote the first waitlisted person: they now check in | Pass |
| Cancelling a checked-in registration is blocked | Pass |
| Undo a check-in: the ticket can be checked in again and the undo is recorded | Pass |
| Dashboard counts match the attendee list; CSV export opens in Excel | Pass for counts and CSV format (UTF-8 BOM, mobile kept as text). Not yet opened in Excel itself. |

**Level 3**

| Check | Result |
|---|---|
| Scan a ticket from another phone's screen with the camera: correct result, then ready for the next scan | **Pending** (no physical camera test yet). Locally, with a simulated camera stream at phone size: correct name shown, ready for the next ticket 2.2 s later. |
| Two browsers register for the last spot at once: only one gets it | Pass (12 at once, 1 confirmed) |
| Two devices check in the same ticket at once: only one says "Checked in" | Pass (8 at once, 1 checked in) |
| A sequential or edited ticket code is never valid | Pass |
| Shared Wi-Fi lookup: from one IP, 16 valid lookups within 5 minutes all open; after 15 wrong combinations the next attempt is limited; lookup works again after the window | Pass (automated HTTP test: 16/16 opened; the limit started at the 11th wrong attempt; window expiry simulated by back-dating the stored attempts by 11 minutes) |
| Registration and promotion emails arrive in the sandbox inbox with the QR or ticket link | **Pending** (no sandbox inbox test yet). Locally, the registration, waitlist and promotion emails are saved in the outbox, and each link opens the right ticket with its current status. Also sent through a local test SMTP server. |
| Door staff can't create or edit events; Organizer can | Pass (door staff get 403 on every organizer page and action) |
| Deployed link works on a phone over HTTPS, including the camera | **Pending** (not deployed yet) |

## Phone and email test record

Fill this in after deploying. Keep a check **Pending** until it has actually been done.

* **HTTPS URL:** Pending (not deployed yet)
* **Test date:** Pending
* **Phones and browsers:** Pending

Before testing, set `APP_URL` in `.env` to the HTTPS address (email links and QR images use it), set `APP_DEBUG=false`, and put the Mailtrap sandbox credentials in `.env` (see [Email setup](#email-setup-optional)).

| Check | Phone and browser | Observed result | Status |
|---|---|---|---|
| Register on a phone over HTTPS and display the ticket | — | — | Pending |
| Scan the displayed ticket with a second phone's regular QR app; the decoded value matches the printed ticket code | — | — | Pending |
| Sign in as door staff on a phone, select the event and scan the displayed ticket with the site camera; the attendee name is shown | — | — | Pending |
| After about 2 seconds the scanner is ready for another ticket | — | — | Pending |
| Registration email arrives in the sandbox inbox; its link opens the correct ticket with its current status | — | — | Pending |
| Waitlist promotion email arrives in the sandbox inbox; its link opens the correct ticket with its current status | — | — | Pending |
