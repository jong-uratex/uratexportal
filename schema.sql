-- ==============================================================================
-- DATABASE SCHEMA: Uratex Shopify SEO Partner Portal
-- Database: u390249810_seomini
-- Purpose: User Authentication, RBAC (Admin & Editor), Audit Logging,
--          and Product SEO Module (shopify_products) with API Sync & Pagination
-- Compatible with: MySQL 5.7+, MySQL 8.0+, MariaDB 10.3+
-- ==============================================================================

-- 1. Create Database
CREATE DATABASE IF NOT EXISTS `u390249810_seomini`
  DEFAULT CHARACTER SET utf8mb4
  DEFAULT COLLATE utf8mb4_unicode_ci;

USE `u390249810_seomini`;

SET FOREIGN_KEY_CHECKS = 0;

-- ------------------------------------------------------------------------------
-- Table 1: users (Authentication & RBAC)
-- ------------------------------------------------------------------------------
DROP TABLE IF EXISTS `users`;
CREATE TABLE `users` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `username` VARCHAR(50) NOT NULL,
  `email` VARCHAR(100) NOT NULL,
  `password_hash` VARCHAR(255) NOT NULL COMMENT 'Bcrypt hash generated via password_hash()',
  `full_name` VARCHAR(100) NOT NULL,
  `role` ENUM('admin', 'editor') NOT NULL DEFAULT 'editor' COMMENT 'admin = full access, editor = SEO editing only',
  `status` ENUM('active', 'inactive', 'suspended') NOT NULL DEFAULT 'active',
  `store_access` VARCHAR(100) NOT NULL DEFAULT 'retail,business' COMMENT 'Comma-separated store keys user can manage',
  `failed_login_attempts` TINYINT UNSIGNED NOT NULL DEFAULT 0,
  `locked_until` DATETIME NULL DEFAULT NULL,
  `last_login_at` DATETIME NULL DEFAULT NULL,
  `last_login_ip` VARCHAR(45) NULL DEFAULT NULL,
  `remember_token` VARCHAR(100) NULL DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_users_username` (`username`),
  UNIQUE KEY `uq_users_email` (`email`),
  KEY `idx_users_role` (`role`),
  KEY `idx_users_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Portal user accounts and credentials';

-- ------------------------------------------------------------------------------
-- Table 2: user_sessions (Token & Session Tracking)
-- ------------------------------------------------------------------------------
DROP TABLE IF EXISTS `user_sessions`;
CREATE TABLE `user_sessions` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` INT UNSIGNED NOT NULL,
  `session_token` VARCHAR(128) NOT NULL,
  `ip_address` VARCHAR(45) NULL DEFAULT NULL,
  `user_agent` VARCHAR(255) NULL DEFAULT NULL,
  `expires_at` DATETIME NOT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_sessions_token` (`session_token`),
  KEY `idx_sessions_user_id` (`user_id`),
  KEY `idx_sessions_expires_at` (`expires_at`),
  CONSTRAINT `fk_sessions_user_id` FOREIGN KEY (`user_id`)
    REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------------------------
-- Table 3: login_logs (Security Audit Trail)
-- ------------------------------------------------------------------------------
DROP TABLE IF EXISTS `login_logs`;
CREATE TABLE `login_logs` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` INT UNSIGNED NULL DEFAULT NULL,
  `username_attempted` VARCHAR(100) NOT NULL,
  `action` ENUM('login_success', 'login_failed', 'logout', 'password_change', 'account_locked') NOT NULL,
  `ip_address` VARCHAR(45) NULL DEFAULT NULL,
  `user_agent` VARCHAR(255) NULL DEFAULT NULL,
  `details` VARCHAR(255) NULL DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_login_logs_user_id` (`user_id`),
  KEY `idx_login_logs_action` (`action`),
  KEY `idx_login_logs_created_at` (`created_at`),
  CONSTRAINT `fk_logs_user_id` FOREIGN KEY (`user_id`)
    REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------------------------
