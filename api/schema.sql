-- Milkha Singh Legacy Marathon 2026 — registrations table
--
-- Run once in Hostinger > Databases > phpMyAdmin > SQL tab.
--
-- A row is written BEFORE the runner is sent to Razorpay, with status
-- 'pending'. That way a payment can always be traced back to a person even if
-- the browser dies mid-checkout — the webhook later flips it to 'paid'.

CREATE TABLE IF NOT EXISTS registrations (
  id                  INT UNSIGNED NOT NULL AUTO_INCREMENT,
  registration_id     VARCHAR(32)  NOT NULL,

  -- Runner
  category            VARCHAR(16)  NOT NULL,   -- half | mini | cause | para
  full_name           VARCHAR(120) NOT NULL,
  email               VARCHAR(190) NOT NULL,
  mobile              VARCHAR(20)  NOT NULL,
  age                 SMALLINT UNSIGNED NOT NULL,   -- on race day, derived from dob
  dob                 DATE         DEFAULT NULL,
  gender              VARCHAR(16)  NOT NULL,
  city                VARCHAR(90)  NOT NULL,
  tshirt_size         VARCHAR(8)   NOT NULL,

  -- Which photo ID the runner will bring, plus the filename of the scan they
  -- uploaded. Only the TYPE and the file are stored, never an ID number.
  -- The file itself lives OUTSIDE public_html — see the note at the foot.
  id_proof_type       VARCHAR(20)  NOT NULL,
  id_proof_file       VARCHAR(120) DEFAULT NULL,

  emergency_name      VARCHAR(120) DEFAULT NULL,
  emergency_phone     VARCHAR(20)  DEFAULT NULL,

  -- Money, always in paise. 0 for the free 1 KM category.
  amount_paise        INT UNSIGNED NOT NULL DEFAULT 0,
  early_bird          TINYINT(1)   NOT NULL DEFAULT 0,

  -- pending  : order created, runner sent to Razorpay
  -- paid     : signature verified (or webhook confirmed)
  -- free     : free category, no payment needed
  -- failed   : Razorpay reported the payment failed
  status              ENUM('pending','paid','free','failed') NOT NULL DEFAULT 'pending',

  razorpay_order_id   VARCHAR(64)  DEFAULT NULL,
  razorpay_payment_id VARCHAR(64)  DEFAULT NULL,

  receipt_emailed     TINYINT(1)   NOT NULL DEFAULT 0,
  ip_address          VARCHAR(45)  DEFAULT NULL,

  created_at          DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  paid_at             DATETIME     DEFAULT NULL,

  PRIMARY KEY (id),
  UNIQUE KEY uniq_registration_id (registration_id),
  -- Unique so a replayed webhook cannot create a second paid row.
  UNIQUE KEY uniq_order_id (razorpay_order_id),
  KEY idx_email (email),
  KEY idx_status (status),
  KEY idx_category (category)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- ON ID PROOF
--
-- Only id_proof_type and a filename are stored, never an ID number. That
-- deliberately avoids holding Aadhaar or PAN numbers, which carry legal
-- obligations in India.
--
-- id_proof_file names a file in the upload directory, which MUST sit outside
-- public_html. These are identity documents: anything under the web root is
-- one guessed URL away from being downloaded by anyone. To view one, fetch it
-- through an authenticated admin page, never by linking to it.
--
-- Consider deleting the files once bib collection is done; you have no reason
-- to keep scans of people's IDs after the race.
-- ---------------------------------------------------------------------------
