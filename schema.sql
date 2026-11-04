-- Solution Summary Builder: MySQL 5.7+ / MariaDB 10.3+
-- setup.php runs this automatically; you can also import it with phpMyAdmin or:
--   mysql -u root -p solution_builder < schema.sql

CREATE TABLE IF NOT EXISTS users (
  id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  username       VARCHAR(64)  NOT NULL,
  full_name      VARCHAR(120) NOT NULL DEFAULT '',
  email          VARCHAR(190) NULL,
  password_hash  VARCHAR(255) NOT NULL,
  role           ENUM('admin','architect') NOT NULL DEFAULT 'architect',
  is_active      TINYINT(1)   NOT NULL DEFAULT 1,
  failed_logins  INT UNSIGNED NOT NULL DEFAULT 0,
  locked_until   DATETIME     NULL,
  last_login_at  DATETIME     NULL,
  created_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_users_username (username)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- One row per Solution Summary / BOM project. `state` holds every input of the
-- 7-step builder as JSON; the other columns are copies kept for listing and reporting.
CREATE TABLE IF NOT EXISTS projects (
  id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id        INT UNSIGNED NOT NULL,
  customer_name  VARCHAR(190) NOT NULL DEFAULT '',
  project_title  VARCHAR(255) NOT NULL DEFAULT '',
  provider       VARCHAR(32)  NOT NULL DEFAULT '',
  version        VARCHAR(20)  NOT NULL DEFAULT '',
  monthly_usd    DECIMAL(14,2) NOT NULL DEFAULT 0,
  one_time_usd   DECIMAL(14,2) NOT NULL DEFAULT 0,
  person_days    DECIMAL(10,1) NOT NULL DEFAULT 0,
  state          LONGTEXT     NULL,
  step           TINYINT UNSIGNED NOT NULL DEFAULT 0,
  rev            INT UNSIGNED NOT NULL DEFAULT 0,
  created_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_projects_user (user_id, updated_at),
  CONSTRAINT fk_projects_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- BOM workbook templates (.xlsx). scope 'user' = one architect's own template;
-- scope 'company' (user_id NULL) = the default every architect gets.
CREATE TABLE IF NOT EXISTS bom_templates (
  id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  scope          ENUM('user','company') NOT NULL,
  user_id        INT UNSIGNED NULL,
  filename       VARCHAR(255) NOT NULL,
  size_bytes     INT UNSIGNED NOT NULL,
  sheets         TEXT         NOT NULL,
  data           LONGBLOB     NOT NULL,
  uploaded_by    INT UNSIGNED NULL,
  uploaded_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_tpl_owner (scope, user_id),
  CONSTRAINT fk_tpl_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
