-- Sponsorship enquiries from the "Become a Sponsor" form.
-- Run once in phpMyAdmin on an existing install; setup.php creates it on a
-- fresh one.

CREATE TABLE IF NOT EXISTS sponsor_enquiries (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT,

  -- Quoted back to the enquirer so they have something to refer to, and shown
  -- in the admin list. Not a database id: those leak how many you have had.
  reference    VARCHAR(32)  NOT NULL,

  company      VARCHAR(160) NOT NULL,
  contact_name VARCHAR(120) NOT NULL,
  designation  VARCHAR(120) DEFAULT NULL,
  email        VARCHAR(190) NOT NULL,
  mobile       VARCHAR(20)  NOT NULL,
  website      VARCHAR(190) DEFAULT NULL,
  city         VARCHAR(90)  DEFAULT NULL,

  -- Free text rather than an ENUM: the tiers are a sales decision and will be
  -- renamed long before anyone wants to run an ALTER on a live table.
  tier         VARCHAR(40)  NOT NULL,
  budget       VARCHAR(40)  DEFAULT NULL,
  message      TEXT         DEFAULT NULL,

  status       ENUM('new','contacted','confirmed','declined') NOT NULL DEFAULT 'new',
  notes        TEXT         DEFAULT NULL,

  ip_address   VARCHAR(45)  DEFAULT NULL,
  created_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,

  PRIMARY KEY (id),
  UNIQUE KEY uniq_reference (reference),
  KEY idx_status (status),
  KEY idx_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
