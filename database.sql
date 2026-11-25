-- =====================================================================
-- CubeTech Events - Event Registration with QR Check-in
-- Schema + demo seed data  (MariaDB 10.2+ / MySQL 5.7+, XAMPP)
--
-- Install (pick one):
--   * Open http://localhost/cubetech/setup.php and click "Install database"
--   * phpMyAdmin > Import > choose this file > Go
--
-- Demo staff logins (passwords are stored as bcrypt hashes):
--   Organizer : admin / admin123
--   Door staff: door  / door123
--
-- Demo event times are RELATIVE to the moment you import this file, so
-- "Cebu Dev Meetup 2026" is always happening today and can be checked in.
-- WARNING: importing this file again DELETES all data and reloads the demo.
-- =====================================================================

SET NAMES utf8mb4;
SET time_zone = '+08:00';            -- Cebu time (Asia/Manila, UTC+8)
SET FOREIGN_KEY_CHECKS = 0;

CREATE DATABASE IF NOT EXISTS `cubetech_events` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE `cubetech_events`;

DROP TABLE IF EXISTS email_outbox;
DROP TABLE IF EXISTS rate_limits;
DROP TABLE IF EXISTS audit_logs;
DROP TABLE IF EXISTS registrations;
DROP TABLE IF EXISTS events;
DROP TABLE IF EXISTS staff_users;

SET FOREIGN_KEY_CHECKS = 1;

