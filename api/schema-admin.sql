-- Milkha Singh Legacy Marathon 2026 — admin panel tables
--
-- Run once in Hostinger > Databases > phpMyAdmin > SQL tab, AFTER schema.sql.
--
-- There is no users table on purpose. The single admin credential lives in
-- marathon-config.php above the webroot, so a database dump does not hand
-- anyone a password hash to grind offline.

-- Throttles login attempts. Without this an automated script can try passwords
-- against the panel all night at whatever rate the server will answer.
CREATE TABLE IF NOT EXISTS admin_login_attempts (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  ip_address  VARCHAR(45)  NOT NULL,
  attempted_at DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  succeeded   TINYINT(1)   NOT NULL DEFAULT 0,
  PRIMARY KEY (id),
  KEY idx_ip_time (ip_address, attempted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Records who looked at which ID proof and when. These are identity documents;
-- if one ever leaks you need to be able to say who opened it.
CREATE TABLE IF NOT EXISTS admin_audit (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  action      VARCHAR(40)  NOT NULL,   -- view_id_proof | export_csv | login
  subject     VARCHAR(64)  DEFAULT NULL,
  ip_address  VARCHAR(45)  DEFAULT NULL,
  created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
