<?php
/**
 * Uratex Shopify SEO Partner Portal - User Management Module
 * Module: user_management.php
 * Handles Add, Edit, Delete of users in `users` table.
 * Strictly restricted to users with 'admin' role.
 */
require_once __DIR__ . '/../config/config.php';

if (!isset($_SESSION['user_logged_in'])) {
    header("Location: ../login.php");
    exit;
}

$userRole = $_SESSION['user_role'] ?? 'editor';
$currentUserEmail = $_SESSION['user_email'] ?? 'jenor.ricafort@uratex.com.ph';
$currentUserId = $_SESSION['user_id'] ?? 1;
$activeStore = $_SESSION['active_store'] ?? 'business';

// Switch Store Handling
if (isset($_GET['switch_store']) && in_array($_GET['switch_store'], ['retail', 'business'])) {
    setActiveStore($_GET['switch_store']);
    header("Location: user_management.php");
    exit;
}

$db = getDbConnection();
$message = '';
$messageType = 'success';

// -----------------------------------------------------------------------------
// STRICT RBAC CHECK: ADMIN ROLE REQUIRED
// -----------------------------------------------------------------------------
$isAdmin = ($userRole === 'admin');

// Auto-initialize users table if not exists & seed initial admins/editors
if ($db) {
    try {
        $db->exec("CREATE TABLE IF NOT EXISTS `users` (
          `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
          `username` VARCHAR(50) NOT NULL,
          `email` VARCHAR(100) NOT NULL,
          `password_hash` VARCHAR(255) NOT NULL,
          `full_name` VARCHAR(100) NOT NULL,
          `role` ENUM('admin', 'editor') NOT NULL DEFAULT 'editor',
          `status` ENUM('active', 'inactive', 'suspended') NOT NULL DEFAULT 'active',
          `store_access` VARCHAR(100) NOT NULL DEFAULT 'retail,business',
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
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");

        // Seed default users if empty
        $checkUsers = $db->query("SELECT COUNT(*) FROM users");
        if ((int)$checkUsers->fetchColumn() === 0) {
            $defaultHashAdmin = password_hash('Ric@fort2025', PASSWORD_BCRYPT);
            $defaultHashEditor = password_hash('Editor@2026!', PASSWORD_BCRYPT);

            $seedStmt = $db->prepare("INSERT INTO users (username, email, password_hash, full_name, role, status, store_access, last_login_at) VALUES 
              ('admin', 'jenor.ricafort@uratex.com.ph', ?, 'Jenor Ricafort', 'admin', 'active', 'retail,business', NOW()),
              ('editor', 'maria.santos@uratex.com.ph', ?, 'Maria Santos', 'editor', 'active', 'business', NOW()),
              ('partner.agent', 'partner.agent@uratex.com.ph', ?, 'Partner SEO Agent', 'editor', 'active', 'retail,business', NOW())");
            $seedStmt->execute([$defaultHashAdmin, $defaultHashEditor, $defaultHashEditor]);
        }
    } catch (Exception $e) {
        // Fallback
    }
}

// -----------------------------------------------------------------------------
// POST ACTIONS: ADD, EDIT, DELETE USER (ADMIN ONLY)
// -----------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $isAdmin) {
    $action = $_POST['action'] ?? '';

    // 1. ADD USER
    if ($action === 'add_user') {
        $username = strtolower(trim($_POST['username'] ?? ''));
        $email = strtolower(trim($_POST['email'] ?? ''));
        $fullName = trim($_POST['full_name'] ?? '');
        $password = $_POST['password'] ?? '';
        $role = in_array($_POST['role'] ?? '', ['admin', 'editor']) ? $_POST['role'] : 'editor';
        $status = in_array($_POST['status'] ?? '', ['active', 'inactive', 'suspended']) ? $_POST['status'] : 'active';
        
        $stores = $_POST['store_access'] ?? ['business'];
        $storeAccess = is_array($stores) ? implode(',', $stores) : 'business';

        if (empty($username) || empty($email) || empty($fullName) || empty($password)) {
            $message = 'All fields (Username, Email, Full Name, Password) are required.';
            $messageType = 'danger';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $message = 'Please provide a valid email address.';
            $messageType = 'danger';
        } elseif (strlen($password) < 6) {
            $message = 'Password must be at least 6 characters long.';
            $messageType = 'danger';
        } else {
            $pwdHash = password_hash($password, PASSWORD_BCRYPT);

            if ($db) {
                try {
                    // Check if username or email exists
                    $checkStmt = $db->prepare("SELECT id FROM users WHERE username = ? OR email = ?");
                    $checkStmt->execute([$username, $email]);
                    if ($checkStmt->fetch()) {
                        $message = 'A user with this username or email already exists.';
                        $messageType = 'danger';
                    } else {
                        $insertStmt = $db->prepare("INSERT INTO users (username, email, password_hash, full_name, role, status, store_access) 
                          VALUES (?, ?, ?, ?, ?, ?, ?)");
                        $insertStmt->execute([$username, $email, $pwdHash, $fullName, $role, $status, $storeAccess]);
                        $newId = $db->lastInsertId();

                        // Log audit trail
                        recordUserLog(
                            'User Created',
                            "User: {$fullName} (@{$username})",
                            "Created account for {$email} with role [{$role}] and store access [{$storeAccess}].",
                            'system',
                            $newId,
                            'success'
                        );

                        $message = "User '{$fullName}' (@{$username}) successfully created.";
                        $messageType = 'success';
                    }
                } catch (Exception $e) {
                    $message = 'Database error: ' . $e->getMessage();
                    $messageType = 'danger';
                }
            } else {
                // Session fallback for demo environments
                if (!isset($_SESSION['demo_users'])) {
                    $_SESSION['demo_users'] = [
                        ['id' => 1, 'username' => 'admin', 'email' => 'jenor.ricafort@uratex.com.ph', 'full_name' => 'Jenor Ricafort', 'role' => 'admin', 'status' => 'active', 'store_access' => 'retail,business', 'created_at' => date('Y-m-d H:i:s')],
                        ['id' => 2, 'username' => 'editor', 'email' => 'maria.santos@uratex.com.ph', 'full_name' => 'Maria Santos', 'role' => 'editor', 'status' => 'active', 'store_access' => 'business', 'created_at' => date('Y-m-d H:i:s')],
                    ];
                }
                $newId = count($_SESSION['demo_users']) + 1;
                $_SESSION['demo_users'][] = [
                    'id' => $newId,
                    'username' => $username,
                    'email' => $email,
                    'full_name' => $fullName,
                    'role' => $role,
                    'status' => $status,
                    'store_access' => $storeAccess,
                    'created_at' => date('Y-m-d H:i:s')
                ];
                recordUserLog(
                    'User Created',
                    "User: {$fullName} (@{$username})",
                    "Created account for {$email} with role [{$role}] and store access [{$storeAccess}].",
                    'system',
                    $newId,
                    'success'
                );
                $message = "User '{$fullName}' (@{$username}) successfully created.";
                $messageType = 'success';
            }
        }
    }

    // 2. EDIT USER
    elseif ($action === 'edit_user') {
        $id = (int)($_POST['id'] ?? 0);
        $email = strtolower(trim($_POST['email'] ?? ''));
        $fullName = trim($_POST['full_name'] ?? '');
        $password = $_POST['password'] ?? '';
        $role = in_array($_POST['role'] ?? '', ['admin', 'editor']) ? $_POST['role'] : 'editor';
        $status = in_array($_POST['status'] ?? '', ['active', 'inactive', 'suspended']) ? $_POST['status'] : 'active';
        
        $stores = $_POST['store_access'] ?? ['business'];
        $storeAccess = is_array($stores) ? implode(',', $stores) : 'business';

        if ($id <= 0 || empty($email) || empty($fullName)) {
            $message = 'User ID, Email, and Full Name are required.';
            $messageType = 'danger';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $message = 'Please provide a valid email address.';
            $messageType = 'danger';
        } else {
            if ($db) {
                try {
                    // Check duplicate email
                    $checkStmt = $db->prepare("SELECT id FROM users WHERE email = ? AND id != ?");
                    $checkStmt->execute([$email, $id]);
                    if ($checkStmt->fetch()) {
                        $message = 'Another user with this email address already exists.';
                        $messageType = 'danger';
                    } else {
                        if (!empty($password)) {
                            if (strlen($password) < 6) {
                                $message = 'New password must be at least 6 characters long.';
                                $messageType = 'danger';
                            } else {
                                $pwdHash = password_hash($password, PASSWORD_BCRYPT);
                                $updStmt = $db->prepare("UPDATE users SET email = ?, full_name = ?, password_hash = ?, role = ?, status = ?, store_access = ? WHERE id = ?");
                                $updStmt->execute([$email, $fullName, $pwdHash, $role, $status, $storeAccess, $id]);
                            }
                        } else {
                            $updStmt = $db->prepare("UPDATE users SET email = ?, full_name = ?, role = ?, status = ?, store_access = ? WHERE id = ?");
                            $updStmt->execute([$email, $fullName, $role, $status, $storeAccess, $id]);
                        }

                        if ($messageType !== 'danger') {
                            recordUserLog(
                                'User Updated',
                                "User: {$fullName} (ID #{$id})",
                                "Modified account settings: Email: {$email}, Role: {$role}, Status: {$status}, Store Access: {$storeAccess}." . (!empty($password) ? " Password updated." : ""),
                                'system',
                                $id,
                                'success'
                            );
                            $message = "User '{$fullName}' (ID #{$id}) updated successfully.";
                            $messageType = 'success';
                        }
                    }
                } catch (Exception $e) {
                    $message = 'Database error: ' . $e->getMessage();
                    $messageType = 'danger';
                }
            } else {
                // Session fallback
                if (isset($_SESSION['demo_users'])) {
                    foreach ($_SESSION['demo_users'] as &$u) {
                        if ($u['id'] == $id) {
                            $u['email'] = $email;
                            $u['full_name'] = $fullName;
                            $u['role'] = $role;
                            $u['status'] = $status;
                            $u['store_access'] = $storeAccess;
                            break;
                        }
                    }
                }
                recordUserLog(
                    'User Updated',
                    "User: {$fullName} (ID #{$id})",
                    "Modified account settings: Email: {$email}, Role: {$role}, Status: {$status}.",
                    'system',
                    $id,
                    'success'
                );
                $message = "User '{$fullName}' (ID #{$id}) updated successfully.";
                $messageType = 'success';
            }
        }
    }

    // 3. DELETE USER
    elseif ($action === 'delete_user') {
        $id = (int)($_POST['id'] ?? 0);

        if ($id <= 0) {
            $message = 'Invalid user ID specified.';
            $messageType = 'danger';
        } elseif ($id == $currentUserId || (isset($_SESSION['user_email']) && $_SESSION['user_email'] === $_POST['user_email_check'])) {
            $message = 'Security Policy Violation: You cannot delete your own active administrator account.';
            $messageType = 'danger';
        } else {
            if ($db) {
                try {
                    // Fetch user info for logging
                    $findStmt = $db->prepare("SELECT username, email, full_name FROM users WHERE id = ?");
                    $findStmt->execute([$id]);
                    $targetUser = $findStmt->fetch(PDO::FETCH_ASSOC);

                    $delStmt = $db->prepare("DELETE FROM users WHERE id = ?");
                    $delStmt->execute([$id]);

                    $targetName = $targetUser['full_name'] ?? "User #{$id}";
                    $targetEmail = $targetUser['email'] ?? '';

                    recordUserLog(
                        'User Deleted',
                        "User: {$targetName} (@" . ($targetUser['username'] ?? '') . ")",
                        "Permanently deleted portal account {$targetEmail} (ID #{$id}).",
                        'system',
                        $id,
                        'warning'
                    );

                    $message = "User '{$targetName}' (ID #{$id}) was permanently deleted.";
                    $messageType = 'success';
                } catch (Exception $e) {
                    $message = 'Database error: ' . $e->getMessage();
                    $messageType = 'danger';
                }
            } else {
                // Session fallback
                if (isset($_SESSION['demo_users'])) {
                    $_SESSION['demo_users'] = array_filter($_SESSION['demo_users'], function($u) use ($id) {
                        return $u['id'] != $id;
                    });
                }
                recordUserLog(
                    'User Deleted',
                    "User ID #{$id}",
                    "Permanently deleted user account #{$id}.",
                    'system',
                    $id,
                    'warning'
                );
                $message = "User #{$id} was permanently deleted.";
                $messageType = 'success';
            }
        }
    }
}

// -----------------------------------------------------------------------------
// FETCH USERS LIST WITH SEARCH & FILTERS
// -----------------------------------------------------------------------------
$search = trim($_GET['search'] ?? '');
$filterRole = trim($_GET['role_filter'] ?? '');
$filterStatus = trim($_GET['status_filter'] ?? '');

$usersList = [];
$totalUsersCount = 0;
$adminCount = 0;
$editorCount = 0;
$activeCount = 0;

if ($isAdmin) {
    if ($db) {
        try {
            $whereSql = "WHERE 1=1";
            $params = [];

            if (!empty($search)) {
                $whereSql .= " AND (username LIKE :search OR email LIKE :search OR full_name LIKE :search)";
                $params[':search'] = "%{$search}%";
            }

            if (!empty($filterRole) && in_array($filterRole, ['admin', 'editor'])) {
                $whereSql .= " AND role = :role_filter";
                $params[':role_filter'] = $filterRole;
            }

            if (!empty($filterStatus) && in_array($filterStatus, ['active', 'inactive', 'suspended'])) {
                $whereSql .= " AND status = :status_filter";
                $params[':status_filter'] = $filterStatus;
            }

            $stmt = $db->prepare("SELECT * FROM users {$whereSql} ORDER BY id ASC");
            $stmt->execute($params);
            $usersList = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // KPI metrics
            $kpiStmt = $db->query("SELECT 
                COUNT(*) as total,
                SUM(CASE WHEN role = 'admin' THEN 1 ELSE 0 END) as admins,
                SUM(CASE WHEN role = 'editor' THEN 1 ELSE 0 END) as editors,
                SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) as active_users
                FROM users");
            $kpi = $kpiStmt->fetch(PDO::FETCH_ASSOC);
            if ($kpi) {
                $totalUsersCount = (int)$kpi['total'];
                $adminCount = (int)$kpi['admins'];
                $editorCount = (int)$kpi['editors'];
                $activeCount = (int)$kpi['active_users'];
            }
        } catch (Exception $e) {
            $usersList = [];
        }
    }

    if (empty($usersList) && !$db) {
        $demo = $_SESSION['demo_users'] ?? [
            ['id' => 1, 'username' => 'admin', 'email' => 'jenor.ricafort@uratex.com.ph', 'full_name' => 'Jenor Ricafort', 'role' => 'admin', 'status' => 'active', 'store_access' => 'retail,business', 'last_login_at' => '2026-08-25 14:30:00', 'created_at' => '2026-08-01 09:00:00'],
            ['id' => 2, 'username' => 'editor', 'email' => 'maria.santos@uratex.com.ph', 'full_name' => 'Maria Santos', 'role' => 'editor', 'status' => 'active', 'store_access' => 'business', 'last_login_at' => '2026-08-25 11:15:00', 'created_at' => '2026-08-10 10:30:00'],
            ['id' => 3, 'username' => 'partner.agent', 'email' => 'partner.agent@uratex.com.ph', 'full_name' => 'Partner SEO Agent', 'role' => 'editor', 'status' => 'active', 'store_access' => 'retail,business', 'last_login_at' => '2026-08-25 14:22:04', 'created_at' => '2026-08-15 14:00:00'],
        ];

        if (!empty($search)) {
            $demo = array_filter($demo, function($u) use ($search) {
                return stripos($u['username'], $search) !== false ||
                       stripos($u['email'], $search) !== false ||
                       stripos($u['full_name'], $search) !== false;
            });
        }
        if (!empty($filterRole)) {
            $demo = array_filter($demo, function($u) use ($filterRole) {
                return $u['role'] === $filterRole;
            });
        }
        if (!empty($filterStatus)) {
            $demo = array_filter($demo, function($u) use ($filterStatus) {
                return $u['status'] === $filterStatus;
            });
        }
        $usersList = array_values($demo);
        $totalUsersCount = count($demo);
        $adminCount = count(array_filter($demo, fn($u) => $u['role'] === 'admin'));
        $editorCount = count(array_filter($demo, fn($u) => $u['role'] === 'editor'));
        $activeCount = count(array_filter($demo, fn($u) => $u['status'] === 'active'));
    }
}

$pageTitle = 'User Management (Admin Console)';
include __DIR__ . '/../includes/header.php';
include __DIR__ . '/../includes/sidebar.php';
?>

<!-- Content Wrapper -->
<div class="content-wrapper">
  <!-- Content Header -->
  <div class="content-header bg-white border-bottom pb-3 mb-3">
    <div class="container-fluid">
      <div class="row align-items-center">
        <div class="col-sm-7">
          <div class="d-flex align-items-center gap-2">
            <h1 class="m-0 font-weight-bold text-dark" style="color: #003399 !important; font-size: 24px; letter-spacing: -0.5px;">
              User Management
            </h1>
            <span class="badge badge-danger ml-2 px-2.5 py-1 font-weight-bold text-uppercase" style="font-size: 10px; letter-spacing: 0.5px;">
              <i class="fas fa-shield-alt mr-1"></i> Admin Access Only
            </span>
          </div>
          <p class="text-muted small mb-0 mt-1">
            Manage partner portal user accounts, role-based permissions (Admin &amp; Editor), and Shopify store access privileges.
          </p>
        </div>
        <div class="col-sm-5 text-right">
          <?php if ($isAdmin): ?>
            <button type="button" class="btn btn-primary btn-sm shadow-sm font-weight-bold" data-toggle="modal" data-target="#modalAddUser" style="background-color: #003399; border-color: #002266;">
              <i class="fas fa-user-plus mr-1.5"></i> Add New User
            </button>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </div>

  <!-- Main Content -->
  <section class="content">
    <div class="container-fluid">

      <!-- Alert Notification -->
      <?php if (!empty($message)): ?>
        <div class="alert alert-<?php echo $messageType; ?> alert-dismissible fade show shadow-sm" role="alert" style="border-radius: 8px;">
          <div class="d-flex align-items-center">
            <i class="fas <?php echo ($messageType === 'success') ? 'fa-check-circle' : 'fa-exclamation-triangle'; ?> mr-2 font-weight-bold" style="font-size: 16px;"></i>
            <span><?php echo htmlspecialchars($message); ?></span>
          </div>
          <button type="button" class="close" data-dismiss="alert" aria-label="Close">
            <span aria-hidden="true">&times;</span>
          </button>
        </div>
      <?php endif; ?>

      <?php if (!$isAdmin): ?>
        <!-- 403 Forbidden Access Block for Non-Admins -->
        <div class="card shadow-sm border-0 mt-4" style="border-radius: 12px;">
          <div class="card-body text-center py-5">
            <div class="rounded-circle d-inline-flex align-items-center justify-content-center bg-light text-danger mb-3" style="width: 70px; height: 70px;">
              <i class="fas fa-user-lock fa-2x"></i>
            </div>
            <h3 class="font-weight-bold text-dark mb-2">Access Restricted (Admin Role Required)</h3>
            <p class="text-muted mx-auto" style="max-width: 540px; font-size: 14px;">
              You are currently signed in as an <strong>Editor</strong> (<code><?php echo htmlspecialchars($currentUserEmail); ?></code>). 
              The User Management module is exclusively restricted to authorized administrators.
            </p>
            <a href="dashboard.php" class="btn btn-primary btn-sm px-4 font-weight-bold mt-2" style="background-color: #003399;">
              <i class="fas fa-arrow-left mr-1"></i> Return to Dashboard
            </a>
          </div>
        </div>
      <?php else: ?>

        <!-- KPI Summary Cards -->
        <div class="row mb-3">
          <div class="col-12 col-sm-6 col-md-3">
            <div class="info-box shadow-sm border">
              <span class="info-box-icon bg-primary elevation-1" style="background-color: #003399 !important;"><i class="fas fa-users"></i></span>
              <div class="info-box-content">
                <span class="info-box-text text-muted text-uppercase font-weight-bold" style="font-size: 11px;">Total Accounts</span>
                <span class="info-box-number font-weight-bold text-dark" style="font-size: 20px;"><?php echo number_format($totalUsersCount); ?></span>
              </div>
            </div>
          </div>

          <div class="col-12 col-sm-6 col-md-3">
            <div class="info-box shadow-sm border">
              <span class="info-box-icon bg-danger elevation-1"><i class="fas fa-user-shield"></i></span>
              <div class="info-box-content">
                <span class="info-box-text text-muted text-uppercase font-weight-bold" style="font-size: 11px;">Administrators</span>
                <span class="info-box-number font-weight-bold text-danger" style="font-size: 20px;"><?php echo number_format($adminCount); ?></span>
              </div>
            </div>
          </div>

          <div class="col-12 col-sm-6 col-md-3">
            <div class="info-box shadow-sm border">
              <span class="info-box-icon bg-info elevation-1"><i class="fas fa-user-edit"></i></span>
              <div class="info-box-content">
                <span class="info-box-text text-muted text-uppercase font-weight-bold" style="font-size: 11px;">SEO Editors</span>
                <span class="info-box-number font-weight-bold text-info" style="font-size: 20px;"><?php echo number_format($editorCount); ?></span>
              </div>
            </div>
          </div>

          <div class="col-12 col-sm-6 col-md-3">
            <div class="info-box shadow-sm border">
              <span class="info-box-icon bg-success elevation-1"><i class="fas fa-check-circle"></i></span>
              <div class="info-box-content">
                <span class="info-box-text text-muted text-uppercase font-weight-bold" style="font-size: 11px;">Active Status</span>
                <span class="info-box-number font-weight-bold text-success" style="font-size: 20px;"><?php echo number_format($activeCount); ?></span>
              </div>
            </div>
          </div>
        </div>

        <!-- Search & Filter Controls -->
        <div class="card shadow-sm border-0 mb-3" style="border-radius: 12px;">
          <div class="card-body p-3">
            <form method="get" action="user_management.php" class="row align-items-center">
              <div class="col-md-5 col-12 mb-2 mb-md-0">
                <div class="input-group">
                  <div class="input-group-prepend">
                    <span class="input-group-text bg-white border-right-0 text-muted"><i class="fas fa-search"></i></span>
                  </div>
                  <input 
                    type="text" 
                    name="search" 
                    class="form-control border-left-0 text-sm" 
                    placeholder="Search by name, email, or username..."
                    value="<?php echo htmlspecialchars($search); ?>"
                  >
                </div>
              </div>

              <div class="col-md-3 col-6 mb-2 mb-md-0">
                <select name="role_filter" class="form-control text-sm" onchange="this.form.submit()">
                  <option value="">All Roles</option>
                  <option value="admin" <?php echo ($filterRole === 'admin') ? 'selected' : ''; ?>>Admin (Full Access)</option>
                  <option value="editor" <?php echo ($filterRole === 'editor') ? 'selected' : ''; ?>>Editor (SEO Only)</option>
                </select>
              </div>

              <div class="col-md-2 col-6 mb-2 mb-md-0">
                <select name="status_filter" class="form-control text-sm" onchange="this.form.submit()">
                  <option value="">All Statuses</option>
                  <option value="active" <?php echo ($filterStatus === 'active') ? 'selected' : ''; ?>>Active</option>
                  <option value="inactive" <?php echo ($filterStatus === 'inactive') ? 'selected' : ''; ?>>Inactive</option>
                  <option value="suspended" <?php echo ($filterStatus === 'suspended') ? 'selected' : ''; ?>>Suspended</option>
                </select>
              </div>

              <div class="col-md-2 col-12 text-right">
                <button type="submit" class="btn btn-dark btn-sm px-3 font-weight-bold">Filter</button>
                <?php if (!empty($search) || !empty($filterRole) || !empty($filterStatus)): ?>
                  <a href="user_management.php" class="btn btn-outline-danger btn-sm ml-1" title="Reset Filters"><i class="fas fa-times"></i></a>
                <?php endif; ?>
              </div>
            </form>
          </div>
        </div>

        <!-- Users Table Card -->
        <div class="card shadow-sm border-0" style="border-radius: 12px; overflow: hidden;">
          <div class="card-header bg-white py-3 border-bottom d-flex justify-content-between align-items-center">
            <h3 class="card-title font-weight-bold text-dark mb-0" style="font-size: 15px;">
              <i class="fas fa-users-cog mr-2 text-primary"></i> Registered Portal Users (<code>users</code> table)
            </h3>
            <span class="badge badge-light border text-secondary px-2.5 py-1.5 font-weight-bold" style="font-size: 12px;">
              <?php echo count($usersList); ?> Users Found
            </span>
          </div>

          <div class="card-body p-0 table-responsive">
            <table class="table table-hover align-middle mb-0" style="font-size: 13px;">
              <thead style="background-color: #f8fafc; color: #475569; font-weight: 700; text-transform: uppercase; font-size: 11px; letter-spacing: 0.5px; border-bottom: 2px solid #e2e8f0;">
                <tr>
                  <th style="width: 260px; padding: 14px 16px;">USER DETAILS</th>
                  <th style="width: 140px; padding: 14px 16px;">ROLE</th>
                  <th style="width: 200px; padding: 14px 16px;">STORE ACCESS</th>
                  <th style="width: 110px; padding: 14px 16px;">STATUS</th>
                  <th style="width: 160px; padding: 14px 16px;">LAST LOGIN</th>
                  <th style="width: 120px; padding: 14px 16px; text-align: right;">ACTIONS</th>
                </tr>
              </thead>
              <tbody class="text-dark">
                <?php if (empty($usersList)): ?>
                  <tr>
                    <td colspan="6" class="text-center py-5 text-muted">
                      <i class="fas fa-user-slash fa-3x mb-3 text-secondary d-block"></i>
                      <p class="font-weight-bold mb-1">No user accounts found matching your filter criteria.</p>
                      <p class="small text-muted mb-0">Click "+ Add New User" above to create an account.</p>
                    </td>
                  </tr>
                <?php else: ?>
                  <?php foreach ($usersList as $u): ?>
                    <?php
                      $storesArr = explode(',', $u['store_access'] ?? 'business');
                      $isSelf = ($u['email'] === $currentUserEmail || $u['id'] == $currentUserId);
                    ?>
                    <tr style="border-bottom: 1px solid #f1f5f9;">
                      <!-- User Details -->
                      <td style="padding: 14px 16px;">
                        <div class="d-flex align-items-center">
                          <div class="rounded-circle d-flex align-items-center justify-content-center mr-3 font-weight-bold text-white shadow-sm" style="width: 36px; height: 36px; background-color: <?php echo ($u['role'] === 'admin') ? '#C8102E' : '#003399'; ?>; font-size: 13px;">
                            <?php 
                              $initials = strtoupper(substr($u['full_name'] ?? 'U', 0, 1));
                              echo htmlspecialchars($initials);
                            ?>
                          </div>
                          <div>
                            <div class="font-weight-bold text-dark d-flex align-items-center gap-1.5" style="font-size: 13.5px;">
                              <?php echo htmlspecialchars($u['full_name']); ?>
                              <?php if ($isSelf): ?>
                                <span class="badge badge-light border text-primary ml-1 font-weight-bold" style="font-size: 10px;">YOU</span>
                              <?php endif; ?>
                            </div>
                            <div class="text-muted font-monospace" style="font-size: 11.5px;">
                              <span><?php echo htmlspecialchars($u['email']); ?></span>
                              <span class="text-secondary ml-1.5">(<?php echo htmlspecialchars($u['username']); ?>)</span>
                            </div>
                          </div>
                        </div>
                      </td>

                      <!-- Role -->
                      <td style="padding: 14px 16px;">
                        <?php if ($u['role'] === 'admin'): ?>
                          <span class="badge px-2.5 py-1 font-weight-bold text-white" style="background-color: #C8102E; font-size: 11px; border-radius: 6px;">
                            <i class="fas fa-shield-alt mr-1"></i> Admin
                          </span>
                        <?php else: ?>
                          <span class="badge px-2.5 py-1 font-weight-bold" style="background-color: #e0f2fe; color: #0369a1; border: 1px solid #bae6fd; font-size: 11px; border-radius: 6px;">
                            <i class="fas fa-edit mr-1"></i> Editor
                          </span>
                        <?php endif; ?>
                      </td>

                      <!-- Store Access -->
                      <td style="padding: 14px 16px;">
                        <div class="d-flex flex-wrap gap-1">
                          <?php if (in_array('retail', $storesArr)): ?>
                            <span class="badge px-2 py-0.5 font-weight-bold mr-1" style="background-color: #f1f5f9; color: #334155; border: 1px solid #cbd5e1; font-size: 10.5px;">
                              Retail (Consumer)
                            </span>
                          <?php endif; ?>
                          <?php if (in_array('business', $storesArr)): ?>
                            <span class="badge px-2 py-0.5 font-weight-bold" style="background-color: #f8fafc; color: #003399; border: 1px solid #bfdbfe; font-size: 10.5px;">
                              Business (B2B)
                            </span>
                          <?php endif; ?>
                        </div>
                      </td>

                      <!-- Status -->
                      <td style="padding: 14px 16px;">
                        <?php if ($u['status'] === 'active'): ?>
                          <span class="badge px-2 py-1 font-weight-bold" style="background-color: #d1fae5; color: #065f46; border: 1px solid #a7f3d0; font-size: 11px;">
                            <i class="fas fa-check mr-1"></i> Active
                          </span>
                        <?php elseif ($u['status'] === 'inactive'): ?>
                          <span class="badge px-2 py-1 font-weight-bold" style="background-color: #f1f5f9; color: #64748b; border: 1px solid #cbd5e1; font-size: 11px;">
                            Inactive
                          </span>
                        <?php else: ?>
                          <span class="badge px-2 py-1 font-weight-bold" style="background-color: #fee2e2; color: #991b1b; border: 1px solid #fecaca; font-size: 11px;">
                            Suspended
                          </span>
                        <?php endif; ?>
                      </td>

                      <!-- Last Login -->
                      <td class="text-muted font-monospace" style="padding: 14px 16px; font-size: 11.5px; white-space: nowrap;">
                        <?php if (!empty($u['last_login_at'])): ?>
                          <i class="far fa-clock text-secondary mr-1"></i>
                          <?php echo htmlspecialchars($u['last_login_at']); ?>
                        <?php else: ?>
                          <span class="text-secondary italic">Never logged in</span>
                        <?php endif; ?>
                      </td>

                      <!-- Actions -->
                      <td style="padding: 14px 16px; text-align: right; white-space: nowrap;">
                        <button 
                          type="button" 
                          class="btn btn-outline-primary btn-sm py-1 px-2.5 mr-1 font-weight-bold"
                          onclick="openEditUserModal(<?php echo htmlspecialchars(json_encode($u)); ?>)"
                          title="Edit User"
                        >
                          <i class="fas fa-edit"></i> Edit
                        </button>
                        
                        <?php if (!$isSelf): ?>
                          <button 
                            type="button" 
                            class="btn btn-outline-danger btn-sm py-1 px-2.5 font-weight-bold"
                            onclick="confirmDeleteUser(<?php echo (int)$u['id']; ?>, '<?php echo htmlspecialchars(addslashes($u['full_name'])); ?>', '<?php echo htmlspecialchars(addslashes($u['email'])); ?>')"
                            title="Delete User"
                          >
                            <i class="fas fa-trash-alt"></i>
                          </button>
                        <?php else: ?>
                          <button 
                            type="button" 
                            class="btn btn-outline-secondary btn-sm py-1 px-2.5 disabled"
                            disabled
                            title="Cannot delete your active session"
                          >
                            <i class="fas fa-lock text-muted"></i>
                          </button>
                        <?php endif; ?>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                <?php endif; ?>
              </tbody>
            </table>
          </div>
        </div>

      <?php endif; ?>

    </div>
  </section>
</div>

<!-- ============================================================================== -->
<!-- MODAL: ADD USER -->
<!-- ============================================================================== -->
<div class="modal fade" id="modalAddUser" tabindex="-1" role="dialog" aria-labelledby="modalAddUserTitle" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered" role="document">
    <div class="modal-content border-0 shadow-lg" style="border-radius: 12px; overflow: hidden;">
      <div class="modal-header bg-primary text-white py-3 px-4" style="background-color: #003399 !important;">
        <h5 class="modal-title font-weight-bold" id="modalAddUserTitle" style="font-size: 16px;">
          <i class="fas fa-user-plus mr-2"></i> Create New Portal User
        </h5>
        <button type="button" class="close text-white opacity-90" data-dismiss="modal" aria-label="Close">
          <span aria-hidden="true">&times;</span>
        </button>
      </div>

      <form method="post" action="user_management.php">
        <input type="hidden" name="action" value="add_user">

        <div class="modal-body p-4">
          <div class="form-group mb-3">
            <label class="font-weight-bold text-dark small text-uppercase mb-1">Full Name <span class="text-danger">*</span></label>
            <input type="text" name="full_name" class="form-control text-sm" placeholder="e.g., Juan Dela Cruz" required>
          </div>

          <div class="row">
            <div class="col-md-6 form-group mb-3">
              <label class="font-weight-bold text-dark small text-uppercase mb-1">Username <span class="text-danger">*</span></label>
              <input type="text" name="username" class="form-control text-sm" placeholder="e.g., jdelacruz" required>
            </div>
            <div class="col-md-6 form-group mb-3">
              <label class="font-weight-bold text-dark small text-uppercase mb-1">Email Address <span class="text-danger">*</span></label>
              <input type="email" name="email" class="form-control text-sm" placeholder="e.g., juan@uratex.com.ph" required>
            </div>
          </div>

          <div class="form-group mb-3">
            <label class="font-weight-bold text-dark small text-uppercase mb-1">Password <span class="text-danger">*</span></label>
            <div class="input-group">
              <input type="password" name="password" id="addPasswordInput" class="form-control text-sm" placeholder="Min. 6 characters" required>
              <div class="input-group-append">
                <button class="btn btn-outline-secondary" type="button" onclick="togglePasswordVisibility('addPasswordInput')">
                  <i class="far fa-eye"></i>
                </button>
              </div>
            </div>
          </div>

          <div class="row">
            <div class="col-md-6 form-group mb-3">
              <label class="font-weight-bold text-dark small text-uppercase mb-1">Portal Role <span class="text-danger">*</span></label>
              <select name="role" class="form-control text-sm" required>
                <option value="editor">Editor (SEO Editing Only)</option>
                <option value="admin">Admin (Full System Access)</option>
              </select>
            </div>
            <div class="col-md-6 form-group mb-3">
              <label class="font-weight-bold text-dark small text-uppercase mb-1">Account Status</label>
              <select name="status" class="form-control text-sm">
                <option value="active" selected>Active</option>
                <option value="inactive">Inactive</option>
                <option value="suspended">Suspended</option>
              </select>
            </div>
          </div>

          <div class="form-group mb-0">
            <label class="font-weight-bold text-dark small text-uppercase mb-1">Assigned Shopify Stores</label>
            <div class="d-flex gap-3 mt-1">
              <div class="custom-control custom-checkbox mr-4">
                <input type="checkbox" class="custom-control-input" id="storeRetailAdd" name="store_access[]" value="retail" checked>
                <label class="custom-control-label text-sm" for="storeRetailAdd">Retail Store (Consumer)</label>
              </div>
              <div class="custom-control custom-checkbox">
                <input type="checkbox" class="custom-control-input" id="storeBusinessAdd" name="store_access[]" value="business" checked>
                <label class="custom-control-label text-sm" for="storeBusinessAdd">Business Store (B2B)</label>
              </div>
            </div>
          </div>
        </div>

        <div class="modal-footer bg-light px-4 py-3 border-top d-flex justify-content-between">
          <button type="button" class="btn btn-outline-secondary btn-sm px-3" data-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-primary btn-sm px-4 font-weight-bold" style="background-color: #003399;">
            <i class="fas fa-check mr-1.5"></i> Save &amp; Create User
          </button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- ============================================================================== -->
<!-- MODAL: EDIT USER -->
<!-- ============================================================================== -->
<div class="modal fade" id="modalEditUser" tabindex="-1" role="dialog" aria-labelledby="modalEditUserTitle" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered" role="document">
    <div class="modal-content border-0 shadow-lg" style="border-radius: 12px; overflow: hidden;">
      <div class="modal-header bg-dark text-white py-3 px-4">
        <h5 class="modal-title font-weight-bold" id="modalEditUserTitle" style="font-size: 16px;">
          <i class="fas fa-user-edit mr-2 text-warning"></i> Edit User Account
        </h5>
        <button type="button" class="close text-white opacity-90" data-dismiss="modal" aria-label="Close">
          <span aria-hidden="true">&times;</span>
        </button>
      </div>

      <form method="post" action="user_management.php">
        <input type="hidden" name="action" value="edit_user">
        <input type="hidden" name="id" id="editUserId">

        <div class="modal-body p-4">
          <div class="form-group mb-3">
            <label class="font-weight-bold text-dark small text-uppercase mb-1">Username (Fixed)</label>
            <input type="text" id="editUsernameInput" class="form-control text-sm bg-light" readonly>
          </div>

          <div class="form-group mb-3">
            <label class="font-weight-bold text-dark small text-uppercase mb-1">Full Name <span class="text-danger">*</span></label>
            <input type="text" name="full_name" id="editFullNameInput" class="form-control text-sm" required>
          </div>

          <div class="form-group mb-3">
            <label class="font-weight-bold text-dark small text-uppercase mb-1">Email Address <span class="text-danger">*</span></label>
            <input type="email" name="email" id="editEmailInput" class="form-control text-sm" required>
          </div>

          <div class="form-group mb-3">
            <label class="font-weight-bold text-dark small text-uppercase mb-1">
              New Password <span class="text-muted font-weight-normal font-italic">(Leave blank to keep unchanged)</span>
            </label>
            <div class="input-group">
              <input type="password" name="password" id="editPasswordInput" class="form-control text-sm" placeholder="Enter new password if changing">
              <div class="input-group-append">
                <button class="btn btn-outline-secondary" type="button" onclick="togglePasswordVisibility('editPasswordInput')">
                  <i class="far fa-eye"></i>
                </button>
              </div>
            </div>
          </div>

          <div class="row">
            <div class="col-md-6 form-group mb-3">
              <label class="font-weight-bold text-dark small text-uppercase mb-1">Portal Role <span class="text-danger">*</span></label>
              <select name="role" id="editRoleInput" class="form-control text-sm" required>
                <option value="editor">Editor (SEO Only)</option>
                <option value="admin">Admin (Full Access)</option>
              </select>
            </div>
            <div class="col-md-6 form-group mb-3">
              <label class="font-weight-bold text-dark small text-uppercase mb-1">Account Status</label>
              <select name="status" id="editStatusInput" class="form-control text-sm">
                <option value="active">Active</option>
                <option value="inactive">Inactive</option>
                <option value="suspended">Suspended</option>
              </select>
            </div>
          </div>

          <div class="form-group mb-0">
            <label class="font-weight-bold text-dark small text-uppercase mb-1">Assigned Shopify Stores</label>
            <div class="d-flex gap-3 mt-1">
              <div class="custom-control custom-checkbox mr-4">
                <input type="checkbox" class="custom-control-input" id="storeRetailEdit" name="store_access[]" value="retail">
                <label class="custom-control-label text-sm" for="storeRetailEdit">Retail Store (Consumer)</label>
              </div>
              <div class="custom-control custom-checkbox">
                <input type="checkbox" class="custom-control-input" id="storeBusinessEdit" name="store_access[]" value="business">
                <label class="custom-control-label text-sm" for="storeBusinessEdit">Business Store (B2B)</label>
              </div>
            </div>
          </div>
        </div>

        <div class="modal-footer bg-light px-4 py-3 border-top d-flex justify-content-between">
          <button type="button" class="btn btn-outline-secondary btn-sm px-3" data-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-primary btn-sm px-4 font-weight-bold" style="background-color: #003399;">
            <i class="fas fa-save mr-1.5"></i> Update User Account
          </button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- ============================================================================== -->
<!-- MODAL: CONFIRM DELETE USER -->
<!-- ============================================================================== -->
<div class="modal fade" id="modalDeleteUser" tabindex="-1" role="dialog" aria-labelledby="modalDeleteUserTitle" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered modal-sm" role="document">
    <div class="modal-content border-0 shadow-lg" style="border-radius: 12px; overflow: hidden;">
      <div class="modal-header bg-danger text-white py-3 px-4">
        <h5 class="modal-title font-weight-bold" id="modalDeleteUserTitle" style="font-size: 15px;">
          <i class="fas fa-trash-alt mr-2"></i> Delete User Account
        </h5>
        <button type="button" class="close text-white opacity-90" data-dismiss="modal" aria-label="Close">
          <span aria-hidden="true">&times;</span>
        </button>
      </div>

      <form method="post" action="user_management.php">
        <input type="hidden" name="action" value="delete_user">
        <input type="hidden" name="id" id="deleteUserId">
        <input type="hidden" name="user_email_check" id="deleteUserEmailCheck">

        <div class="modal-body p-4 text-center">
          <div class="text-danger mb-3">
            <i class="fas fa-exclamation-circle fa-3x"></i>
          </div>
          <p class="font-weight-bold text-dark mb-1" id="deleteUserNameText">Delete this user?</p>
          <p class="text-muted small mb-0">This operation is irreversible and will remove all credentials and store permissions.</p>
        </div>

        <div class="modal-footer bg-light px-4 py-3 border-top d-flex justify-content-between">
          <button type="button" class="btn btn-outline-secondary btn-sm px-3" data-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-danger btn-sm px-3 font-weight-bold">
            <i class="fas fa-trash-alt mr-1"></i> Confirm Delete
          </button>
        </div>
      </form>
    </div>
  </div>
</div>

<script>
function togglePasswordVisibility(inputId) {
  const el = document.getElementById(inputId);
  if (el) {
    el.type = (el.type === 'password') ? 'text' : 'password';
  }
}

function openEditUserModal(user) {
  document.getElementById('editUserId').value = user.id;
  document.getElementById('editUsernameInput').value = user.username;
  document.getElementById('editFullNameInput').value = user.full_name;
  document.getElementById('editEmailInput').value = user.email;
  document.getElementById('editPasswordInput').value = '';
  document.getElementById('editRoleInput').value = user.role;
  document.getElementById('editStatusInput').value = user.status;

  const stores = (user.store_access || '').split(',');
  document.getElementById('storeRetailEdit').checked = stores.includes('retail');
  document.getElementById('storeBusinessEdit').checked = stores.includes('business');

  $('#modalEditUser').modal('show');
}

function confirmDeleteUser(id, fullName, email) {
  document.getElementById('deleteUserId').value = id;
  document.getElementById('deleteUserEmailCheck').value = email;
  document.getElementById('deleteUserNameText').textContent = 'Permanently delete user ' + fullName + ' (' + email + ')?';
  $('#modalDeleteUser').modal('show');
}
</script>

<?php
include __DIR__ . '/../includes/footer.php';
?>
