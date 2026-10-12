<?php
require_once __DIR__ . '/../config/config.php';

if (!isset($_SESSION['user_logged_in']) || $_SESSION['user_logged_in'] !== true) {
    header('Location: ../login.php');
    exit;
}

$currentUserId = (int) ($_SESSION['user_id'] ?? 0);
$message = '';
$messageType = 'success';
$db = getDbConnection();

if (empty($_SESSION['user_profile_csrf'])) {
    $_SESSION['user_profile_csrf'] = bin2hex(random_bytes(32));
}

$sessionStoreAccess = $_SESSION['store_access'] ?? [];
if (!is_array($sessionStoreAccess)) {
    $sessionStoreAccess = explode(',', (string) $sessionStoreAccess);
}

$profile = [
    'id' => $currentUserId,
    'username' => $_SESSION['user_username'] ?? '',
    'email' => $_SESSION['user_email'] ?? '',
    'full_name' => $_SESSION['user_name'] ?? '',
    'role' => $_SESSION['user_role'] ?? 'editor',
    'status' => 'active',
    'store_access' => implode(',', $sessionStoreAccess),
];

if ($db && $currentUserId > 0) {
    try {
        $profileStatement = $db->prepare(
            'SELECT id, username, email, full_name, role, status, store_access
             FROM users
             WHERE id = :id
             LIMIT 1'
        );
        $profileStatement->execute([':id' => $currentUserId]);
        $databaseProfile = $profileStatement->fetch(PDO::FETCH_ASSOC);

        if (!$databaseProfile) {
            $message = 'Your account could not be found. Please contact an administrator.';
            $messageType = 'danger';
        } else {
            $profile = $databaseProfile;
        }
    } catch (PDOException $exception) {
        error_log('Unable to load user profile: ' . $exception->getMessage());
        $message = 'Your profile could not be loaded. Please try again later.';
        $messageType = 'danger';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $submittedToken = $_POST['csrf_token'] ?? '';
    $fullName = trim($_POST['full_name'] ?? '');
    $email = strtolower(trim($_POST['email'] ?? ''));
    $password = $_POST['password'] ?? '';

    if (!is_string($submittedToken) || !hash_equals($_SESSION['user_profile_csrf'], $submittedToken)) {
        $message = 'Your session has expired. Reload the page and try again.';
        $messageType = 'danger';
    } elseif ($currentUserId <= 0) {
        $message = 'Your account identifier is missing. Please sign in again.';
        $messageType = 'danger';
    } elseif ($fullName === '' || $email === '') {
        $message = 'Full name and email address are required.';
        $messageType = 'danger';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $message = 'Please provide a valid email address.';
        $messageType = 'danger';
    } elseif ($password !== '' && strlen($password) < 6) {
        $message = 'Your new password must be at least 6 characters long.';
        $messageType = 'danger';
    } elseif (!$db) {
        if ($password !== '') {
            $message = 'Password changes are unavailable while the database is disconnected.';
            $messageType = 'danger';
        } else {
            $_SESSION['user_name'] = $fullName;
            $_SESSION['user_email'] = $email;
            $profile['full_name'] = $fullName;
            $profile['email'] = $email;
            $message = 'Your profile details were updated for this session.';
        }
    } else {
        try {
            $duplicateEmailStatement = $db->prepare(
                'SELECT id FROM users WHERE email = :email AND id <> :id LIMIT 1'
            );
            $duplicateEmailStatement->execute([
                ':email' => $email,
                ':id' => $currentUserId,
            ]);

            if ($duplicateEmailStatement->fetch()) {
                $message = 'Another user already uses this email address.';
                $messageType = 'danger';
            } else {
                if ($password !== '') {
                    $updateStatement = $db->prepare(
                        'UPDATE users
                         SET full_name = :full_name, email = :email, password_hash = :password_hash
                         WHERE id = :id'
                    );
                    $updateStatement->execute([
                        ':full_name' => $fullName,
                        ':email' => $email,
                        ':password_hash' => password_hash($password, PASSWORD_BCRYPT),
                        ':id' => $currentUserId,
                    ]);
                } else {
                    $updateStatement = $db->prepare(
                        'UPDATE users SET full_name = :full_name, email = :email WHERE id = :id'
                    );
                    $updateStatement->execute([
                        ':full_name' => $fullName,
                        ':email' => $email,
                        ':id' => $currentUserId,
                    ]);
                }

                $_SESSION['user_name'] = $fullName;
                $_SESSION['user_email'] = $email;
                $profile['full_name'] = $fullName;
                $profile['email'] = $email;

                recordUserLog(
                    'Profile Updated',
                    'My Profile',
                    'Updated personal profile details.' . ($password !== '' ? ' Password updated.' : ''),
                    'user',
                    $currentUserId,
                    'success'
                );

                $message = 'Your profile has been updated successfully.';
            }
        } catch (PDOException $exception) {
            error_log('Unable to update user profile: ' . $exception->getMessage());
            $message = 'Your profile could not be updated. Please try again later.';
            $messageType = 'danger';
        }
    }
}

$roleLabels = [
    'admin' => 'Administrator',
    'editor' => 'Editor',
    'customer_service' => 'Customer Service',
];
$storeLabels = [
    'retail' => 'Retail Store (Consumer)',
    'business' => 'Business Store (B2B)',
];
$assignedStores = array_filter(explode(',', $profile['store_access'] ?? ''));

$pageTitle = 'My Profile';
include __DIR__ . '/../includes/header.php';
include __DIR__ . '/../includes/sidebar.php';
?>

<div class="content-wrapper">
  <div class="content-header bg-white border-bottom pb-3 mb-3">
    <div class="container-fluid">
      <h1 class="m-0 font-weight-bold text-dark" style="color: #003399 !important; font-size: 24px;">
        <i class="fas fa-user-cog mr-2"></i>My Profile
      </h1>
      <p class="text-muted small mb-0 mt-1">Update your personal details and password. Role and access settings are managed by an administrator.</p>
    </div>
  </div>

  <section class="content">
    <div class="container-fluid">
      <div class="row">
        <div class="col-lg-8">
          <?php if ($message !== ''): ?>
            <div class="alert alert-<?php echo $messageType; ?> alert-dismissible fade show" role="alert">
              <?php echo htmlspecialchars($message); ?>
              <button type="button" class="close" data-dismiss="alert" aria-label="Close"><span aria-hidden="true">&times;</span></button>
            </div>
          <?php endif; ?>

          <div class="card shadow-sm border-0" style="border-radius: 12px;">
            <form method="post" action="user_profile.php">
              <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['user_profile_csrf']); ?>">
              <div class="card-header bg-white border-bottom">
                <h2 class="card-title font-weight-bold mb-0"><i class="fas fa-id-card mr-2 text-primary"></i>Personal Details</h2>
              </div>
              <div class="card-body">
                <div class="form-group">
                  <label for="profileUsername" class="font-weight-bold">Username</label>
                  <input type="text" id="profileUsername" class="form-control bg-light" value="<?php echo htmlspecialchars($profile['username']); ?>" readonly>
                  <small class="form-text text-muted">Your username is managed by an administrator.</small>
                </div>
                <div class="form-group">
                  <label for="profileFullName" class="font-weight-bold">Full Name</label>
                  <input type="text" name="full_name" id="profileFullName" class="form-control" value="<?php echo htmlspecialchars($profile['full_name']); ?>" required>
                </div>
                <div class="form-group">
                  <label for="profileEmail" class="font-weight-bold">Email Address</label>
                  <input type="email" name="email" id="profileEmail" class="form-control" value="<?php echo htmlspecialchars($profile['email']); ?>" required>
                </div>
                <div class="form-group mb-0">
                  <label for="profilePassword" class="font-weight-bold">New Password <span class="text-muted font-weight-normal">(leave blank to keep your current password)</span></label>
                  <input type="password" name="password" id="profilePassword" class="form-control" minlength="6" autocomplete="new-password">
                </div>
              </div>
              <div class="card-footer bg-light text-right">
                <button type="submit" class="btn btn-primary font-weight-bold" style="background-color: #003399; border-color: #002266;">
                  <i class="fas fa-save mr-1"></i> Save Changes
                </button>
              </div>
            </form>
          </div>
        </div>

        <div class="col-lg-4 mt-3 mt-lg-0">
          <div class="card shadow-sm border-0" style="border-radius: 12px;">
            <div class="card-header bg-white border-bottom">
              <h2 class="card-title font-weight-bold mb-0"><i class="fas fa-shield-alt mr-2 text-warning"></i>Account Access</h2>
            </div>
            <div class="card-body">
              <dl class="mb-0">
                <dt class="text-muted text-uppercase small">Portal Role</dt>
                <dd class="font-weight-bold"><?php echo htmlspecialchars($roleLabels[$profile['role']] ?? ucfirst($profile['role'])); ?></dd>
                <dt class="text-muted text-uppercase small">Account Status</dt>
                <dd><span class="badge badge-<?php echo ($profile['status'] ?? '') === 'active' ? 'success' : 'secondary'; ?>"><?php echo htmlspecialchars(ucfirst($profile['status'] ?? 'unknown')); ?></span></dd>
                <dt class="text-muted text-uppercase small">Assigned Stores</dt>
                <dd class="mb-0">
                  <?php if ($assignedStores): ?>
                    <?php foreach ($assignedStores as $store): ?>
                      <span class="badge badge-light border mr-1 mb-1"><?php echo htmlspecialchars($storeLabels[$store] ?? $store); ?></span>
                    <?php endforeach; ?>
                  <?php else: ?>
                    <span class="text-muted">No stores assigned</span>
                  <?php endif; ?>
                </dd>
              </dl>
            </div>
          </div>
        </div>
      </div>
    </div>
  </section>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
