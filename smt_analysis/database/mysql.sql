-- SMT Analytics / Daily CSAT
-- Import with phpMyAdmin, MySQL Workbench, or the mysql command-line client.
-- This file creates structure only: no default accounts and no private workbook data.
-- Uses MySQL/MariaDB InnoDB tables. Use an account allowed to create the database.
-- If your host creates the database for you, change/omit CREATE DATABASE and USE.

CREATE DATABASE IF NOT EXISTS `smt_analysis`
  CHARACTER SET utf8mb4 COLLATE utf8mb4_bin;

USE `smt_analysis`;

CREATE TABLE IF NOT EXISTS `users` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(120) NOT NULL,
  `email` VARCHAR(190) NOT NULL,
  `password_hash` VARCHAR(255) NOT NULL,
  `role` VARCHAR(10) NOT NULL,
  `active` INT NOT NULL DEFAULT 1,
  `created_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `users_email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

CREATE TABLE IF NOT EXISTS `imports` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `filename` VARCHAR(255) NOT NULL,
  `file_hash` VARCHAR(64) NOT NULL,
  `uploaded_by` INT UNSIGNED NOT NULL,
  `row_count` INT NOT NULL,
  `date_from` DATE NOT NULL,
  `date_to` DATE NOT NULL,
  `created_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `imports_file_hash` (`file_hash`),
  KEY `imports_uploaded_by` (`uploaded_by`),
  CONSTRAINT `imports_uploaded_by_foreign`
    FOREIGN KEY (`uploaded_by`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

CREATE TABLE IF NOT EXISTS `responses` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `import_id` INT UNSIGNED NOT NULL,
  `source_row` INT NOT NULL,
  `response_date` DATE NOT NULL,
  `team` VARCHAR(120) NOT NULL,
  `agent_name` VARCHAR(255) NOT NULL,
  `channel` VARCHAR(120) NOT NULL,
  `category` VARCHAR(255) NOT NULL,
  `csat_id` VARCHAR(100) NOT NULL,
  `good` INT NOT NULL,
  `bad` INT NOT NULL,
  `is_excluded` INT NOT NULL,
  `handling_seconds` DOUBLE NULL,
  `raw_json` TEXT NOT NULL,
  PRIMARY KEY (`id`),
  KEY `responses_import_id_response_date` (`import_id`, `response_date`),
  UNIQUE KEY `responses_import_id_csat_id` (`import_id`, `csat_id`),
  CONSTRAINT `responses_import_id_foreign`
    FOREIGN KEY (`import_id`) REFERENCES `imports` (`id`)
    ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

-- After configuring .env, run:
--   php spark migrate
--   php spark csat:user
-- Migrate also creates CodeIgniter's migrations tracking table. The initial
-- migration safely skips the three matching tables already created above.
-- Create your own administrator via csat:user; do not store plaintext passwords.