-- Table 4: password_resets (Password Recovery)
-- ------------------------------------------------------------------------------
DROP TABLE IF EXISTS `password_resets`;
CREATE TABLE `password_resets` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `email` VARCHAR(100) NOT NULL,
  `token` VARCHAR(100) NOT NULL,
  `expires_at` DATETIME NOT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_password_resets_email_token` (`email`, `token`),
  KEY `idx_password_resets_expires_at` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------------------------
-- Table 5: shopify_products (Product SEO Module - products.php)
-- Stores all synced Shopify products with metadata & editable SEO properties.
-- Supports 20 items per page pagination and bulk sync from Shopify REST API.
-- ------------------------------------------------------------------------------
DROP TABLE IF EXISTS `shopify_products`;
CREATE TABLE `shopify_products` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `store_key` VARCHAR(50) NOT NULL DEFAULT 'business' COMMENT 'Shopify store identifier (retail, business)',
  `shopify_product_id` BIGINT UNSIGNED NOT NULL COMMENT 'Unique Shopify Product ID from REST API',
  
  -- READ-ONLY SHOPIFY ASSETS (Fetched from API, displayed in card info box)
  `product_name` VARCHAR(255) NOT NULL COMMENT 'Original product name from Shopify catalog',
  `image_url` VARCHAR(1000) NULL DEFAULT NULL COMMENT 'Full CDN URL to product featured image',
  `image_name` VARCHAR(255) NULL DEFAULT NULL COMMENT 'Extracted file name (e.g. Ethan.jpg)',
  `product_url` VARCHAR(1000) NULL DEFAULT NULL COMMENT 'Live product URL on Shopify storefront',
  
  -- EDITABLE SEO FIELDS (Only these 3 fields are editable in the portal)
  `title` VARCHAR(255) NOT NULL COMMENT 'Editable SEO Page Title (metafield or title)',
  `meta_description` TEXT NULL COMMENT 'Editable SEO Meta Description',
  `handle` VARCHAR(255) NOT NULL COMMENT 'Editable URL Handle (slug)',
  
  -- STATUS & AUDIT
  `status` ENUM('draft', 'published', 'needs_optimization') NOT NULL DEFAULT 'draft',
  `seo_score` TINYINT UNSIGNED NOT NULL DEFAULT 70 COMMENT 'Computed SEO health score 0-100',
  `category` VARCHAR(100) NULL DEFAULT 'Product',
  `price` VARCHAR(50) NULL DEFAULT NULL,
  `last_synced_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'Timestamp of last Shopify API sync',
  `last_pushed_at` DATETIME NULL DEFAULT NULL COMMENT 'Timestamp when changes were pushed back to Shopify',
  `updated_by` VARCHAR(100) NULL DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_store_product` (`store_key`, `shopify_product_id`),
  KEY `idx_products_store_status` (`store_key`, `status`),
  KEY `idx_products_handle` (`handle`),
  FULLTEXT KEY `ft_products_search` (`title`, `handle`, `meta_description`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Shopify products with editable SEO metadata';

-- -----------------------------------------------------------------------------
-- TABLE 6: `shopify_collections`
-- Stores all synchronized Shopify Collections with editable SEO Page Titles,
-- Meta Descriptions, and URL Handles categorized by store_key (retail, business).
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `shopify_collections` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `store_key` VARCHAR(50) NOT NULL DEFAULT 'business' COMMENT 'Shopify store identifier (retail, business)',
  `shopify_collection_id` BIGINT UNSIGNED NOT NULL COMMENT 'Unique Shopify Collection ID from REST API',
  `collection_title` VARCHAR(255) NOT NULL COMMENT 'Original collection title from Shopify',
  `image_url` VARCHAR(1000) NULL DEFAULT NULL COMMENT 'Hero banner image CDN URL',
  `image_name` VARCHAR(255) NULL DEFAULT NULL COMMENT 'Extracted image filename',
  `collection_url` VARCHAR(1000) NULL DEFAULT NULL COMMENT 'Live collection URL on storefront',
  `title` VARCHAR(255) NOT NULL COMMENT 'Editable SEO Page Title',
  `meta_description` TEXT NULL COMMENT 'Editable SEO Meta Description',
  `handle` VARCHAR(255) NOT NULL COMMENT 'Editable URL Handle (slug)',
  `item_count` INT UNSIGNED DEFAULT 0 COMMENT 'Number of products in collection',
  `status` ENUM('draft', 'published', 'needs_optimization') NOT NULL DEFAULT 'draft',
  `seo_score` TINYINT UNSIGNED NOT NULL DEFAULT 85 COMMENT 'Computed SEO health score 0-100',
  `last_synced_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `last_pushed_at` DATETIME NULL DEFAULT NULL,
  `updated_by` VARCHAR(100) NULL DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_store_collection` (`store_key`, `shopify_collection_id`),
  KEY `idx_collections_store_status` (`store_key`, `status`),
  KEY `idx_collections_handle` (`handle`),
  FULLTEXT KEY `ft_collections_search` (`title`, `handle`, `meta_description`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Shopify collections with editable SEO metadata';

-- -----------------------------------------------------------------------------
-- TABLE 7: `shopify_pages`
-- Stores all synchronized static Shopify Pages with editable SEO Page Titles,
-- Meta Descriptions, and URL Handles categorized by store_key (retail, business).
-- Supports 20 items per page pagination, search, status filters, and live API sync.
-- -----------------------------------------------------------------------------
DROP TABLE IF EXISTS `shopify_pages`;
CREATE TABLE `shopify_pages` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `store_key` VARCHAR(50) NOT NULL DEFAULT 'business' COMMENT 'Shopify store identifier (retail, business)',
  `shopify_page_id` BIGINT UNSIGNED NOT NULL COMMENT 'Unique Shopify Page ID from REST API',
  `page_title` VARCHAR(255) NOT NULL COMMENT 'Original page title from Shopify',
  `page_type` VARCHAR(100) NULL DEFAULT 'General Page' COMMENT 'Classification (e.g. Landing Page, Policy, About, Registration)',
  `page_url` VARCHAR(1000) NULL DEFAULT NULL COMMENT 'Live page URL on storefront',
  
  -- EDITABLE SEO FIELDS (Only these 3 fields are editable in the portal)
  `title` VARCHAR(255) NOT NULL COMMENT 'Editable SEO Page Title',
  `meta_description` TEXT NULL COMMENT 'Editable SEO Meta Description',
  `handle` VARCHAR(255) NOT NULL COMMENT 'Editable URL Handle (slug)',
  
  -- METADATA & STATUS
  `author` VARCHAR(100) NULL DEFAULT 'Uratex Team' COMMENT 'Author or department name',
  `status` ENUM('draft', 'published', 'needs_optimization') NOT NULL DEFAULT 'draft',
  `seo_score` TINYINT UNSIGNED NOT NULL DEFAULT 85 COMMENT 'Computed SEO health score 0-100',
  `last_synced_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'Timestamp of last Shopify API sync',
  `last_pushed_at` DATETIME NULL DEFAULT NULL COMMENT 'Timestamp when pushed to Shopify',
  `updated_by` VARCHAR(100) NULL DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_store_page` (`store_key`, `shopify_page_id`),
  KEY `idx_pages_store_status` (`store_key`, `status`),
  KEY `idx_pages_handle` (`handle`),
  FULLTEXT KEY `ft_pages_search` (`title`, `handle`, `meta_description`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Shopify static pages with editable SEO metadata';

-- -----------------------------------------------------------------------------
-- TABLE 8: `shopify_blogs`
-- Stores all synchronized Shopify Blog Articles with editable SEO Article Titles,
-- Meta Descriptions (Search Excerpts), and URL Handles categorized by store_key.
-- Supports 20 items per page pagination, search, status filters, and live API sync.
-- -----------------------------------------------------------------------------
DROP TABLE IF EXISTS `shopify_blogs`;
CREATE TABLE `shopify_blogs` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `store_key` VARCHAR(50) NOT NULL DEFAULT 'business' COMMENT 'Shopify store identifier (retail, business)',
  `shopify_article_id` BIGINT UNSIGNED NOT NULL COMMENT 'Unique Shopify Article ID from REST API',
  `article_title` VARCHAR(255) NOT NULL COMMENT 'Original article title from Shopify',
  `blog_title` VARCHAR(150) NULL DEFAULT 'News & Sleep Guides' COMMENT 'Parent blog title (e.g. News, Sleep Health, B2B Insights)',
  `image_url` VARCHAR(1000) NULL DEFAULT NULL COMMENT 'Article featured image CDN URL',
  `image_name` VARCHAR(255) NULL DEFAULT NULL COMMENT 'Extracted article image filename',
  `article_url` VARCHAR(1000) NULL DEFAULT NULL COMMENT 'Live article URL on storefront',
  
  -- EDITABLE SEO FIELDS (Only these 3 fields are editable in the portal)
  `title` VARCHAR(255) NOT NULL COMMENT 'Editable SEO Article Title',
  `meta_description` TEXT NULL COMMENT 'Editable SEO Meta Description / Search Excerpt',
  `handle` VARCHAR(255) NOT NULL COMMENT 'Editable URL Handle (slug)',
  
  -- METADATA & STATUS
  `author` VARCHAR(100) NULL DEFAULT 'Uratex Editorial Team' COMMENT 'Article author or specialist byline',
  `category` VARCHAR(100) NULL DEFAULT 'Sleep Science' COMMENT 'Article topic category / tags',
  `read_time` VARCHAR(50) NULL DEFAULT '5 min read' COMMENT 'Estimated reading duration',
  `published_at` DATETIME NULL DEFAULT NULL COMMENT 'Publication timestamp on Shopify',
  `status` ENUM('draft', 'published', 'needs_optimization') NOT NULL DEFAULT 'draft',
  `seo_score` TINYINT UNSIGNED NOT NULL DEFAULT 85 COMMENT 'Computed SEO health score 0-100',
  `last_synced_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'Timestamp of last Shopify API sync',
  `last_pushed_at` DATETIME NULL DEFAULT NULL COMMENT 'Timestamp when pushed to Shopify',
  `updated_by` VARCHAR(100) NULL DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_store_article` (`store_key`, `shopify_article_id`),
  KEY `idx_blogs_store_status` (`store_key`, `status`),
  KEY `idx_blogs_handle` (`handle`),
  KEY `idx_blogs_category` (`category`),
  FULLTEXT KEY `ft_blogs_search` (`title`, `handle`, `meta_description`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Shopify blog articles with editable SEO metadata';

-- -----------------------------------------------------------------------------
-- TABLE 7: `user_logs` (Partner Agent Audit Trail & Activity Logs)
-- Tracks all partner agent actions: logins, logouts, store switching, metadata edits,
-- draft revisions, Shopify syncs, API pushes, and AI optimizations.
-- Configured for 100 rows per page pagination.
-- -----------------------------------------------------------------------------
DROP TABLE IF EXISTS `user_logs`;
CREATE TABLE `user_logs` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` INT UNSIGNED NULL DEFAULT NULL COMMENT 'Foreign key to users.id',
  `user_email` VARCHAR(100) NOT NULL COMMENT 'Partner agent email or username',
  `user_name` VARCHAR(100) NULL DEFAULT NULL COMMENT 'Partner agent display name',
  `store_key` VARCHAR(50) NOT NULL DEFAULT 'business' COMMENT 'Store context (retail, business)',
  `action` VARCHAR(100) NOT NULL COMMENT 'Action: Login, Logout, Draft Saved, Shopify Push, Shopify Sync, AI Optimize, etc.',
  `target_resource` VARCHAR(255) NOT NULL COMMENT 'Target entity (Product name, Collection, Page, Blog, Portal Session)',
  `change_details` TEXT NULL COMMENT 'Descriptive summary of the modification or event',
  `resource_type` ENUM('auth', 'product', 'collection', 'page', 'blog', 'redirect', 'script', 'system') NOT NULL DEFAULT 'system',
  `resource_id` VARCHAR(100) NULL DEFAULT NULL COMMENT 'Resource ID or Shopify item ID',
  `ip_address` VARCHAR(45) NULL DEFAULT NULL,
  `user_agent` VARCHAR(255) NULL DEFAULT NULL,
  `status` ENUM('success', 'failed', 'warning') NOT NULL DEFAULT 'success',
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_user_logs_user` (`user_email`),
  KEY `idx_user_logs_action` (`action`),
  KEY `idx_user_logs_store` (`store_key`),
  KEY `idx_user_logs_created_at` (`created_at`),
  KEY `idx_user_logs_resource` (`resource_type`),
  FULLTEXT KEY `ft_user_logs_search` (`user_email`, `action`, `target_resource`, `change_details`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Partner Agent Audit Trail & Activity Logs';

SET FOREIGN_KEY_CHECKS = 1;

-- ==============================================================================
-- SEED DATA: SAMPLE USERS & PRODUCT SEO SEED DATA (FOR 20 ITEMS/PAGE PAGINATION)
-- ==============================================================================

-- 1. Users
INSERT INTO `users` (
  `id`, `username`, `email`, `password_hash`, `full_name`, `role`, `status`, `store_access`
) VALUES
(1, 'admin', 'jenor.ricafort@uratex.com.ph', '$2y$10$w6M6Z2x9hG8r2uH9jK4l7eS8t9u0v1w2x3y4z5a6b7c8d9e0f1g2h', 'Jenor Ricafort', 'admin', 'active', 'retail,business'),
(2, 'editor', 'maria.santos@uratex.com.ph', '$2y$10$k1N2o3P4q5R6s7T8u9V0w1x2y3z4a5b6c7d8e9f0g1h2i3j4k5l6m', 'Maria Santos', 'editor', 'active', 'business')
ON DUPLICATE KEY UPDATE `full_name` = VALUES(`full_name`);

-- 2. Seed Initial 22 Products for 'business' Store (demonstrates 20 per page pagination)
INSERT INTO `shopify_products` (
  `store_key`, `shopify_product_id`, `product_name`, `image_url`, `image_name`, `product_url`, `title`, `meta_description`, `handle`, `status`, `seo_score`, `category`, `price`
) VALUES
('business', 8486281601, 'Ethan Computer Table with Shelves', 'https://cdn.shopify.com/s/files/1/0569/8486/2816/files/Ethan.jpg?v=1711350767', 'Ethan.jpg', 'https://uratex-business.myshopify.com/products/ethan-computer-table-with-shelves', '[Test 360&5] Ethan Computer Table with Shelves', 'Computer table with shelves that serves as display storage and book case.', 'ethan-computer-table-with-shelves', 'draft', 68, 'Office Furniture', '₱4,850.00'),
('business', 8486281602, 'Manuel Storage Cabinet', 'https://cdn.shopify.com/s/files/1/0569/8486/2816/files/Manuel.jpg?v=1711350771', 'Manuel.jpg', 'https://uratex-business.myshopify.com/products/manuel-storage-cabinet', '[Test 360&5] Manuel Storage Cabinet', 'A complementary bookcase perfect for display shelvings and book storage.', 'manuel-storage-cabinet', 'draft', 72, 'Storage & Shelving', '₱6,200.00'),
('business', 8486281603, 'Uratex Hotel Orthocare Commercial Mattress', 'https://cdn.shopify.com/s/files/1/0569/8486/2816/files/Hotel_Orthocare.jpg', 'Hotel_Orthocare.jpg', 'https://uratex-business.myshopify.com/products/uratex-hotel-orthocare-mattress-bulk', 'Uratex Institutional High-Density Hotel Orthocare Mattress', 'Engineered for commercial hospitality, resorts, and dormitories. High-density sanitized foam with waterproof fire-retardant quilted jacquard fabric.', 'uratex-hotel-orthocare-mattress-bulk', 'published', 96, 'Hospitality Mattresses', '₱12,400.00'),
('business', 8486281604, 'Uratex B2B Ergonomic Mesh Task Chair', 'https://cdn.shopify.com/s/files/1/0569/8486/2816/files/Ergo_Chair_Pro.jpg', 'Ergo_Chair_Pro.jpg', 'https://uratex-business.myshopify.com/products/uratex-ergonomic-mesh-task-chair', 'Uratex B2B Ergonomic Mesh Task Chair with Lumbar Support', 'Heavy-duty breathable office mesh chair with adjustable headrest, 3D armrests, and synchro-tilt mechanism. Wholesale pricing for corporate fit-outs.', 'uratex-ergonomic-mesh-task-chair', 'needs_optimization', 84, 'Corporate Seating', '₱5,450.00'),
('business', 8486281605, 'Medical Grade Hospital Foam Mattress', 'https://cdn.shopify.com/s/files/1/0569/8486/2816/files/MedFoam_Pro.jpg', 'MedFoam_Pro.jpg', 'https://uratex-business.myshopify.com/products/uratex-medical-grade-hospital-foam', 'Uratex Medical Grade Waterproof Healthcare Hospital Foam', 'Antimicrobial and fluid-resistant medical mattress for clinics, hospitals, and assisted living facilities. Meets strict sanitary and DOH standards.', 'uratex-medical-grade-hospital-foam', 'published', 98, 'Healthcare & Medical', '₱8,900.00'),
('business', 8486281606, 'Apollo Heavy Duty Conference Desk', 'https://cdn.shopify.com/s/files/1/0569/8486/2816/files/Apollo_Desk.jpg', 'Apollo_Desk.jpg', 'https://uratex-business.myshopify.com/products/apollo-conference-desk', 'Apollo Executive Heavy Duty Meeting & Conference Table', 'Modular 10-seater boardroom table with wire management channels, scratch-resistant Melamine top, and reinforced powder-coated steel legs.', 'apollo-conference-desk', 'published', 92, 'Office Furniture', '₱18,500.00'),
('business', 8486281607, 'Acoustic Soundproofing Foam Wedge Panels', 'https://cdn.shopify.com/s/files/1/0569/8486/2816/files/Acoustic_Foam.jpg', 'Acoustic_Foam.jpg', 'https://uratex-business.myshopify.com/products/acoustic-soundproofing-foam-panels', 'Uratex Studio Acoustic Sound Dampening Wedge Foam Panels', 'Professional grade acoustic foam tiles designed to absorb reverberation, flutter echoes, and noise in audio studios and BPO call centers.', 'acoustic-soundproofing-foam-panels', 'published', 90, 'Industrial & Acoustic', '₱2,100.00'),
('business', 8486281608, 'Titan Steel Frame Bunk Bed System', 'https://cdn.shopify.com/s/files/1/0569/8486/2816/files/Titan_Bunk.jpg', 'Titan_Bunk.jpg', 'https://uratex-business.myshopify.com/products/titan-steel-frame-bunk-bed', 'Titan Heavy Duty Metal Dormitory Double Bunk Bed System', 'Constructed with heavy-gauge tubular steel and electrostatic powder coating. Built for worker dormitories, military barracks, and hostels.', 'titan-steel-frame-bunk-bed', 'published', 88, 'Dormitory Furniture', '₱9,800.00'),
('business', 8486281609, 'Uratex Monobloc Americana Bistro Chair (Pack of 4)', 'https://cdn.shopify.com/s/files/1/0569/8486/2816/files/Americana_Chair.jpg', 'Americana_Chair.jpg', 'https://uratex-business.myshopify.com/products/uratex-monobloc-americana-chair', 'Uratex Commercial Americana Monobloc Stackable Chair', 'Weatherproof 100% virgin resin heavy-duty plastic chair with UV stabilizers. Ideal for events, dining halls, food courts, and catering rentals.', 'uratex-monobloc-americana-chair', 'published', 94, 'Commercial Seating', '₱2,400.00'),
('business', 8486281610, 'Centurion Fire-Resistant Filing Cabinet 4-Drawer', 'https://cdn.shopify.com/s/files/1/0569/8486/2816/files/Centurion_Cabinet.jpg', 'Centurion_Cabinet.jpg', 'https://uratex-business.myshopify.com/products/centurion-fire-resistant-cabinet', 'Centurion 4-Drawer Heavy Steel Central Lock File Cabinet', 'Secure document storage built with Japanese SPCC cold-rolled steel and anti-tilt locking mechanism. Perfect for corporate archiving.', 'centurion-fire-resistant-cabinet', 'published', 89, 'Storage & Shelving', '₱11,200.00'),
('business', 8486281611, 'Uratex Ortho Pro Commercial Hotel Topper', 'https://cdn.shopify.com/s/files/1/0569/8486/2816/files/Ortho_Topper.jpg', 'Ortho_Topper.jpg', 'https://uratex-business.myshopify.com/products/uratex-hotel-ortho-topper', 'Uratex B2B 2-Inch High Density Bed Mattress Topper', 'Enhances guest sleep comfort without replacing existing bed frames. Wrapped in hypoallergenic breathable fabric with corner elastic bands.', 'uratex-hotel-ortho-topper', 'draft', 78, 'Hospitality Mattresses', '₱3,600.00'),
('business', 8486281612, 'Matrix Modular Workstation Desk 4-Cluster', 'https://cdn.shopify.com/s/files/1/0569/8486/2816/files/Matrix_Workstation.jpg', 'Matrix_Workstation.jpg', 'https://uratex-business.myshopify.com/products/matrix-modular-workstation-4cluster', 'Matrix 4-Person Modular Office Workstation Cubicle Pod', 'Open plan collaborative workstation desk with acoustic fabric privacy dividers, cable management raceways, and lockable pedestal drawers.', 'matrix-modular-workstation-4cluster', 'published', 95, 'Office Furniture', '₱32,000.00'),
('business', 8486281613, 'Uratex High Resiliency Industrial Foam Block', 'https://cdn.shopify.com/s/files/1/0569/8486/2816/files/HR_Foam_Block.jpg', 'HR_Foam_Block.jpg', 'https://uratex-business.myshopify.com/products/uratex-industrial-hr-foam-block', 'Uratex Custom High Resiliency (HR) Bulk Upholstery Foam', 'Raw polyurethane foam bun and cut-to-size sheets for furniture makers, automotive seating, and commercial upholstery applications.', 'uratex-industrial-hr-foam-block', 'published', 91, 'Industrial & Acoustic', '₱14,500.00'),
('business', 8486281614, 'Apex Executive High Back Leather Boss Chair', 'https://cdn.shopify.com/s/files/1/0569/8486/2816/files/Apex_Boss_Chair.jpg', 'Apex_Boss_Chair.jpg', 'https://uratex-business.myshopify.com/products/apex-executive-highback-chair', 'Apex Genuine Top Grain Leather Executive Swivel Chair', 'Ergonomic waterfall cushion seat with padded aluminum armrests and multi-lock recline mechanism. Tailored for corporate C-suite offices.', 'apex-executive-highback-chair', 'published', 93, 'Corporate Seating', '₱15,800.00'),
('business', 8486281615, 'Uratex Anti-Bacterial Nursing Home Waterproof Foam', 'https://cdn.shopify.com/s/files/1/0569/8486/2816/files/Nursing_Foam.jpg', 'Nursing_Foam.jpg', 'https://uratex-business.myshopify.com/products/uratex-nursing-home-waterproof-foam', 'Uratex Sanitized Senior Care Medical Waterproof Mattress', 'Specifically designed for eldercare and specialized rehabilitation clinics with breathable vapor-permeable vinyl cover.', 'uratex-nursing-home-waterproof-foam', 'published', 97, 'Healthcare & Medical', '₱7,800.00'),
('business', 8486281616, 'Vanguard Mobile Multi-Tier Utility Cart', 'https://cdn.shopify.com/s/files/1/0569/8486/2816/files/Vanguard_Cart.jpg', 'Vanguard_Cart.jpg', 'https://uratex-business.myshopify.com/products/vanguard-mobile-utility-cart', 'Vanguard 3-Tier Heavy Duty Commercial Utility Cart', 'Smooth rolling 360-degree castor wheels with locking brakes, non-conductive polypropylene trays for hospitality and cleanroom logistics.', 'vanguard-mobile-utility-cart', 'published', 86, 'Storage & Shelving', '₱3,800.00'),
('business', 8486281617, 'Uratex Fold-A-Bed Institutional Space Saver', 'https://cdn.shopify.com/s/files/1/0569/8486/2816/files/Fold_A_Bed.jpg', 'Fold_A_Bed.jpg', 'https://uratex-business.myshopify.com/products/uratex-fold-a-bed-commercial', 'Uratex Commercial Fold-A-Bed Compact Rollaway Bed', 'Space-saving foldable steel frame with premium high-density foam mattress for disaster relief, quarantine centers, and guest extra beds.', 'uratex-fold-a-bed-commercial', 'draft', 74, 'Dormitory Furniture', '₱4,950.00'),
('business', 8486281618, 'Uratex Heavy Duty Monobloc Olympia Round Banquet Table', 'https://cdn.shopify.com/s/files/1/0569/8486/2816/files/Olympia_Table.jpg', 'Olympia_Table.jpg', 'https://uratex-business.myshopify.com/products/uratex-olympia-round-table', 'Uratex 8-Seater Commercial Resin Round Banquet Table', 'Sturdy 48-inch round molded plastic dining table with detachable reinforced steel legs. Weather-resistant for outdoor caterers and resorts.', 'uratex-olympia-round-table', 'published', 90, 'Commercial Seating', '₱4,200.00'),
('business', 8486281619, 'Uratex Flame-Retardant Bassinet Mattress Foam', 'https://cdn.shopify.com/s/files/1/0569/8486/2816/files/Bassinet_Foam.jpg', 'Bassinet_Foam.jpg', 'https://uratex-business.myshopify.com/products/uratex-maternity-bassinet-foam', 'Uratex Maternity Ward Waterproof Bassinet Crib Mattress', 'Hypoallergenic and phthalate-free pediatric mattress with heat-sealed waterproof seams for hospital nurseries and newborn intensive care.', 'uratex-maternity-bassinet-foam', 'published', 96, 'Healthcare & Medical', '₱2,800.00'),
('business', 8486281620, 'Nexus Height-Adjustable Dual Motor Standing Desk', 'https://cdn.shopify.com/s/files/1/0569/8486/2816/files/Nexus_Stand_Desk.jpg', 'Nexus_Stand_Desk.jpg', 'https://uratex-business.myshopify.com/products/nexus-motorized-standing-desk', 'Nexus Smart Electric Height-Adjustable Sit-to-Stand Desk', 'Whisper-quiet dual motors with 4 memory height presets, anti-collision sensor, and heavy-duty 100kg lift capacity for modern ergonomic offices.', 'nexus-motorized-standing-desk', 'published', 94, 'Office Furniture', '₱16,900.00'),
-- Products on Page 2 (21 & 22)
('business', 8486281621, 'Uratex Commercial Heavy Quilted Pillow Bulk (10-Pack)', 'https://cdn.shopify.com/s/files/1/0569/8486/2816/files/Hotel_Pillow.jpg', 'Hotel_Pillow.jpg', 'https://uratex-business.myshopify.com/products/uratex-hotel-pillow-bulk-pack', 'Uratex Hotel Collection Siliconized Fiberfill Pillows (10-Pack)', 'Plush 100% virgin hollow siliconized fiberfill hotel-grade pillows wrapped in 300-thread count breathable cotton casing. Machine washable.', 'uratex-hotel-pillow-bulk-pack', 'published', 92, 'Hospitality Mattresses', '₱4,500.00'),
('business', 8486281622, 'Sentinel Heavy Duty Industrial Tool Cabinet Box', 'https://cdn.shopify.com/s/files/1/0569/8486/2816/files/Sentinel_Tools.jpg', 'Sentinel_Tools.jpg', 'https://uratex-business.myshopify.com/products/sentinel-industrial-tool-cabinet', 'Sentinel 7-Drawer Heavy Duty Mobile Steel Workshop Tool Chest', 'Reinforced ball-bearing slide drawers with individual safety latches and heavy polyurethane casters for factory and automotive workshops.', 'sentinel-industrial-tool-cabinet', 'draft', 76, 'Storage & Shelving', '₱24,500.00'),

-- 3. Seed Initial 22 Products for 'retail' Store (Uratex Retail Consumer Products)
('retail', 8486282001, 'Uratex Premium Touch Viscoluxe Memory Foam Mattress', 'https://cdn.shopify.com/s/files/1/0569/8486/2816/files/Viscoluxe_Queen.jpg', 'Viscoluxe_Queen.jpg', 'https://uratex.com.ph/products/uratex-premium-touch-viscoluxe-mattress', 'Uratex Premium Touch Viscoluxe Memory Foam Mattress', 'Experience pressure-relieving sleep with Uratex Premium Touch Viscoluxe. Features high-resilient base foam, cooling Tencel fabric, and 15-year warranty.', 'uratex-premium-touch-viscoluxe-mattress', 'published', 98, 'Mattresses', '₱18,500.00'),
('retail', 8486282002, 'Uratex Trifold Sofa Bed Foam Mattress Space Saver', 'https://cdn.shopify.com/s/files/1/0569/8486/2816/files/Trifold_Sofa.jpg', 'Trifold_Sofa.jpg', 'https://uratex.com.ph/products/uratex-trifold-sofa-bed-space-saver', 'Uratex Trifold Sofa Bed Foam Mattress Space Saver', 'Dual-purpose foldable sofa bed foam for condominiums and studio apartments. Compact 3-fold design with removable washable polycotton cover.', 'uratex-trifold-sofa-bed-space-saver', 'published', 95, 'Sofa Beds', '₱5,200.00'),
('retail', 8486282003, 'Uratex Classic Blue Mattress - Sanitized Durable Foam', 'https://cdn.shopify.com/s/files/1/0569/8486/2816/files/Classic_Blue.jpg', 'Classic_Blue.jpg', 'https://uratex.com.ph/products/uratex-classic-blue-foam-mattress', 'Uratex Classic Blue Mattress - Sanitized Durable Foam', 'The trusted standard in Filipino homes for over 50 years. Medium firm support with Sanitized® antimicrobial protection to prevent dust mites and mold.', 'uratex-classic-blue-foam-mattress', 'draft', 92, 'Foam Mattresses', '₱3,100.00'),
('retail', 8486282004, 'Uratex Cool Quilt Pillow with Hydro-Gel Cooling Pad', 'https://cdn.shopify.com/s/files/1/0569/8486/2816/files/Cool_Pillow.jpg', 'Cool_Pillow.jpg', 'https://uratex.com.ph/products/uratex-cool-quilt-pillow-hydrogel', 'Uratex Cool Quilt Pillow with Hydro-Gel Cooling Pad', 'Sleep cool through warm Philippine nights. Ergonomic hydro-gel cooling layer absorbs heat while high-density micro-fiber delivers plush neck support.', 'uratex-cool-quilt-pillow-hydrogel', 'needs_optimization', 85, 'Pillows & Accessories', '₱1,450.00'),
('retail', 8486282005, 'Uratex Trill Hybrid Pocket Spring & Memory Mattress', 'https://cdn.shopify.com/s/files/1/0569/8486/2816/files/Trill_Hybrid.jpg', 'Trill_Hybrid.jpg', 'https://uratex.com.ph/products/uratex-trill-hybrid-pocket-spring-mattress', 'Uratex Trill Hybrid Pocket Spring & Memory Mattress', 'The ultimate box mattress featuring independent pocket springs, plush memory foam topper, and breathable anti-sag perimeter encasement.', 'uratex-trill-hybrid-pocket-spring-mattress', 'published', 96, 'Mattresses', '₱15,900.00'),
('retail', 8486282006, 'Uratex Senso Memory Frost Cooling Bed Pillow', 'https://cdn.shopify.com/s/files/1/0569/8486/2816/files/Frost_Pillow.jpg', 'Frost_Pillow.jpg', 'https://uratex.com.ph/products/uratex-senso-memory-frost-cooling-pillow', 'Uratex Senso Memory Frost Cooling Bed Pillow', 'Memory foam pillow infused with SensoFrost cooling technology for instant temperature regulation and cervical spine contouring.', 'uratex-senso-memory-frost-cooling-pillow', 'published', 94, 'Pillows & Accessories', '₱2,200.00'),
('retail', 8486282007, 'Uratex Bio Aire Egg Crate Breathable Foam Mattress', 'https://cdn.shopify.com/s/files/1/0569/8486/2816/files/Bio_Aire.jpg', 'Bio_Aire.jpg', 'https://uratex.com.ph/products/uratex-bio-aire-egg-crate-foam-mattress', 'Uratex Bio Aire Egg Crate Breathable Foam Mattress', 'Distinctive convoluted egg-crate contours evenly distribute body pressure, boost airflow, and help prevent bedsores for therapeutic rest.', 'uratex-bio-aire-egg-crate-foam-mattress', 'published', 93, 'Orthopedic Beds', '₱4,650.00'),
('retail', 8486282008, 'Uratex Fold-A-Mattress Portable Travel Sleeper', 'https://cdn.shopify.com/s/files/1/0569/8486/2816/files/Fold_A_Mattress.jpg', 'Fold_A_Mattress.jpg', 'https://uratex.com.ph/products/uratex-fold-a-mattress-portable-sleeper', 'Uratex Fold-A-Mattress Portable Travel Sleeper', 'Easy-to-carry 3-fold travel mattress with water-resistant backing and strap handles. Perfect for camping, sleepovers, and quick guest bedding.', 'uratex-fold-a-mattress-portable-sleeper', 'published', 91, 'Space Savers', '₱2,400.00'),
('retail', 8486282009, 'Uratex Permahard Extra Firm Orthopedic Mattress', 'https://cdn.shopify.com/s/files/1/0569/8486/2816/files/Permahard.jpg', 'Permahard.jpg', 'https://uratex.com.ph/products/uratex-permahard-extra-firm-orthopedic-mattress', 'Uratex Permahard Extra Firm Orthopedic Mattress', 'Orthopedic doctor recommended extra firm mattress for chronic lower back and lumbar support, wrapped in durable woven jacquard fabric.', 'uratex-permahard-extra-firm-orthopedic-mattress', 'published', 95, 'Orthopedic Beds', '₱7,800.00'),
('retail', 8486282010, 'Uratex Soft Deluxe 100% Virgin Fiberfill Pillow', 'https://cdn.shopify.com/s/files/1/0569/8486/2816/files/Soft_Deluxe.jpg', 'Soft_Deluxe.jpg', 'https://uratex.com.ph/products/uratex-soft-deluxe-fiberfill-pillow', 'Uratex Soft Deluxe 100% Virgin Fiberfill Pillow', 'Fluffy hypoallergenic virgin hollow fiberfill bed pillow designed to maintain loft and bounce night after night. Machine washable.', 'uratex-soft-deluxe-fiberfill-pillow', 'draft', 88, 'Pillows & Accessories', '₱650.00'),
('retail', 8486282011, 'Uratex Airlite Cool Breathable Air-Mesh Mattress', 'https://cdn.shopify.com/s/files/1/0569/8486/2816/files/Airlite_Cool.jpg', 'Airlite_Cool.jpg', 'https://uratex.com.ph/products/uratex-airlite-cool-breathable-mattress', 'Uratex Airlite Cool Breathable Air-Mesh Mattress', 'Engineered with 3D Spacer fabric side mesh panels that expel hot humid air, maintaining a fresh and cool sleeping environment all year round.', 'uratex-airlite-cool-breathable-mattress', 'published', 92, 'Foam Mattresses', '₱6,400.00'),
('retail', 8486282012, 'Uratex Back Relief Lumbar Ergonomic Pillow', 'https://cdn.shopify.com/s/files/1/0569/8486/2816/files/Back_Relief.jpg', 'Back_Relief.jpg', 'https://uratex.com.ph/products/uratex-back-relief-lumbar-pillow', 'Uratex Back Relief Lumbar Ergonomic Pillow', 'Ergonomic lumbar support pillow tailored for work-from-home office chairs and car seats. Relieves lower spine pressure and improves posture.', 'uratex-back-relief-lumbar-pillow', 'published', 90, 'Accessories', '₱1,250.00'),
('retail', 8486282013, 'Uratex Happy Dreams Nursery Crib Foam Mattress', 'https://cdn.shopify.com/s/files/1/0569/8486/2816/files/Happy_Dreams.jpg', 'Happy_Dreams.jpg', 'https://uratex.com.ph/products/uratex-happy-dreams-nursery-crib-mattress', 'Uratex Happy Dreams Nursery Crib Foam Mattress', 'Pediatrician-approved firm baby crib mattress with waterproof sanitized cover to safeguard infants and toddlers from allergens and moisture.', 'uratex-happy-dreams-nursery-crib-mattress', 'published', 94, 'Baby & Kids', '₱1,850.00'),
('retail', 8486282014, 'Uratex Siesta Mattress with Built-In Pillow Headrest', 'https://cdn.shopify.com/s/files/1/0569/8486/2816/files/Siesta_Foam.jpg', 'Siesta_Foam.jpg', 'https://uratex.com.ph/products/uratex-siesta-mattress-integrated-pillow', 'Uratex Siesta Mattress with Built-In Pillow Headrest', 'All-in-one rollup sleeper foam with an integrated raised pillow headrest and breathable fabric. Ideal for afternoon naps and studio spaces.', 'uratex-siesta-mattress-integrated-pillow', 'draft', 89, 'Space Savers', '₱2,950.00'),
('retail', 8486282015, 'Uratex Nuve High Density Memory Foam Mattress Topper', 'https://cdn.shopify.com/s/files/1/0569/8486/2816/files/Nuve_Topper.jpg', 'Nuve_Topper.jpg', 'https://uratex.com.ph/products/uratex-nuve-memory-foam-mattress-topper', 'Uratex Nuve High Density Memory Foam Mattress Topper', 'Upgrade your existing firm bed instantly. High density 2-inch Visco memory foam layer conforms to your body curve for cloud-like comfort.', 'uratex-nuve-memory-foam-mattress-topper', 'published', 96, 'Mattress Toppers', '₱6,800.00'),
('retail', 8486282016, 'Uratex Dual Comfort Reversible Firm & Soft Mattress', 'https://cdn.shopify.com/s/files/1/0569/8486/2816/files/Dual_Comfort.jpg', 'Dual_Comfort.jpg', 'https://uratex.com.ph/products/uratex-dual-comfort-reversible-mattress', 'Uratex Dual Comfort Reversible Firm & Soft Mattress', 'Two distinct firmness levels in one mattress: firm support on one side, plush comfort on the other. Simply flip to match your sleeping preference.', 'uratex-dual-comfort-reversible-mattress', 'published', 93, 'Mattresses', '₱8,200.00'),
('retail', 8486282017, 'Uratex Cozy Rest Contoured Neck Support Pillow', 'https://cdn.shopify.com/s/files/1/0569/8486/2816/files/Cozy_Rest.jpg', 'Cozy_Rest.jpg', 'https://uratex.com.ph/products/uratex-cozy-rest-contoured-neck-pillow', 'Uratex Cozy Rest Contoured Neck Support Pillow', 'Cervical contour pillow with dual-wave neck arches designed to maintain natural neck curvature and minimize morning muscle soreness.', 'uratex-cozy-rest-contoured-neck-pillow', 'published', 91, 'Pillows & Accessories', '₱1,100.00'),
('retail', 8486282018, 'Uratex Monobloc 101 Casual Resin Dining Chair', 'https://cdn.shopify.com/s/files/1/0569/8486/2816/files/Monobloc_101.jpg', 'Monobloc_101.jpg', 'https://uratex.com.ph/products/uratex-monobloc-101-casual-chair', 'Uratex Monobloc 101 Casual Resin Dining Chair', 'The iconic Philippine standard plastic chair. Molded from 100% virgin resin, lightweight, stackable, and certified for 150kg weight load.', 'uratex-monobloc-101-casual-chair', 'published', 95, 'Monobloc Seating', '₱480.00'),
('retail', 8486282019, 'Uratex Quilted Mattress Protector Waterproof Shield', 'https://cdn.shopify.com/s/files/1/0569/8486/2816/files/Bed_Protector.jpg', 'Bed_Protector.jpg', 'https://uratex.com.ph/products/uratex-quilted-waterproof-mattress-protector', 'Uratex Quilted Mattress Protector Waterproof Shield', 'Fitted elastic skirt mattress protector with breathable TPU waterproof membrane to guard against liquid spills, allergens, and perspiration.', 'uratex-quilted-waterproof-mattress-protector', 'published', 92, 'Bedding Accessories', '₱1,350.00'),
('retail', 8486282020, 'Uratex Edge Quilted Plain Firm Foam Mattress', 'https://cdn.shopify.com/s/files/1/0569/8486/2816/files/Edge_Quilted.jpg', 'Edge_Quilted.jpg', 'https://uratex.com.ph/products/uratex-edge-quilted-plain-firm-mattress', 'Uratex Edge Quilted Plain Firm Foam Mattress', 'Entry-level quilted firm foam mattress with Sanitized treatment, delivering comfortable orthocare sleep support at an economical value.', 'uratex-edge-quilted-plain-firm-mattress', 'published', 90, 'Foam Mattresses', '₱3,950.00'),
('retail', 8486282021, 'Uratex Sleep Haven Pocket Spring Luxury Mattress', 'https://cdn.shopify.com/s/files/1/0569/8486/2816/files/Sleep_Haven.jpg', 'Sleep_Haven.jpg', 'https://uratex.com.ph/products/uratex-sleep-haven-pocket-spring-luxury-mattress', 'Uratex Sleep Haven Pocket Spring Luxury Mattress', '5-Star luxury sleep experience crafted with individual pocket coils, organic Belgian damask fabric, and high-density sanitized foam encasement.', 'uratex-sleep-haven-pocket-spring-luxury-mattress', 'published', 97, 'Luxury Beds', '₱22,500.00'),
('retail', 8486282022, 'Uratex Snooze Cloud Microfiber Body Bolster', 'https://cdn.shopify.com/s/files/1/0569/8486/2816/files/Snooze_Bolster.jpg', 'Snooze_Bolster.jpg', 'https://uratex.com.ph/products/uratex-snooze-cloud-microfiber-body-bolster', 'Uratex Snooze Cloud Microfiber Body Bolster', 'Plush cylinder body hugger pillow stuffed with hypoallergenic down-alternative microfiber. Provides superior side-sleeper body and hip support.', 'uratex-snooze-cloud-microfiber-body-bolster', 'draft', 87, 'Pillows & Accessories', '₱890.00')
ON DUPLICATE KEY UPDATE
  `title` = VALUES(`title`),
  `meta_description` = VALUES(`meta_description`),
  `handle` = VALUES(`handle`),
  `status` = VALUES(`status`),
  `seo_score` = VALUES(`seo_score`);

-- 4. Seed Initial Pages for 'business' and 'retail' Stores (shopify_pages)
INSERT INTO `shopify_pages` (
  `store_key`, `shopify_page_id`, `page_title`, `page_type`, `page_url`, `title`, `meta_description`, `handle`, `author`, `status`, `seo_score`
) VALUES
-- Business Store Pages
('business', 8487001, 'Uratex B2B Wholesale & Institutional Supply Solutions', 'Landing Page', 'https://uratex-business.myshopify.com/pages/b2b-wholesale-solutions', 'Uratex B2B Wholesale & Institutional Supply Solutions', 'Partner with the Philippine leader in foam and bedding solutions. Bulk procurement, corporate tier discounts, and customized manufacturing.', 'b2b-wholesale-solutions', 'B2B Commercial Division', 'published', 96),
('business', 8487002, 'Corporate Account Registration & Credit Application Portal', 'Registration / Form', 'https://uratex-business.myshopify.com/pages/corporate-account-registration', 'Corporate Account Registration & Credit Application', 'Apply for official Uratex corporate partner perks, 30-day payment terms, dedicated account managers, and nationwide institutional delivery.', 'corporate-account-registration', 'Commercial Credit Desk', 'published', 94),
('business', 8487003, 'Warranty & Quality Assurance for Commercial Clients', 'Policy & Standards', 'https://uratex-business.myshopify.com/pages/commercial-warranty-policy', 'Commercial Warranty Policy & Quality Assurance | B2B', 'Comprehensive commercial warranty details, durability testing standards, and Sanitized® hygiene certifications for institutional clients.', 'commercial-warranty-policy', 'Quality Assurance', 'draft', 90),
('business', 8487004, 'Hotel & Resort Hospitality Mattress Bulk Procurement', 'Industry Portal', 'https://uratex-business.myshopify.com/pages/hospitality-hotel-mattress-procurement', 'Hotel Mattress Bulk Procurement & Hospitality Supply PH', 'Equip your hotel, resort, or boutique inn with 5-star pocket spring mattresses, fire-retardant Belgian damask casing, and wholesale packages.', 'hospitality-hotel-mattress-procurement', 'Hospitality Desk', 'published', 98),
('business', 8487005, 'Healthcare, Clinic & Hospital Bed Foam Supply Solutions', 'Medical Solutions', 'https://uratex-business.myshopify.com/pages/healthcare-medical-grade-foam-supply', 'Medical Grade Hospital Foam & Clinic Bed Mattresses', 'Antimicrobial, fluid-resistant, and anti-decubitus medical mattresses for hospital wards, ICU units, and clinical care facilities nationwide.', 'healthcare-medical-grade-foam-supply', 'Healthcare Division', 'published', 95),
('business', 8487006, 'Custom Acoustic Foam & Industrial Soundproofing Fabrication', 'Technical Fabrication', 'https://uratex-business.myshopify.com/pages/custom-acoustic-soundproofing-fabrication', 'Custom Acoustic Foam & Soundproofing Panels | B2B PH', 'Professional polyurethane acoustic wedge panels, sound barriers, and vibration dampening foam for recording studios, BPOs, and plant floors.', 'custom-acoustic-soundproofing-fabrication', 'Acoustic Engineering', 'needs_optimization', 84),
('business', 8487007, 'Dormitory, Hostels & Worker Housing Bunk Bed Solutions', 'Institutional Housing', 'https://uratex-business.myshopify.com/pages/dormitory-worker-housing-bunk-solutions', 'Dormitory Bunk Beds & Flame-Retardant Mattresses Bulk', 'Heavy-gauge steel bunk beds and institutional water-resistant vinyl mattresses built for worker dorms, universities, and barracks.', 'dormitory-worker-housing-bunk-solutions', 'Institutional Sales', 'published', 95),
('business', 8487008, 'Corporate Office Fit-Out & Ergonomic Seating Catalog', 'Office Solutions', 'https://uratex-business.myshopify.com/pages/office-fitout-ergonomic-seating-catalog', 'Corporate Office Furniture Fit-Out & Ergonomic Seating', 'Furnish your corporate headquarters with commercial workstations, mesh executive chairs, modular cubicles, and boardroom tables.', 'office-fitout-ergonomic-seating-catalog', 'Commercial Interiors', 'published', 93),
('business', 8487009, 'Global Export & International Foam Logistics Services', 'Global Export', 'https://uratex-business.myshopify.com/pages/export-international-foam-logistics', 'International Foam Export & Global Container Logistics', 'Uratex RGC international export division providing high-resiliency polyurethane foam blocks, molded seating, and container freight worldwide.', 'export-international-foam-logistics', 'Export Logistics Team', 'published', 94),
('business', 8487010, 'Uratex ESG Sustainability & Green Manufacturing Report', 'ESG & Compliance', 'https://uratex-business.myshopify.com/pages/sustainability-environmental-esg-report', 'ESG Sustainability & Green Polyurethane Manufacturing', 'Explore our corporate ESG commitments: bio-based polyol development, zero-landfill foam recycling programs, and solar-powered plant facilities.', 'sustainability-environmental-esg-report', 'Sustainability Office', 'published', 96),
-- Retail Store Pages
('retail', 9582001, 'About Uratex Philippines - 55+ Years of Sleep Heritage', 'Brand Story', 'https://uratex.com.ph/pages/about-uratex-philippines', 'About Uratex Philippines | 55+ Years of Sleep Innovation', 'Learn about the legacy of Uratex Philippines (RGC Group of Companies), pioneering sleep innovation, world-class foam manufacturing, and quality mattresses.', 'about-uratex-philippines', 'Uratex Brand Heritage Desk', 'published', 97),
('retail', 9582002, 'Store Locator - Find Uratex Sleep Showrooms Near You', 'Directory / Locator', 'https://uratex.com.ph/pages/store-locator-dealers', 'Uratex Showroom & Sleep Store Locator Philippines', 'Locate official Uratex sleep showrooms, factory outlets, and authorized retail distributors across Metro Manila, Luzon, Visayas, and Mindanao.', 'store-locator-dealers', 'Retail Operations', 'published', 94),
('retail', 9582003, 'Uratex Sleep Lab & Advanced Orthopedic Foam Science', 'Technology / Innovation', 'https://uratex.com.ph/pages/sleep-lab-foam-science', 'Uratex Sleep Science Lab | Orthopedic Foam Research', 'Discover clinical pressure-mapping research, ergonomics, and cooling airflow formulations powering Uratex mattresses.', 'sleep-lab-foam-science', 'Sleep Science Center', 'published', 96),
('retail', 9582004, 'Ultimate Philippine Mattress Buying & Firmness Guide', 'Educational Guide', 'https://uratex.com.ph/pages/mattress-buying-guide', 'Philippine Mattress Buying Guide 2026: Foam vs Spring', 'Discover the best mattress for your sleeping style. Compare firmness scales, orthopedic spinal alignment, cooling open-cell foam, and budget options.', 'mattress-buying-guide', 'Sleep Specialist Team', 'published', 98),
('retail', 9582005, 'Official 10-Year Mattress Warranty Online Registration', 'Registration / Form', 'https://uratex.com.ph/pages/10-year-warranty-registration', 'Register Your Uratex Mattress 10-Year Warranty Online', 'Activate your official Uratex product warranty online. Quick serial number verification, replacement coverage details, and customer service support.', '10-year-warranty-registration', 'Customer Care Desk', 'published', 95),
('retail', 9582006, 'RGC Group Sustainability & Eco-Friendly Sleep Initiatives', 'Sustainability / ESG', 'https://uratex.com.ph/pages/eco-friendly-rgc-care', 'Sustainability & Green Manufacturing | Uratex Philippines', 'Discover Uratex RGC environmental stewardship: zero-waste recycling, low VOC emissions, eco-friendly foam formulations, and renewable energy adoption.', 'eco-friendly-rgc-care', 'RGC Sustainability Desk', 'published', 93),
('retail', 9582007, 'Uratex CSR - Community Building & Sleep for Every Juan', 'CSR / Community', 'https://uratex.com.ph/pages/corporate-social-responsibility', 'Corporate Social Responsibility | Uratex Community Care', 'Empowering Filipino communities through disaster relief bedding donations, education sponsorships, and housing development partnerships.', 'corporate-social-responsibility', 'Public Relations', 'published', 91),
('retail', 9582008, 'How to Clean and Care for Your Uratex Foam Mattress', 'Maintenance Guide', 'https://uratex.com.ph/pages/mattress-care-cleaning-instructions', 'Mattress Care & Cleaning Guide: Keep Beds Fresh & Clean', 'Expert tips on maintaining foam elasticity, spot-cleaning stains, rotating mattresses, and utilizing waterproof protectors to extend mattress lifespan.', 'mattress-care-cleaning-instructions', 'Customer Care Desk', 'draft', 88),
('retail', 9582009, 'Custom Sized Mattress & Tailored Foam Cutting Service', 'Custom Services', 'https://uratex.com.ph/pages/custom-mattress-cut-to-size', 'Custom Cut-to-Size Mattress & Foam Cushion Service PH', 'Need custom dimensions for sofa beds, daybeds, RV campers, or yachts? Order tailored high-density foam cutting with choice of fabric covers.', 'custom-mattress-cut-to-size', 'Custom Fabrication Lab', 'published', 95),
('retail', 9582010, 'Careers at Uratex - Join the Leading Foam Innovator', 'Careers / HR', 'https://uratex.com.ph/pages/careers-life-at-uratex', 'Careers at Uratex Philippines | Job Openings & Culture', 'Explore exciting career opportunities in engineering, marketing, supply chain, and retail sales with the Philippines leading sleep and foam company.', 'careers-life-at-uratex', 'Human Resources', 'published', 92)
ON DUPLICATE KEY UPDATE
  `title` = VALUES(`title`),
  `meta_description` = VALUES(`meta_description`),
  `handle` = VALUES(`handle`),
  `status` = VALUES(`status`),
  `seo_score` = VALUES(`seo_score`);

-- 5. Seed Initial Blog Articles for 'business' and 'retail' Stores (shopify_blogs)
INSERT INTO `shopify_blogs` (
  `store_key`, `shopify_article_id`, `article_title`, `blog_title`, `image_url`, `image_name`, `article_url`, `title`, `meta_description`, `handle`, `author`, `category`, `read_time`, `status`, `seo_score`
) VALUES
-- Business Store Articles
('business', 6782001, 'Hotel Mattress Buying Guide 2026: Balancing Guest Comfort and Longevity', 'Hospitality B2B Insights', 'https://images.unsplash.com/photo-1582582621959-48d27397dc69?w=800&auto=format&fit=crop&q=80', 'hotel-mattress-guide.jpg', 'https://uratex-business.myshopify.com/blogs/news/hotel-mattress-buying-guide-2026', 'Hotel Mattress Buying Guide 2026: Balancing Guest Comfort & Longevity', 'Essential guide for hotel general managers and procurement directors in the Philippines on selecting commercial mattresses that maximize ROI.', 'hotel-mattress-buying-guide-2026', 'Commercial Hospitality Advisory', 'Hospitality Procurement', '8 min read', 'published', 98),
('business', 6782002, 'Clinical Foam Solutions: Medical-Grade Antimicrobial Mattresses for Hospitals', 'Healthcare Technical Bulletin', 'https://images.unsplash.com/photo-1505693416388-ac5ce068fe85?w=800&auto=format&fit=crop&q=80', 'hospital-bed-foam.jpg', 'https://uratex-business.myshopify.com/blogs/news/hospital-medical-grade-foam-infection-control-ph', 'Healthcare Bedding Standards: Infection Control & Decubitus Prevention', 'Technical compliance specs for hospital bed mattresses: fluid-impermeable vinyl covers, anti-decubitus pressure redistribution, and DOH protocols.', 'hospital-medical-grade-foam-infection-control-ph', 'Healthcare Solutions Division', 'Healthcare & Medical', '7 min read', 'published', 97),
('business', 6782003, 'Commercial Fire Safety: Flame-Retardant Polyurethane Foam Compliance', 'Standards & Certifications', 'https://images.unsplash.com/photo-1540518614846-7ede433c4550?w=800&auto=format&fit=crop&q=80', 'fire-safety-foam.jpg', 'https://uratex-business.myshopify.com/blogs/news/fire-retardant-polyurethane-standards-commercial-venues', 'Fire-Retardant Standards (CAL 117 & BS 5852) for Commercial Venues', 'Understand mandatory fire safety ratings for hotels, auditoriums, and transport seating. How Uratex certifies commercial foam against rapid ignition.', 'fire-retardant-polyurethane-standards-commercial-venues', 'Testing & Certification Lab', 'Safety & Compliance', '6 min read', 'published', 95),
('business', 6782004, 'Ergonomic Fit-Outs: How Lumbar Support Reduces BPO Employee Absenteeism', 'Corporate Interiors', 'https://images.unsplash.com/photo-1595428774223-ef52624120d2?w=800&auto=format&fit=crop&q=80', 'bpo-office-ergonomics.jpg', 'https://uratex-business.myshopify.com/blogs/news/bpo-office-ergonomic-mesh-chairs-productivity', 'BPO Office Ergonomics: Boosting Agent Productivity with Task Chairs', 'Optimize corporate call center productivity with heavy-duty breathable mesh chairs, synchronous tilting, and adjustable armrests designed for 24/7 shifts.', 'bpo-office-ergonomic-mesh-chairs-productivity', 'Corporate Interiors Team', 'Office Ergonomics', '5 min read', 'published', 96),
('business', 6782005, 'Industrial Acoustic Foam: Noise Absorption Coefficients for Open-Plan Workplaces', 'Acoustic Engineering', 'https://images.unsplash.com/photo-1631049307264-da0ec9d70304?w=800&auto=format&fit=crop&q=80', 'acoustic-soundproofing.jpg', 'https://uratex-business.myshopify.com/blogs/news/industrial-acoustic-foam-panels-noise-reduction-offices', 'Acoustic Soundproofing Engineering: Controlling Noise in Open Offices', 'Mitigate echo and reverberation in open-plan offices, recording studios, and plant control rooms using calibrated polyurethane acoustic wedge panels.', 'industrial-acoustic-foam-panels-noise-reduction-offices', 'Acoustic Engineering Division', 'Acoustic Engineering', '7 min read', 'needs_optimization', 85),
-- Retail Store Articles
('retail', 7891001, 'How to Choose the Best Mattress for Back Pain Relief in 2026', 'Sleep Health & Wellness', 'https://images.unsplash.com/photo-1540518614846-7ede433c4550?w=800&auto=format&fit=crop&q=80', 'back-pain-mattress-guide.jpg', 'https://uratex.com.ph/blogs/news/how-to-choose-mattress-back-pain-philippines', 'How to Choose the Best Mattress for Back Pain Relief in 2026', 'Struggling with chronic morning lumbar pain? Learn how orthopedic memory foam and pocket spring mattresses align the spine and alleviate pressure points.', 'how-to-choose-mattress-back-pain-philippines', 'Dr. Martin Ramos, Orthopedic Sleep Specialist', 'Sleep Health & Orthopedics', '6 min read', 'published', 98),
('retail', 7891002, 'Beat the Tropical Heat: Best Cooling Mattresses & Open-Cell Foam Hacks', 'Sleep Science & Tech', 'https://images.unsplash.com/photo-1631049307264-da0ec9d70304?w=800&auto=format&fit=crop&q=80', 'cooling-mattress-tech.jpg', 'https://uratex.com.ph/blogs/news/cooling-mattress-hacks-tropical-summer-philippines', 'Cooling Mattress Hacks for Humid Philippine Summer Nights', 'Stay sweat-free and sleep deeply during hot Philippine summers. Discover open-cell foam, 3D spacer mesh fabrics, and hydrogel cooling pad technologies.', 'cooling-mattress-hacks-tropical-summer-philippines', 'Uratex Sleep Science Desk', 'Sleep Technology', '5 min read', 'published', 95),
('retail', 7891003, 'Memory Foam vs Pocket Spring Mattresses: Complete Comparison Guide', 'Buying Guides', 'https://images.unsplash.com/photo-1505693416388-ac5ce068fe85?w=800&auto=format&fit=crop&q=80', 'memory-foam-vs-spring.jpg', 'https://uratex.com.ph/blogs/news/memory-foam-vs-pocket-spring-mattress-comparison', 'Memory Foam vs Pocket Spring: Which Mattress Type is Right for You?', 'Comparing visco-elastic memory foam contouring with independent pocket coil bounce and motion isolation to find your dream bed.', 'memory-foam-vs-pocket-spring-mattress-comparison', 'Uratex Sleep Lab', 'Buying Guides', '7 min read', 'published', 97),
('retail', 7891004, 'Condo Living: 5 Smart Space-Saving Sofa Bed Hacks for Small Units', 'Home Design & Living', 'https://images.unsplash.com/photo-1555041469-a586c61ea9bc?w=800&auto=format&fit=crop&q=80', 'condo-sofa-bed-hacks.jpg', 'https://uratex.com.ph/blogs/news/condo-living-space-saving-sofa-bed-hacks', '5 Ways to Maximize Small Studio Condo Space with Sofa Beds', 'Transform tight condominium footprints into chic daytime living rooms and cozy nocturnal master bedrooms with high-density foldable sofa beds.', 'condo-living-space-saving-sofa-bed-hacks', 'Interior Design Team', 'Condo Living & Furniture', '4 min read', 'published', 94),
('retail', 7891005, 'How Built-In Sanitized Protection Prevents Allergies and Bed Bugs', 'Home Hygiene & Health', 'https://images.unsplash.com/photo-1582582621959-48d27397dc69?w=800&auto=format&fit=crop&q=80', 'sanitized-mattress-protection.jpg', 'https://uratex.com.ph/blogs/news/mattress-sanitization-dust-mite-prevention-ph', 'Mattress Sanitization & Dust Mite Prevention in the Philippines', 'Safeguard your family from asthma triggers and microbial growth. Learn how Sanitized antimicrobial silver treatment keeps foam mattresses sterile.', 'mattress-sanitization-dust-mite-prevention-ph', 'Hygiene & Quality Assurance', 'Home Hygiene', '5 min read', 'published', 96)
ON DUPLICATE KEY UPDATE
  `title` = VALUES(`title`),
  `meta_description` = VALUES(`meta_description`),
  `handle` = VALUES(`handle`),
  `status` = VALUES(`status`),
  `seo_score` = VALUES(`seo_score`);

-- Initial Audit Log Records
INSERT INTO `login_logs` (`user_id`, `username_attempted`, `action`, `ip_address`, `user_agent`, `details`)
VALUES
(1, 'admin', 'login_success', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0)', 'Admin console product catalog initialization'),
(2, 'editor', 'login_success', '127.0.0.1', 'Mozilla/5.0 (Macintosh; Intel Mac OS X)', 'Editor product metadata drafting session');

-- 6. Seed Partner Agent Audit Trail & Activity Logs (user_logs)
INSERT INTO `user_logs` (
  `user_id`, `user_email`, `user_name`, `store_key`, `action`, `target_resource`, `change_details`, `resource_type`, `resource_id`, `ip_address`, `created_at`
) VALUES
(1, 'partner.agent@uratex.com.ph', 'Partner Agent', 'business', 'Draft Saved', '[Test 360&5] Manuel Storage Cabinet', 'Updated meta description and URL handle.', 'product', '8486281602', '127.0.0.1', '2026-08-25 14:22:04'),
(1, 'jenor.ricafort@uratex.com.ph', 'Jenor Ricafort', 'business', 'Draft Saved', '[Test 360&5] Ethan Computer Table with Shelves', 'Edited page title & meta tags.', 'product', '8486281601', '127.0.0.1', '2026-08-25 14:20:12'),
(1, 'admin', 'Jenor Ricafort', 'business', 'Shopify Sync', 'All Products (5 items)', 'Synchronized live metadata from uratex-business.myshopify.com', 'product', NULL, '127.0.0.1', '2026-08-25 11:00:55'),
(1, 'jenor.ricafort@uratex.com.ph', 'Jenor Ricafort', 'business', 'Login', 'Partner Portal Session', 'Partner agent signed in successfully via Google reCAPTCHA v2 (Store: BUSINESS, Role: Admin)', 'auth', '1', '127.0.0.1', '2026-08-25 10:45:10'),
(2, 'maria.santos@uratex.com.ph', 'Maria Santos', 'retail', 'Draft Saved', 'Uratex Premium Touch Viscoluxe Memory Foam Mattress', 'Optimized SEO title length (58 chars) and added target focus keyword.', 'product', '9245181001', '127.0.0.1', '2026-08-24 16:30:15'),
(2, 'maria.santos@uratex.com.ph', 'Maria Santos', 'retail', 'Shopify Push', 'Uratex Premium Touch Viscoluxe Memory Foam Mattress', 'Pushed live SEO tags to Shopify REST API v2025-10.', 'product', '9245181001', '127.0.0.1', '2026-08-24 16:35:40'),
(1, 'jenor.ricafort@uratex.com.ph', 'Jenor Ricafort', 'business', 'AI Optimize', 'Uratex Hotel Orthocare Commercial Mattress', 'Generated Gemini 3.7 Flash high CTR meta package.', 'product', '8486281603', '127.0.0.1', '2026-08-24 15:10:20'),
(1, 'jenor.ricafort@uratex.com.ph', 'Jenor Ricafort', 'business', 'Draft Saved', 'Hotel & Commercial Collections', 'Refined meta description to match B2B institutional search intent.', 'collection', 'col-101', '127.0.0.1', '2026-08-24 14:05:12'),
(2, 'maria.santos@uratex.com.ph', 'Maria Santos', 'retail', 'Login', 'Partner Portal Session', 'Partner agent signed in successfully via Google reCAPTCHA v2 (Store: RETAIL, Role: Editor)', 'auth', '2', '127.0.0.1', '2026-08-24 09:12:44'),
(2, 'maria.santos@uratex.com.ph', 'Maria Santos', 'retail', 'Logout', 'Partner Portal Session', 'Partner agent ended portal session and signed out.', 'auth', '2', '127.0.0.1', '2026-08-24 18:00:19')
ON DUPLICATE KEY UPDATE `change_details` = VALUES(`change_details`);
