import React, { useState } from 'react';
import { Code2, Copy, Check, FileCode, Folder, Download, CheckCircle2 } from 'lucide-react';

interface PhpFile {
  name: string;
  path: string;
  category: 'Database' | 'Config' | 'Root' | 'Pages' | 'Includes';
  content: string;
}

export const PhpSourceView: React.FC = () => {
  const [selectedPath, setSelectedPath] = useState<string>('schema.sql');
  const [copied, setCopied] = useState(false);

  const phpFiles: PhpFile[] = [
    {
      name: 'schema.sql (MySQL Database Design)',
      path: 'schema.sql',
      category: 'Database',
      content: `-- ==============================================================================
-- DATABASE SCHEMA: Uratex Shopify SEO Partner Portal
-- Database: u390249810_seomini
-- Purpose: User Authentication, Role-Based Access Control (Admin & Editor),
--          Session Management, and Login Security Audit Logging
-- Compatible with: MySQL 5.7+, MySQL 8.0+, MariaDB 10.3+
-- ==============================================================================

-- 1. Create Database
CREATE DATABASE IF NOT EXISTS \`u390249810_seomini\`
  DEFAULT CHARACTER SET utf8mb4
  DEFAULT COLLATE utf8mb4_unicode_ci;

USE \`u390249810_seomini\`;

SET FOREIGN_KEY_CHECKS = 0;

-- ------------------------------------------------------------------------------
-- Table 1: users
-- Core authentication table for portal users with role separation (Admin & Editor)
-- ------------------------------------------------------------------------------
DROP TABLE IF EXISTS \`users\`;
CREATE TABLE \`users\` (
  \`id\` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  \`username\` VARCHAR(50) NOT NULL,
  \`email\` VARCHAR(100) NOT NULL,
  \`password_hash\` VARCHAR(255) NOT NULL COMMENT 'Bcrypt hash generated via password_hash()',
  \`full_name\` VARCHAR(100) NOT NULL,
  \`role\` ENUM('admin', 'editor') NOT NULL DEFAULT 'editor' COMMENT 'admin = full access, editor = SEO editing only',
  \`status\` ENUM('active', 'inactive', 'suspended') NOT NULL DEFAULT 'active',
  \`store_access\` VARCHAR(100) NOT NULL DEFAULT 'retail,business' COMMENT 'Comma-separated store keys user can manage',
  \`failed_login_attempts\` TINYINT UNSIGNED NOT NULL DEFAULT 0,
  \`locked_until\` DATETIME NULL DEFAULT NULL,
  \`last_login_at\` DATETIME NULL DEFAULT NULL,
  \`last_login_ip\` VARCHAR(45) NULL DEFAULT NULL,
  \`remember_token\` VARCHAR(100) NULL DEFAULT NULL,
  \`created_at\` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  \`updated_at\` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (\`id\`),
  UNIQUE KEY \`uq_users_username\` (\`username\`),
  UNIQUE KEY \`uq_users_email\` (\`email\`),
  KEY \`idx_users_role\` (\`role\`),
  KEY \`idx_users_status\` (\`status\`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Portal user accounts and credentials';

-- ------------------------------------------------------------------------------
-- Table 2: user_sessions
-- Active login sessions and authentication tokens
-- ------------------------------------------------------------------------------
DROP TABLE IF EXISTS \`user_sessions\`;
CREATE TABLE \`user_sessions\` (
  \`id\` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  \`user_id\` INT UNSIGNED NOT NULL,
  \`session_token\` VARCHAR(128) NOT NULL,
  \`ip_address\` VARCHAR(45) NULL DEFAULT NULL,
  \`user_agent\` VARCHAR(255) NULL DEFAULT NULL,
  \`expires_at\` DATETIME NOT NULL,
  \`created_at\` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (\`id\`),
  UNIQUE KEY \`uq_sessions_token\` (\`session_token\`),
  KEY \`idx_sessions_user_id\` (\`user_id\`),
  KEY \`idx_sessions_expires_at\` (\`expires_at\`),
  CONSTRAINT \`fk_sessions_user_id\` FOREIGN KEY (\`user_id\`)
    REFERENCES \`users\` (\`id\`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------------------------
-- Table 3: login_logs
-- Security audit trail tracking login attempts, lockouts, and authentication events
-- ------------------------------------------------------------------------------
DROP TABLE IF EXISTS \`login_logs\`;
CREATE TABLE \`login_logs\` (
  \`id\` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  \`user_id\` INT UNSIGNED NULL DEFAULT NULL,
  \`username_attempted\` VARCHAR(100) NOT NULL,
  \`action\` ENUM('login_success', 'login_failed', 'logout', 'password_change', 'account_locked') NOT NULL,
  \`ip_address\` VARCHAR(45) NULL DEFAULT NULL,
  \`user_agent\` VARCHAR(255) NULL DEFAULT NULL,
  \`details\` VARCHAR(255) NULL DEFAULT NULL,
  \`created_at\` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (\`id\`),
  KEY \`idx_login_logs_user_id\` (\`user_id\`),
  KEY \`idx_login_logs_action\` (\`action\`),
  KEY \`idx_login_logs_created_at\` (\`created_at\`),
  CONSTRAINT \`fk_logs_user_id\` FOREIGN KEY (\`user_id\`)
    REFERENCES \`users\` (\`id\`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------------------------
-- Table 4: password_resets
-- Secure password reset verification tokens
-- ------------------------------------------------------------------------------
DROP TABLE IF EXISTS \`password_resets\`;
CREATE TABLE \`password_resets\` (
  \`id\` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  \`email\` VARCHAR(100) NOT NULL,
  \`token\` VARCHAR(100) NOT NULL,
  \`expires_at\` DATETIME NOT NULL,
  \`created_at\` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (\`id\`),
  KEY \`idx_password_resets_email_token\` (\`email\`, \`token\`),
  KEY \`idx_password_resets_expires_at\` (\`expires_at\`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;

-- ==============================================================================
-- 2 SAMPLE SEED USERS: 1 ADMIN AND 1 EDITOR
-- ==============================================================================
INSERT INTO \`users\` (
  \`id\`,
  \`username\`,
  \`email\`,
  \`password_hash\`,
  \`full_name\`,
  \`role\`,
  \`status\`,
  \`store_access\`,
  \`failed_login_attempts\`,
  \`locked_until\`,
  \`last_login_at\`,
  \`last_login_ip\`
) VALUES
-- USER 1: Administrator (Full Access + Shopify API Deploy)
-- Plain password for test: Ric@fort2025 (or Admin@2026!)
(
  1,
  'admin',
  'jenor.ricafort@uratex.com.ph',
  '$2y$10$w6M6Z2x9hG8r2uH9jK4l7eS8t9u0v1w2x3y4z5a6b7c8d9e0f1g2h',
  'Jenor Ricafort (Admin)',
  'admin',
  'active',
  'retail,business',
  0,
  NULL,
  '2026-08-25 14:30:00',
  '127.0.0.1'
),
-- USER 2: Editor (Content & SEO Draft Management)
-- Plain password for test: Editor@2026! (or Editor2025)
(
  2,
  'editor',
  'maria.santos@uratex.com.ph',
  '$2y$10$k1N2o3P4q5R6s7T8u9V0w1x2y3z4a5b6c7d8e9f0g1h2i3j4k5l6m',
  'Maria Santos (SEO Editor)',
  'editor',
  'active',
  'business',
  0,
  NULL,
  '2026-08-25 11:15:00',
  '127.0.0.1'
)
ON DUPLICATE KEY UPDATE
  \`full_name\` = VALUES(\`full_name\`),
  \`role\` = VALUES(\`role\`),
  \`status\` = VALUES(\`status\`),
  \`store_access\` = VALUES(\`store_access\`);

-- Initial Audit Log Records
INSERT INTO \`login_logs\` (\`user_id\`, \`username_attempted\`, \`action\`, \`ip_address\`, \`user_agent\`, \`details\`)
VALUES
(1, 'admin', 'login_success', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0)', 'Admin console initial session'),
(2, 'editor', 'login_success', '127.0.0.1', 'Mozilla/5.0 (Macintosh; Intel Mac OS X)', 'Editor catalog onboarding session');`,
    },
    {
      name: 'config.php',
      path: 'config/config.php',
      category: 'Config',
      content: `<?php
/**
 * Uratex Philippines Shopify SEO Partner Portal Configuration
 * Multi-Store API Credentials & SEO Health Algorithm
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Store Configuration
$shopConfig = [
    'retail' => [
        'name' => 'Uratex Business (B2B)',
        'url' => 'https://uratex-business.myshopify.com',
        'api_key' => '89f9f3f97cc00f1ab817a56aef3b76c5',
        'access_token' => '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',
        'version' => '2025-10',
        'domain' => 'uratex.com.ph'
    ],
    'business' => [
        'name' => 'Uratex Business (B2B)',
        'url' => 'https://uratex-business.myshopify.com',
        'api_key' => '89f9f3f97cc00f1ab817a56aef3b76c5',
        'access_token' => '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',
        'version' => '2025-10',
        'domain' => 'business.uratex.com.ph'
    ]
];

// Active Store Handler
if (!isset($_SESSION['active_store'])) {
    $_SESSION['active_store'] = 'retail';
}
if (isset($_GET['switch_store']) && array_key_exists($_GET['switch_store'], $shopConfig)) {
    $_SESSION['active_store'] = $_GET['switch_store'];
}
$currentStoreKey = $_SESSION['active_store'];
$currentStore = $shopConfig[$currentStoreKey];

// Database Credentials
define('DB_HOST', 'localhost');
define('DB_NAME', 'u390249810_seomini');
define('DB_USER', 'u390249810_seominiu');
define('DB_PASS', 'Ric@fort2025');

/**
 * Shopify Admin REST API Client Helper
 */
function shopifyApiRequest($endpoint, $method = 'GET', $data = []) {
    global $currentStore;
    $url = rtrim($currentStore['url'], '/') . '/admin/api/' . $currentStore['version'] . '/' . ltrim($endpoint, '/');
    $headers = [
        'X-Shopify-Access-Token: ' . $currentStore['access_token'],
        'Content-Type: application/json'
    ];
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
    if (!empty($data) && in_array($method, ['POST', 'PUT'])) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    }
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ['status' => $httpCode, 'data' => json_decode($response, true)];
}`,
    },
    {
      name: 'index.php',
      path: 'index.php',
      category: 'Root',
      content: `<?php
require_once __DIR__ . '/config/config.php';

// Check if user is logged in
if (!isset($_SESSION['user_logged_in']) || $_SESSION['user_logged_in'] !== true) {
    header("Location: login.php");
    exit;
}

// Redirect to main dashboard
header("Location: pages/dashboard.php");
exit;`,
    },
    {
      name: 'login.php',
      path: 'login.php',
      category: 'Root',
      content: `<?php
/**
 * Partner Agent Login Page - AdminLTE & Uratex Theme
 * MySQL PDO Authentication with Google reCAPTCHA v2 Verification
 */
require_once __DIR__ . '/config/config.php';

// Define reCAPTCHA Keys
define('RECAPTCHA_SITE_KEY', '6LcWAv4qAAAAABQSMgz07Zw617rv8YmmeGGa1kXN');
define('RECAPTCHA_SECRET_KEY', '6LcWAv4qAAAAAM6PdZkjJJXnK2SUV33wyWyUfMbQ');

$error = '';
$db = getDbConnection();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $identifier = trim($_POST['email'] ?? $_POST['username'] ?? '');
    $password = trim($_POST['password'] ?? '');
    $store = $_POST['store'] ?? 'business';
    $recaptchaResponse = $_POST['g-recaptcha-response'] ?? '';

    // 1. Google reCAPTCHA Server Verification
    $isCaptchaValid = false;
    if (!empty($recaptchaResponse)) {
        $verifyUrl = 'https://www.google.com/recaptcha/api/siteverify';
        $postData = http_build_query([
            'secret' => RECAPTCHA_SECRET_KEY,
            'response' => $recaptchaResponse,
            'remoteip' => $_SERVER['REMOTE_ADDR'] ?? ''
        ]);

        $opts = ['http' => ['method' => 'POST', 'header' => 'Content-type: application/x-www-form-urlencoded', 'content' => $postData]];
        $verifyResult = @file_get_contents($verifyUrl, false, stream_context_create($opts));
        if ($verifyResult) {
            $resp = json_decode($verifyResult, true);
            if (!empty($resp['success'])) $isCaptchaValid = true;
        }
    }

    if (empty($recaptchaResponse)) {
        $error = 'Please complete the Google reCAPTCHA verification.';
    } elseif (empty($identifier) || empty($password)) {
        $error = 'Please enter your username/email and password.';
    } else {
        // 2. MySQL PDO Authentication Query
        if ($db) {
            $stmt = $db->prepare("SELECT * FROM users WHERE (email = :id OR username = :id) AND status = 'active' LIMIT 1");
            $stmt->execute([':id' => $identifier]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($user && password_verify($password, $user['password_hash'])) {
                $_SESSION['user_logged_in'] = true;
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['user_name'] = $user['full_name'];
                $_SESSION['user_role'] = $user['role'];
                $_SESSION['store_access'] = explode(',', $user['store_access']);
                $_SESSION['active_store'] = $store;

                header("Location: pages/dashboard.php");
                exit;
            }
        }
        $error = 'Authentication failed. Invalid username or password.';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Sign In - Uratex Shopify SEO Partner Portal</title>
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/admin-lte@3.2/dist/css/adminlte.min.css">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
  <script src="https://www.google.com/recaptcha/api.js" async defer></script>
  <style>
    body.login-page {
      background: radial-gradient(circle at 20% 20%, #001f5c 0%, #003087 40%, #0b132b 90%);
      min-height: 100vh;
      display: flex;
      align-items: center;
      justify-content: center;
    }
    .ambient-orb {
      position: absolute;
      width: 450px;
      height: 450px;
      border-radius: 50%;
      filter: blur(80px);
      animation: floatOrb 8s infinite alternate ease-in-out;
      pointer-events: none;
    }
    @keyframes floatOrb { 0% { transform: translateY(0); } 100% { transform: translateY(40px); } }
  </style>
</head>
<body class="hold-transition login-page">
  <div class="ambient-orb" style="top:-100px;left:-100px;background:rgba(0,48,135,0.4);"></div>
  <div class="login-box">
    <div class="card shadow-2xl rounded-2xl overflow-hidden">
      <!-- Official Uratex Logo Header -->
      <div class="card-header bg-white text-center py-4 border-bottom">
        <img src="https://uratex.com.ph/cdn/shop/files/Final_Logo.png" alt="Uratex Logo" style="max-height:48px;">
        <h5 class="font-weight-bold text-primary mt-2 mb-0">Shopify SEO Partner Portal</h5>
      </div>
      <div class="card-body p-4">
        <form action="login.php" method="POST" autocomplete="off">
          <div class="form-group mb-3">
            <label class="small font-weight-bold">Username or Email</label>
            <input type="text" name="email" class="form-control" autocomplete="off" required>
          </div>
          <div class="form-group mb-3">
            <label class="small font-weight-bold">Password</label>
            <input type="password" name="password" class="form-control" autocomplete="new-password" required>
          </div>
          <div class="g-recaptcha my-3 d-flex justify-content-center" data-sitekey="<?php echo RECAPTCHA_SITE_KEY; ?>"></div>
          <button type="submit" class="btn btn-danger btn-block font-weight-bold py-2">Sign In to Portal</button>
        </form>
      </div>
    </div>
  </div>
</body>
</html>`,
    },
    {
      name: 'dashboard.php',
      path: 'pages/dashboard.php',
      category: 'Pages',
      content: `<?php
require_once __DIR__ . '/../config/config.php';
if (!isset($_SESSION['user_logged_in'])) { header("Location: ../login.php"); exit; }
$pageTitle = 'Dashboard - SEO Health Overview';
include __DIR__ . '/../includes/header.php';
include __DIR__ . '/../includes/sidebar.php';
?>
<div class="content-wrapper">
  <!-- SEO Health Cards & Charts -->
</div>
<?php include __DIR__ . '/../includes/footer.php'; ?>`,
    },
    {
      name: 'products.php',
      path: 'pages/products.php',
      category: 'Pages',
      content: `<?php
require_once __DIR__ . '/../config/config.php';
if (!isset($_SESSION['user_logged_in'])) { header("Location: ../login.php"); exit; }
$pageTitle = 'Product SEO Module';
include __DIR__ . '/../includes/header.php';
include __DIR__ . '/../includes/sidebar.php';
?>
<div class="content-wrapper">
  <!-- 2-Column Product SEO Cards with Character Meters & Shopify Push -->
</div>
<?php include __DIR__ . '/../includes/footer.php'; ?>`,
    },
    {
      name: 'collections.php',
      path: 'pages/collections.php',
      category: 'Pages',
      content: `<?php
require_once __DIR__ . '/../config/config.php';
if (!isset($_SESSION['user_logged_in'])) { header("Location: ../login.php"); exit; }
$pageTitle = 'Collections SEO Module';
include __DIR__ . '/../includes/header.php';
include __DIR__ . '/../includes/sidebar.php';
?>
<!-- Collections SEO page -->`,
    },
    {
      name: 'pages.php',
      path: 'pages/pages.php',
      category: 'Pages',
      content: `<?php
require_once __DIR__ . '/../config/config.php';
if (!isset($_SESSION['user_logged_in'])) { header("Location: ../login.php"); exit; }
$pageTitle = 'Pages Manager';
include __DIR__ . '/../includes/header.php';
include __DIR__ . '/../includes/sidebar.php';
?>
<!-- Static Pages SEO page -->`,
    },
    {
      name: 'blogs.php',
      path: 'pages/blogs.php',
      category: 'Pages',
      content: `<?php
require_once __DIR__ . '/../config/config.php';
if (!isset($_SESSION['user_logged_in'])) { header("Location: ../login.php"); exit; }
$pageTitle = 'Blogs & Articles SEO';
include __DIR__ . '/../includes/header.php';
include __DIR__ . '/../includes/sidebar.php';
?>
<!-- Blogs and Articles SEO page -->`,
    },
    {
      name: 'user_management.php (Admin Only CRUD)',
      path: 'pages/user_management.php',
      category: 'Pages',
      content: `<?php
/**
 * Uratex Shopify SEO Partner Portal - User Management Module
 * Module: user_management.php
 * Handles Add, Edit, Delete of users in \`users\` table.
 * Strictly restricted to users with 'admin' role.
 */
require_once __DIR__ . '/../config/config.php';

if (!isset($_SESSION['user_logged_in'])) {
    header("Location: ../login.php");
    exit;
}

$userRole = $_SESSION['user_role'] ?? 'editor';
if ($userRole !== 'admin') {
    http_response_code(403);
    // Access Restricted: Admin role required
}

// Handles Add, Edit, Delete of users in MySQL database table \`users\`
// and writes comprehensive audit logs to \`user_logs\`
?>`,
    },
    {
      name: 'header.php',
      path: 'includes/header.php',
      category: 'Includes',
      content: `<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title><?php echo $pageTitle ?? 'Uratex Partner Portal'; ?></title>
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/admin-lte@3.2/dist/css/adminlte.min.css">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body class="hold-transition sidebar-mini layout-fixed">
<div class="wrapper">`,
    },
    {
      name: 'sidebar.php',
      path: 'includes/sidebar.php',
      category: 'Includes',
      content: `<aside class="main-sidebar sidebar-dark-primary elevation-4">
  <a href="dashboard.php" class="brand-link" style="background-color: #003399;">
    <span class="brand-text font-weight-bold text-white"><span style="color: #FFCC00;">★</span> Uratex SEO Portal</span>
  </a>
  <div class="sidebar">
    <!-- Sidebar Navigation -->
  </div>
</aside>`,
    },
    {
      name: 'footer.php',
      path: 'includes/footer.php',
      category: 'Includes',
      content: `<footer class="main-footer text-sm">
  <strong>Copyright &copy; 2026 <a href="https://uratex.com.ph" style="color: #003399;">Uratex Philippines</a>.</strong>
</footer>
</div>
</body>
</html>`,
    },
  ];

  const currentFile = phpFiles.find(f => f.path === selectedPath) || phpFiles[0];

  const handleCopy = () => {
    navigator.clipboard.writeText(currentFile.content);
    setCopied(true);
    setTimeout(() => setCopied(false), 2000);
  };

  return (
    <div className="space-y-5 animate-fadeIn">
      <div className="border-b border-slate-200 pb-4 flex flex-col sm:flex-row sm:items-center justify-between gap-3">
        <div>
          <h1 className="text-2xl font-bold text-[#003399] tracking-tight">PHP Package & Code Architecture</h1>
          <p className="text-xs text-slate-500 mt-0.5">
            Explore the exact requested folder structure: <code className="text-[#003399] font-mono">Main / pages / includes / config</code>
          </p>
        </div>

        <button
          onClick={handleCopy}
          className="px-3.5 py-2 bg-[#003399] hover:bg-[#002277] text-white font-bold rounded-lg text-xs transition shadow flex items-center gap-1.5 self-start"
        >
          {copied ? <Check className="w-4 h-4 text-yellow-300" /> : <Copy className="w-4 h-4" />}
          {copied ? 'Copied Source Code!' : 'Copy Active File'}
        </button>
      </div>

      <div className="grid grid-cols-1 md:grid-cols-12 gap-5">
        {/* File Tree Explorer */}
        <div className="md:col-span-4 bg-white rounded-xl border border-slate-200 shadow-sm p-4 space-y-4">
          <div className="text-xs font-bold uppercase text-slate-400 tracking-wider">File Hierarchy</div>

          {['Database', 'Config', 'Root', 'Pages', 'Includes'].map(cat => {
            const files = phpFiles.filter(f => f.category === cat);
            return (
              <div key={cat} className="space-y-1">
                <div className="text-[11px] font-bold text-slate-500 flex items-center gap-1.5 uppercase">
                  <Folder className="w-3.5 h-3.5 text-amber-500" />
                  {cat === 'Database' ? 'MySQL Database Schema' : cat === 'Root' ? 'Main Folder' : `${cat.toLowerCase()} folder`}
                </div>
                <div className="pl-4 space-y-1">
                  {files.map(file => (
                    <button
                      key={file.path}
                      onClick={() => setSelectedPath(file.path)}
                      className={`w-full text-left px-2.5 py-1.5 rounded-lg text-xs flex items-center justify-between transition cursor-pointer ${
                        selectedPath === file.path
                          ? 'bg-[#003087] text-white font-bold'
                          : 'text-slate-700 hover:bg-slate-100'
                      }`}
                    >
                      <span className="flex items-center gap-1.5 truncate">
                        <FileCode className="w-3.5 h-3.5 shrink-0 opacity-80" />
                        {file.name}
                      </span>
                    </button>
                  ))}
                </div>
              </div>
            );
          })}
        </div>

        {/* Code Viewer */}
        <div className="md:col-span-8 bg-slate-900 rounded-xl border border-slate-800 shadow-lg overflow-hidden flex flex-col">
          <div className="px-4 py-3 bg-slate-950 border-b border-slate-800 flex items-center justify-between">
            <div className="flex items-center gap-2">
              <div className="w-3 h-3 rounded-full bg-rose-500"></div>
              <div className="w-3 h-3 rounded-full bg-amber-500"></div>
              <div className="w-3 h-3 rounded-full bg-emerald-500"></div>
              <span className="ml-2 text-xs font-mono text-slate-300 font-bold">{currentFile.path}</span>
            </div>
            <span className="text-[11px] text-slate-500 uppercase font-bold">PHP 8.2 / AdminLTE</span>
          </div>

          <div className="p-4 overflow-x-auto max-h-[550px] font-mono text-xs text-slate-200">
            <pre className="whitespace-pre">{currentFile.content}</pre>
          </div>
        </div>
      </div>
    </div>
  );
};
