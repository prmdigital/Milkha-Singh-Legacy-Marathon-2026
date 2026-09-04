-- Admin users and roles. Run once in phpMyAdmin if the tables already exist;
-- setup.php creates all of this on a fresh install.

CREATE TABLE IF NOT EXISTS admin_users (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  username      VARCHAR(40)  NOT NULL,
  full_name     VARCHAR(120) NOT NULL,
  email         VARCHAR(190) DEFAULT NULL,

  -- Only ever a hash. Nothing in this system stores or can recover a password.
  password_hash VARCHAR(255) NOT NULL,

  -- owner   : everything, including managing these users
  -- manager : the whole registration desk — export, ID proofs, mark as paid
  -- viewer  : read the list and the records, nothing else. No export and no ID
  --           proofs, because those are the two ways bulk personal data leaves
  --           the building.
  role          ENUM('owner','manager','viewer') NOT NULL DEFAULT 'viewer',

  -- Deactivated rather than deleted: an audit entry that points at a user who
  -- no longer exists tells you nothing when you most need it to.
  is_active     TINYINT(1)   NOT NULL DEFAULT 1,

  -- Forces a change at next sign-in, used when an owner resets someone.
  must_change   TINYINT(1)   NOT NULL DEFAULT 0,

  created_by    INT UNSIGNED DEFAULT NULL,
  created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_login_at DATETIME     DEFAULT NULL,

  PRIMARY KEY (id),
  UNIQUE KEY uniq_username (username),
  KEY idx_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Who did it, not just what was done. Without this the audit log cannot answer
-- the only question it exists for once more than one person can sign in.
ALTER TABLE admin_audit
  ADD COLUMN actor VARCHAR(40) DEFAULT NULL AFTER action;

-- Ties a failed sign-in to the username that was tried, so a run of attempts
-- against one account is visible rather than blending into the IP counter.
ALTER TABLE admin_login_attempts
  ADD COLUMN username VARCHAR(40) DEFAULT NULL AFTER ip_address;