-- ---------------------------------------------------------------------
-- Staff accounts (organizer = manages everything, door_staff = check-in only)
-- ---------------------------------------------------------------------
CREATE TABLE staff_users (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name          VARCHAR(100) NOT NULL,
  username      VARCHAR(50)  NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  role          ENUM('organizer','door_staff') NOT NULL DEFAULT 'door_staff',
  is_active     TINYINT(1)   NOT NULL DEFAULT 1,
  created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_login_at DATETIME     NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_staff_username (username)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Events
-- ---------------------------------------------------------------------
CREATE TABLE events (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  title       VARCHAR(150) NOT NULL,
  description TEXT         NOT NULL,
  venue       VARCHAR(200) NOT NULL,
  starts_at   DATETIME     NOT NULL,                       -- Cebu time
  ends_at     DATETIME     NOT NULL,                       -- Cebu time
  capacity    INT UNSIGNED NOT NULL,
  status      ENUM('draft','published','closed') NOT NULL DEFAULT 'draft',
  created_by  INT UNSIGNED NULL,
  created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at  DATETIME     NULL,
  PRIMARY KEY (id),
  KEY idx_events_status_start (status, starts_at),
  CONSTRAINT chk_events_capacity CHECK (capacity >= 1),
  CONSTRAINT chk_events_times    CHECK (ends_at > starts_at),
  CONSTRAINT fk_events_created_by FOREIGN KEY (created_by) REFERENCES staff_users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Registrations / tickets
--   status: confirmed | checked_in   -> count toward capacity
--           waitlisted | cancelled   -> do not count
-- ---------------------------------------------------------------------
CREATE TABLE registrations (
  id                INT UNSIGNED NOT NULL AUTO_INCREMENT,
  event_id          INT UNSIGNED NOT NULL,
  full_name         VARCHAR(120) NOT NULL,
  email             VARCHAR(190) NOT NULL,                 -- stored in lowercase
  mobile            CHAR(11)     NOT NULL,                 -- 09XXXXXXXXX
  ticket_code       VARCHAR(24)  NOT NULL,                 -- e.g. CDM-7K3P-9QXW-M2RT (12 random characters)
  status            ENUM('confirmed','checked_in','waitlisted','cancelled') NOT NULL,
  registered_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  confirmed_at      DATETIME     NULL,
  checked_in_at     DATETIME     NULL,
  checked_in_by     INT UNSIGNED NULL,
  cancelled_at      DATETIME     NULL,
  cancelled_by      INT UNSIGNED NULL,                     -- NULL = cancelled by the attendee
  checkin_undone_at DATETIME     NULL,
  checkin_undone_by INT UNSIGNED NULL,
  -- Database-level guard: one ACTIVE registration per email per event.
  -- Cancelled rows become NULL here, so they don't block registering again.
  active_email      VARCHAR(190) AS (IF(status = 'cancelled', NULL, email)) STORED,
  PRIMARY KEY (id),
  UNIQUE KEY uq_reg_ticket_code (ticket_code),
  UNIQUE KEY uq_reg_active_email (event_id, active_email),
  KEY idx_reg_event_status (event_id, status),
  CONSTRAINT fk_reg_event        FOREIGN KEY (event_id)          REFERENCES events (id)      ON DELETE CASCADE,
  CONSTRAINT fk_reg_checked_in   FOREIGN KEY (checked_in_by)     REFERENCES staff_users (id) ON DELETE SET NULL,
  CONSTRAINT fk_reg_cancelled_by FOREIGN KEY (cancelled_by)      REFERENCES staff_users (id) ON DELETE SET NULL,
  CONSTRAINT fk_reg_undone_by    FOREIGN KEY (checkin_undone_by) REFERENCES staff_users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Audit log: who did what, and when
-- ---------------------------------------------------------------------
CREATE TABLE audit_logs (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  staff_user_id INT UNSIGNED NULL,                         -- NULL = attendee (self-service)
  actor         VARCHAR(120) NOT NULL,                     -- name shown in the log
  action        VARCHAR(40)  NOT NULL,
  target_type   VARCHAR(30)  NOT NULL,
  target_id     INT UNSIGNED NULL,
  details       VARCHAR(500) NULL,
  ip_address    VARCHAR(45)  NULL,
  created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_audit_created (created_at),
  KEY idx_audit_action (action),
  CONSTRAINT fk_audit_staff FOREIGN KEY (staff_user_id) REFERENCES staff_users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Rate limiting (ticket lookup, check-in, sign-in)
-- ---------------------------------------------------------------------
CREATE TABLE rate_limits (
  id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  bucket     VARCHAR(30)  NOT NULL,
  ident      VARCHAR(100) NOT NULL,
  created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_rate_lookup (bucket, ident, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Email outbox (every email is recorded here; also sent via SMTP when configured)
-- ---------------------------------------------------------------------
CREATE TABLE email_outbox (
  id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  registration_id INT UNSIGNED NULL,
  to_email        VARCHAR(190) NOT NULL,
  subject         VARCHAR(200) NOT NULL,
  body_html       MEDIUMTEXT   NOT NULL,
  status          ENUM('sent','failed','logged') NOT NULL,
  error           VARCHAR(500) NULL,
  created_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_outbox_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================================
-- SEED DATA
-- =====================================================================

INSERT INTO staff_users (id, name, username, password_hash, role) VALUES
  (1, 'Event Organizer', 'admin', '$2y$10$ZXTYZa9EJXH/hK4Ss.kwpe8bY7XIofzI0kcWiFmabmicKY3ZF2gnK', 'organizer'),
  (2, 'Door Staff',      'door',  '$2y$10$1f/Myqsh/sKfGbBc17Vzvu8Xx7jkxk5wIBpYgd8jsZtJs37QyIGBS', 'door_staff');

-- Times relative to "now" (Cebu time)
SET @now          = NOW();
SET @hour_start   = CAST(DATE_FORMAT(@now, '%Y-%m-%d %H:00:00') AS DATETIME);
SET @meetup_start = @hour_start - INTERVAL 1 HOUR;      -- started about an hour ago
SET @meetup_end   = @meetup_start + INTERVAL 5 HOUR;
SET @pitch_start  = @hour_start;                        -- started this hour
SET @pitch_end    = @hour_start + INTERVAL 4 HOUR;

INSERT INTO events (id, title, description, venue, starts_at, ends_at, capacity, status, created_by, created_at) VALUES
  (1, 'Cebu Dev Meetup 2026',
      'A relaxed meetup for Cebu''s developer community: lightning talks, live demos and plenty of time to network. Bring your laptop and your questions. Free snacks and coffee.',
      'Ayala Center Cebu, Function Room 2', @meetup_start, @meetup_end, 30, 'published', 1, @now - INTERVAL 20 DAY),
  (2, 'Cebu Startup Pitch Night',
      'Ten local founders pitch their startups to a panel of mentors and investors. Small room, so seats are limited. Join the waitlist if it is full.',
      'The Company Cebu, Mandaue City', @pitch_start, @pitch_end, 10, 'published', 1, @now - INTERVAL 18 DAY),
  (3, 'Intro to Laravel Workshop',
      'A hands-on afternoon workshop: build and deploy a small Laravel app from scratch. Bring a laptop with PHP 8.2+ and Composer installed.',
      'University of San Carlos Talamban Campus, Room LB 301', TIMESTAMP(CURDATE() + INTERVAL 14 DAY, '13:00:00'), TIMESTAMP(CURDATE() + INTERVAL 14 DAY, '17:00:00'), 20, 'published', 1, @now - INTERVAL 10 DAY),
  (4, 'Cebu Hackathon 2027',
      'A 12-hour hackathon for students and professionals. Details are still being finalized. (Draft: attendees cannot see this event yet.)',
      'Cebu IT Park, Cebu City', TIMESTAMP(CURDATE() + INTERVAL 45 DAY, '08:00:00'), TIMESTAMP(CURDATE() + INTERVAL 45 DAY, '20:00:00'), 100, 'draft', 1, @now - INTERVAL 2 DAY),
  (5, 'Cebu JS Night',
      'An evening of JavaScript talks: modern frameworks, testing, and performance tips from local engineers.',
      'Ayala Center Cebu, Function Room 1', TIMESTAMP(CURDATE() - INTERVAL 7 DAY, '18:00:00'), TIMESTAMP(CURDATE() - INTERVAL 7 DAY, '21:00:00'), 25, 'published', 1, @now - INTERVAL 30 DAY);

INSERT INTO registrations
  (event_id, full_name, email, mobile, ticket_code, status, registered_at, confirmed_at, checked_in_at, checked_in_by, cancelled_at)
VALUES
  -- Cebu Dev Meetup 2026 (capacity 30): 4 confirmed, 1 checked in, 1 cancelled
  (1, 'Andrea Villanueva', 'andrea.villanueva@example.com', '09171234501', 'CDM-GBU8-2MEC-Z4UN', 'confirmed',  @now - INTERVAL 5 DAY, @now - INTERVAL 5 DAY, NULL, NULL, NULL),
  (1, 'Carlo Mendoza',     'carlo.mendoza@example.com',     '09181234502', 'CDM-NA8H-9H84-FMKD', 'confirmed',  @now - INTERVAL 4 DAY, @now - INTERVAL 4 DAY, NULL, NULL, NULL),
  (1, 'Bea Fernandez',     'bea.fernandez@example.com',     '09201234503', 'CDM-TTQB-ZZ8E-GXNZ', 'confirmed',  @now - INTERVAL 3 DAY, @now - INTERVAL 3 DAY, NULL, NULL, NULL),
  (1, 'Miguel Tan',        'miguel.tan@example.com',        '09271234504', 'CDM-FV6G-6SEZ-SW6C', 'confirmed',  @now - INTERVAL 2 DAY, @now - INTERVAL 2 DAY, NULL, NULL, NULL),
  (1, 'Patricia Lim',      'patricia.lim@example.com',      '09351234505', 'CDM-42WK-9CKY-TURJ', 'checked_in', @now - INTERVAL 2 DAY, @now - INTERVAL 2 DAY, @now - INTERVAL 15 MINUTE, 2, NULL),
  (1, 'Rafael Gomez',      'rafael.gomez@example.com',      '09451234506', 'CDM-KFAZ-6VKM-H2D7', 'cancelled',  @now - INTERVAL 6 DAY, @now - INTERVAL 6 DAY, NULL, NULL, @now - INTERVAL 1 DAY),

  -- Cebu Startup Pitch Night (capacity 10): FULL, plus 2 on the waitlist
  (2, 'Kristine Ramos',    'kristine.ramos@example.com',    '09171112201', 'CSP-2UUK-XU67-SRWQ', 'confirmed',  @now - INTERVAL 9 DAY, @now - INTERVAL 9 DAY, NULL, NULL, NULL),
  (2, 'Paolo Garcia',      'paolo.garcia@example.com',      '09171112202', 'CSP-VMR8-ASD2-XK42', 'confirmed',  @now - INTERVAL 9 DAY, @now - INTERVAL 9 DAY, NULL, NULL, NULL),
  (2, 'Jasmine Uy',        'jasmine.uy@example.com',        '09171112203', 'CSP-6JKQ-NJD8-2CT6', 'confirmed',  @now - INTERVAL 8 DAY, @now - INTERVAL 8 DAY, NULL, NULL, NULL),
  (2, 'Mark Aquino',       'mark.aquino@example.com',       '09171112204', 'CSP-E9NF-Q9TB-M34Y', 'confirmed',  @now - INTERVAL 8 DAY, @now - INTERVAL 8 DAY, NULL, NULL, NULL),
  (2, 'Leah Bautista',     'leah.bautista@example.com',     '09171112205', 'CSP-MUBB-DARS-STQR', 'confirmed',  @now - INTERVAL 7 DAY, @now - INTERVAL 7 DAY, NULL, NULL, NULL),
  (2, 'Joshua Reyes',      'joshua.reyes@example.com',      '09171112206', 'CSP-QMFF-WHFY-GWBR', 'confirmed',  @now - INTERVAL 6 DAY, @now - INTERVAL 6 DAY, NULL, NULL, NULL),
  (2, 'Nicole Castillo',   'nicole.castillo@example.com',   '09171112207', 'CSP-SF5Y-ZXBP-RJT8', 'confirmed',  @now - INTERVAL 5 DAY, @now - INTERVAL 5 DAY, NULL, NULL, NULL),
  (2, 'Adrian Lopez',      'adrian.lopez@example.com',      '09171112208', 'CSP-XP7Y-XGKE-493H', 'confirmed',  @now - INTERVAL 4 DAY, @now - INTERVAL 4 DAY, NULL, NULL, NULL),
  (2, 'Sofia Navarro',     'sofia.navarro@example.com',     '09171112209', 'CSP-NN6J-JFVC-RC3G', 'confirmed',  @now - INTERVAL 3 DAY, @now - INTERVAL 3 DAY, NULL, NULL, NULL),
  (2, 'Daniel Chua',       'daniel.chua@example.com',       '09171112210', 'CSP-4H86-HBMF-DFMN', 'confirmed',  @now - INTERVAL 2 DAY, @now - INTERVAL 2 DAY, NULL, NULL, NULL),
  (2, 'Bianca Torres',     'bianca.torres@example.com',     '09171112211', 'CSP-AY7U-5Z2X-2EM2', 'waitlisted', @now - INTERVAL 1 DAY, NULL, NULL, NULL, NULL),
  (2, 'Enzo Morales',      'enzo.morales@example.com',      '09171112212', 'CSP-BGVF-5VJK-4DPK', 'waitlisted', @now - INTERVAL 12 HOUR, NULL, NULL, NULL, NULL),

  -- Intro to Laravel Workshop (capacity 20)
  (3, 'Grace Flores',      'grace.flores@example.com',      '09221230001', 'ITL-TJQK-V5W5-PH8R', 'confirmed',  @now - INTERVAL 3 DAY, @now - INTERVAL 3 DAY, NULL, NULL, NULL),
  (3, 'Ivan Pascual',      'ivan.pascual@example.com',      '09221230002', 'ITL-JN48-7MSR-RTFU', 'confirmed',  @now - INTERVAL 2 DAY, @now - INTERVAL 2 DAY, NULL, NULL, NULL),
  (3, 'Carla Domingo',     'carla.domingo@example.com',     '09221230003', 'ITL-DJHZ-FFSZ-6KMK', 'confirmed',  @now - INTERVAL 1 DAY, @now - INTERVAL 1 DAY, NULL, NULL, NULL),

  -- Cebu JS Night (ended last week): 3 checked in, 1 no-show
  (5, 'Ramon Dizon',       'ramon.dizon@example.com',       '09331110001', 'CJN-H9GK-V2DH-UZMZ', 'checked_in', @now - INTERVAL 20 DAY, @now - INTERVAL 20 DAY, TIMESTAMP(CURDATE() - INTERVAL 7 DAY, '17:52:00'), 2, NULL),
  (5, 'Trisha Gonzales',   'trisha.gonzales@example.com',   '09331110002', 'CJN-JUTC-JFMY-BCC7', 'checked_in', @now - INTERVAL 18 DAY, @now - INTERVAL 18 DAY, TIMESTAMP(CURDATE() - INTERVAL 7 DAY, '18:04:00'), 2, NULL),
  (5, 'Arvin Salazar',     'arvin.salazar@example.com',     '09331110003', 'CJN-ZZSJ-HZVN-GTZQ', 'checked_in', @now - INTERVAL 15 DAY, @now - INTERVAL 15 DAY, TIMESTAMP(CURDATE() - INTERVAL 7 DAY, '18:11:00'), 1, NULL),
  (5, 'Mae Ocampo',        'mae.ocampo@example.com',        '09331110004', 'CJN-JJ5E-C99J-R7WW', 'confirmed',  @now - INTERVAL 12 DAY, @now - INTERVAL 12 DAY, NULL, NULL, NULL);

INSERT INTO audit_logs (staff_user_id, actor, action, target_type, target_id, details, created_at) VALUES
  (1, 'Event Organizer', 'event_created', 'event', 1, 'Created "Cebu Dev Meetup 2026" (published, capacity 30)', @now - INTERVAL 20 DAY),
  (1, 'Event Organizer', 'event_created', 'event', 2, 'Created "Cebu Startup Pitch Night" (published, capacity 10)', @now - INTERVAL 18 DAY),
  (NULL, 'Attendee', 'registration_cancelled', 'registration', 6, 'Rafael Gomez (CDM-KFAZ-6VKM-H2D7) cancelled their registration for "Cebu Dev Meetup 2026"', @now - INTERVAL 1 DAY),
  (2, 'Door Staff', 'check_in', 'registration', 5, 'Checked in Patricia Lim (CDM-42WK-9CKY-TURJ) for "Cebu Dev Meetup 2026"', @now - INTERVAL 15 MINUTE);
