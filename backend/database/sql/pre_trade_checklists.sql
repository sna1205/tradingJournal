-- Pre-trade checklist schema (MySQL 8+)
-- Mirrors Laravel migrations:
-- 2026_02_23_000022_create_checklists_table
-- 2026_02_23_000023_create_checklist_items_table
-- 2026_02_23_000024_create_trade_checklist_responses_table
-- 2026_02_23_000025_add_checklist_incomplete_to_trades_table

CREATE TABLE `checklists` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` BIGINT UNSIGNED NULL,
  `account_id` BIGINT UNSIGNED NULL,
  `name` VARCHAR(160) NOT NULL,
  `scope` ENUM('global', 'account', 'strategy') NOT NULL DEFAULT 'global',
  `enforcement_mode` ENUM('soft', 'strict') NOT NULL DEFAULT 'soft',
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` TIMESTAMP NULL DEFAULT NULL,
  `updated_at` TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `checklists_user_scope_active_index` (`user_id`, `scope`, `is_active`),
  KEY `checklists_account_active_index` (`account_id`, `is_active`),
  CONSTRAINT `checklists_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `checklists_account_id_foreign` FOREIGN KEY (`account_id`) REFERENCES `accounts` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `checklist_items` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `checklist_id` BIGINT UNSIGNED NOT NULL,
  `order_index` INT UNSIGNED NOT NULL DEFAULT 0,
  `title` VARCHAR(220) NOT NULL,
  `type` ENUM('checkbox', 'dropdown', 'number', 'text', 'scale') NOT NULL,
  `required` TINYINT(1) NOT NULL DEFAULT 0,
  `category` VARCHAR(80) NOT NULL DEFAULT 'General',
  `help_text` TEXT NULL,
  `config` JSON NULL,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` TIMESTAMP NULL DEFAULT NULL,
  `updated_at` TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `checklist_items_order_active_index` (`checklist_id`, `order_index`, `is_active`),
  CONSTRAINT `checklist_items_checklist_id_foreign` FOREIGN KEY (`checklist_id`) REFERENCES `checklists` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `trade_checklist_responses` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `trade_id` BIGINT UNSIGNED NOT NULL,
  `checklist_id` BIGINT UNSIGNED NOT NULL,
  `checklist_item_id` BIGINT UNSIGNED NOT NULL,
  `value` JSON NULL,
  `is_completed` TINYINT(1) NOT NULL DEFAULT 0,
  `completed_at` TIMESTAMP NULL DEFAULT NULL,
  `created_at` TIMESTAMP NULL DEFAULT NULL,
  `updated_at` TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `trade_checklist_unique_trade_item` (`trade_id`, `checklist_item_id`),
  KEY `trade_checklist_trade_item_index` (`trade_id`, `checklist_item_id`),
  CONSTRAINT `trade_checklist_responses_trade_id_foreign` FOREIGN KEY (`trade_id`) REFERENCES `trades` (`id`) ON DELETE CASCADE,
  CONSTRAINT `trade_checklist_responses_checklist_id_foreign` FOREIGN KEY (`checklist_id`) REFERENCES `checklists` (`id`) ON DELETE CASCADE,
  CONSTRAINT `trade_checklist_responses_checklist_item_id_foreign` FOREIGN KEY (`checklist_item_id`) REFERENCES `checklist_items` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE `trades`
  ADD COLUMN `checklist_incomplete` TINYINT(1) NOT NULL DEFAULT 0 AFTER `followed_rules`,
  ADD KEY `trades_checklist_incomplete_date_index` (`checklist_incomplete`, `date`);
