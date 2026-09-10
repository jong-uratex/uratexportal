<?php
/**
 * URL Redirects Module (redirect.php) - Uratex Shopify SEO Partner Portal
 *
 * Features:
 *  1. Fetches ALL URL redirects from Shopify REST API (redirects.json with Link pagination)
 *  2. Saves & persists redirects in MySQL table `shopify_redirects`
 *  3. Categorized strictly according to active store (retail / business)
 *  4. Create / Update / Delete redirects (pushed live to Shopify API)
 *  5. 20 Redirects Per Page Pagination with search (path / target)
 *  6. Defensive error handling – never dies with HTTP 500
 */
require_once __DIR__ . '/../config/config.php';

// Auth Guard
if (!isset($_SESSION['user_logged_in'])) {
    header("Location: ../login.php");
    exit;
}

// Active Store Handling
if (isset($_GET['store']) && in_array($_GET['store'], ['retail', 'business'])) {
    $_SESSION['active_store'] = $_GET['store'];
} elseif (isset($_GET['switch_store']) && in_array($_GET['switch_store'], ['retail', 'business'])) {
    $_SESSION['active_store'] = $_GET['switch_store'];
    recordUserLog('Switch Store', 'Active Store', "Switched active store to '{$_GET['switch_store']}' from URL Redirects module.", 'system', null, 'success');
}

$db          = getDbConnection();
$activeStore = $_SESSION['active_store'] ?? 'business';
$currentUser = $_SESSION['user_name'] ?? 'Jenor Ricafort';
$shopCfg     = $shopConfig[$activeStore] ?? $shopConfig['business'];
$message     = '';

/**
 * Returns the best .myshopify.com domain for Admin API calls.
 */
function getShopifyAdminDomain(array $shopCfg, string $activeStore): string
{
    if (!empty($shopCfg['url'])) {
        return $shopCfg['url'];
    }
    if (!empty($shopCfg['fallback_url'])) {
        return $shopCfg['fallback_url'];
    }
    return ($activeStore === 'business')
        ? 'uratex-business.myshopify.com'
        : 'uratex-philippines.myshopify.com';
}

/**
 * Normalise a redirect path: must start with "/".
 */
function normaliseRedirectPath(string $path): string
{
    $path = trim($path);
    if ($path === '') {
        return '';
    }
    if ($path[0] !== '/') {
        $path = '/' . $path;
    }
    return $path;
}

/**
 * Validate redirect target: must start with "/" or be a valid http(s) URL.
 */
function isValidRedirectTarget(string $target): bool
{
    $target = trim($target);
    if ($target === '') {
        return false;
    }
    if (strpos($target, '/') === 0) {
        return strlen($target) > 1;
    }
    return (bool)preg_match('#^https?://[^\s/$.?#].[^\s]*$#i', $target);
}

/**
 * Add a new URL redirect: validates input, creates it live on Shopify
 * (POST /admin/api/{version}/redirects.json), then caches it locally.
 *
 * @return array{success: bool, message: string, shopify_id: int}
 */
function createShopifyRedirect($db, array $shopCfg, string $activeStore, string $currentUser, string $path, string $target): array
{
    $path   = normaliseRedirectPath($path);
    $target = trim($target);

    if ($path === '' || $target === '') {
        throw new Exception('Both "From URL" (path) and "To URL" (target) are required.');
    }
    if (!isValidRedirectTarget($target)) {
        throw new Exception('Invalid target. Use a path starting with "/" or a full https:// URL.');
    }

    $adminDomain = getShopifyAdminDomain($shopCfg, $activeStore);
    $version     = !empty($shopCfg['version']) ? $shopCfg['version'] : '2025-10';
    $postUrl     = "https://{$adminDomain}/admin/api/{$version}/redirects.json";
    $payload     = json_encode(['redirect' => ['path' => $path, 'target' => $target]]);

    $ch = curl_init($postUrl);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_HTTPHEADER     => [
            "X-Shopify-Access-Token: " . ($shopCfg['access_token'] ?? ''),
            "Content-Type: application/json"
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_TIMEOUT        => 15,
    ]);
    $res      = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $rJson = json_decode(is_string($res) ? $res : '', true);

    if ($httpCode < 200 || $httpCode >= 300 || empty($rJson['redirect']['id'])) {
        $errDetail = $rJson['errors'] ?? substr(is_string($res) ? $res : '', 0, 300);
        throw new Exception("Shopify API returned HTTP {$httpCode}. " . (is_string($errDetail) ? $errDetail : json_encode($errDetail)));
    }

    $shopifyId = (int)$rJson['redirect']['id'];
    $savedPath   = $rJson['redirect']['path'] ?? $path;
    $savedTarget = $rJson['redirect']['target'] ?? $target;

    if ($db) {
        $stmt = $db->prepare("
            INSERT INTO shopify_redirects (store_key, shopify_redirect_id, `path`, `target`, last_synced_at, last_pushed_at, updated_by)
            VALUES (:store, :rid, :path, :target, NOW(), NOW(), :user)
            ON DUPLICATE KEY UPDATE `path` = VALUES(`path`), `target` = VALUES(`target`),
                last_synced_at = NOW(), last_pushed_at = NOW(), updated_by = VALUES(updated_by)
        ");
        $stmt->execute([
            ':store'  => $activeStore,
            ':rid'    => $shopifyId,
            ':path'   => $savedPath,
            ':target' => $savedTarget,
            ':user'   => $currentUser,
        ]);
    }

    recordUserLog('redirect_created', 'redirects', "Created {$savedPath} → {$savedTarget}", 'redirect', (string)$shopifyId, 'success');

    return [
        'success'    => true,
        'message'    => "Redirect created: {$savedPath} → {$savedTarget}",
        'shopify_id' => $shopifyId,
    ];
}

// -----------------------------------------------------------------------------
// AUTO-CREATE TABLE
// -----------------------------------------------------------------------------
if ($db) {
    try {
        $db->exec("
            CREATE TABLE IF NOT EXISTS `shopify_redirects` (
                `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                `store_key` VARCHAR(50) NOT NULL DEFAULT 'business',
                `shopify_redirect_id` BIGINT UNSIGNED NOT NULL,
                `path` VARCHAR(1000) NOT NULL,
                `target` VARCHAR(1000) NOT NULL,
                `last_synced_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `last_pushed_at` DATETIME NULL DEFAULT NULL,
                `updated_by` VARCHAR(100) NULL DEFAULT NULL,
                `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uq_store_redirect` (`store_key`, `shopify_redirect_id`),
                KEY `idx_redirects_store` (`store_key`),
                KEY `idx_redirects_path` (`path`(191))
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ");
    } catch (PDOException $e) {
        // silent – page must still render
    }
}

// -----------------------------------------------------------------------------
// ACTION HANDLERS
// -----------------------------------------------------------------------------

// A. TEST CONNECTION (shop + redirects pull + count)
if (isset($_POST['action']) && $_POST['action'] === 'test_connection') {
    try {
        $storesToTest = ['retail', 'business'];
        $results = [];
        $allSuccess = true;

        foreach ($storesToTest as $storeKey) {
            $storeCfg  = $shopConfig[$storeKey] ?? [];
            $targetUrl = getShopifyAdminDomain($storeCfg, $storeKey);
            $version   = !empty($storeCfg['version']) ? $storeCfg['version'] : '2025-10';
            $token     = $storeCfg['access_token'] ?? '';

            $storeResults = [];
            $storeSuccess = true;

            if (empty($token)) {
                $storeResults[] = "❌ Access Token: MISSING - No access token found for {$storeKey} store";
                $storeResults[] = "❌ Pull Redirects: NOT TESTED - No access token";
                $results[$storeKey] = implode('<br>', $storeResults);
                $allSuccess = false;
                continue;
            }

            if (empty($targetUrl)) {
                $storeResults[] = "❌ Store Configuration: MISSING - No URL configured for {$storeKey} store";
                $storeResults[] = "❌ Pull Redirects: NOT TESTED - No store URL";
                $results[$storeKey] = implode('<br>', $storeResults);
                $allSuccess = false;
                continue;
            }

            $headers = [
                "X-Shopify-Access-Token: {$token}",
                "Content-Type: application/json"
            ];

            // Test 1: Basic connection
            $ch = curl_init("https://{$targetUrl}/admin/api/{$version}/shop.json");
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER     => $headers,
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_TIMEOUT        => 15,
                CURLOPT_HEADER         => true,
            ]);
            $response   = curl_exec($ch);
            $httpCode   = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
            $curlError  = curl_error($ch);
            curl_close($ch);

            $bodyStr = is_string($response) ? substr($response, (int)$headerSize) : '';
            $json    = json_decode($bodyStr, true);

            if ($httpCode === 200 && !empty($json['shop'])) {
                $shopName   = $json['shop']['name'] ?? 'Unknown';
                $shopDomain = $json['shop']['myshopify_domain'] ?? $json['shop']['domain'] ?? $targetUrl;
                $storeResults[] = "✅ Basic Connection: SUCCESS - Store: <strong>{$shopName}</strong> ({$shopDomain}) | API Version: {$version}";
            } else {
                $errorDetails = $json['errors'] ?? substr($bodyStr, 0, 400);
                $msg = "❌ Basic Connection: FAILED! HTTP {$httpCode}";
                if ($curlError) {
                    $msg .= " | cURL: " . htmlspecialchars($curlError);
                }
                $msg .= "<br><small>" . htmlspecialchars(is_string($errorDetails) ? $errorDetails : json_encode($errorDetails)) . "</small>";
                $storeResults[] = $msg;
                $storeSuccess = false;
            }

            // Test 2: Pull redirects
            $ch = curl_init("https://{$targetUrl}/admin/api/{$version}/redirects.json?limit=1");
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER     => $headers,
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_TIMEOUT        => 15,
                CURLOPT_HEADER         => true,
            ]);
            $response   = curl_exec($ch);
            $httpCode   = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
            $curlError  = curl_error($ch);
            curl_close($ch);

            $bodyStr = is_string($response) ? substr($response, (int)$headerSize) : '';
            $rJson   = json_decode($bodyStr, true);

            if ($httpCode === 200 && isset($rJson['redirects']) && is_array($rJson['redirects'])) {
                $storeResults[] = "✅ Pull Redirects: SUCCESS - Endpoint reachable (sample: " . count($rJson['redirects']) . " record(s))";
            } else {
                $errorDetails = $rJson['errors'] ?? substr($bodyStr, 0, 400);
                $msg = "❌ Pull Redirects: FAILED! HTTP {$httpCode}";
                if ($curlError) {
                    $msg .= " | cURL: " . htmlspecialchars($curlError);
                }
                if (!empty($errorDetails)) {
                    $msg .= "<br><small>" . htmlspecialchars(is_string($errorDetails) ? $errorDetails : json_encode($errorDetails)) . "</small>";
                }
                $storeResults[] = $msg;
                $storeSuccess = false;
            }

            // Test 3: Count endpoint
            $ch = curl_init("https://{$targetUrl}/admin/api/{$version}/redirects/count.json");
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER     => $headers,
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_TIMEOUT        => 15,
            ]);
            $response  = curl_exec($ch);
            $httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            $cJson = json_decode(is_string($response) ? $response : '', true);

            if ($httpCode === 200 && isset($cJson['count'])) {
                $storeResults[] = "✅ Count Redirects: SUCCESS - {$cJson['count']} redirect(s) on Shopify";
            } else {
                $storeResults[] = "⚠️ Count Redirects: unavailable (HTTP {$httpCode})";
            }

            $results[$storeKey] = implode('<br>', $storeResults);
            if (!$storeSuccess) {
                $allSuccess = false;
            }
        }

        $message = implode('<br><br>', $results);
        $message = ($allSuccess
            ? "✅ All connections and API capabilities verified successfully!<br><br>"
            : "⚠️ Some API capabilities failed!<br><br>") . $message;
        recordUserLog('Test Connection', 'Redirects API', "Tested Shopify API connection (url redirects) for retail + business stores — " . ($allSuccess ? 'all checks passed.' : 'some checks failed.'), 'redirect', null, $allSuccess ? 'success' : 'error');
    } catch (Throwable $e) {
        $message = "ERROR: Test connection failed – " . htmlspecialchars($e->getMessage());
    }
}

// B. SYNC REDIRECTS FROM SHOPIFY (fetch ALL via Link pagination)
if (isset($_POST['action']) && $_POST['action'] === 'sync_redirects') {
    try {
        $syncedCount      = 0;
        $shopifyRedirects = [];
        $apiError         = null;
        $successfulDomain = null;

        $primaryUrl  = getShopifyAdminDomain($shopCfg, $activeStore);
        $fallbackUrl = $shopCfg['fallback_url'] ?? null;
        $version     = !empty($shopCfg['version']) ? $shopCfg['version'] : '2025-10';
        $token       = $shopCfg['access_token'] ?? '';

        if (empty($token)) {
            throw new Exception('Access token is empty for the active store. Check your settings table.');
        }

        $domainsToTry = array_unique(array_filter([$primaryUrl, $fallbackUrl]));

        recordUserLog('sync_started', 'redirects', "Starting redirects sync for store: {$activeStore}", 'redirect', null, 'info');

        foreach ($domainsToTry as $domain) {
            $nextUrl          = "https://{$domain}/admin/api/{$version}/redirects.json?limit=250";
            $headers          = [
                "X-Shopify-Access-Token: {$token}",
                "Content-Type: application/json"
            ];
            $pageLimit        = 40;
            $currentPageCount = 0;
            $tempRedirects    = [];
            $gotValidResponse = false;

            while (!empty($nextUrl) && $currentPageCount < $pageLimit) {
                $currentPageCount++;

                $ch = curl_init($nextUrl);
                if ($ch === false) {
                    throw new Exception('Failed to initialize cURL');
                }
                curl_setopt_array($ch, [
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_HTTPHEADER     => $headers,
                    CURLOPT_HEADER         => true,
                    CURLOPT_SSL_VERIFYPEER => false,
                    CURLOPT_TIMEOUT        => 25,
                ]);

                $response   = curl_exec($ch);
                $httpCode   = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
                $curlError  = curl_error($ch);
                curl_close($ch);

                if ($httpCode === 200 && $response !== false) {
                    $gotValidResponse = true;
                    $headersStr = substr($response, 0, (int)$headerSize);
                    $bodyStr    = substr($response, (int)$headerSize);
                    $json       = json_decode($bodyStr, true);

                    if (!empty($json['redirects']) && is_array($json['redirects'])) {
                        $tempRedirects = array_merge($tempRedirects, $json['redirects']);
                    }

                    $nextUrl = '';
                    if (preg_match('/<([^>]+)>;\s*rel=["\']next["\']/i', $headersStr, $match)) {
                        $nextUrl = $match[1];
                    }
                } else {
                    $bodyStr   = is_string($response) ? substr($response, (int)$headerSize) : '';
                    $errorData = json_decode($bodyStr, true);
                    $apiError  = "HTTP {$httpCode} on {$domain}";
                    if ($curlError) {
                        $apiError .= " | cURL: {$curlError}";
                    }
                    if (!empty($errorData['errors'])) {
                        $apiError .= " | " . json_encode($errorData['errors']);
                    } elseif ($bodyStr) {
                        $apiError .= " | " . substr($bodyStr, 0, 300);
                    }
                    break;
                }
            }

            if ($gotValidResponse) {
                $shopifyRedirects = $tempRedirects;
                $successfulDomain = $domain;
                break;
            }
        }

        if ($db && $successfulDomain !== null) {
            $insertStmt = $db->prepare("
                INSERT INTO shopify_redirects (
                    store_key, shopify_redirect_id, `path`, `target`, last_synced_at
                ) VALUES (
                    :store, :rid, :path, :target, NOW()
                )
                ON DUPLICATE KEY UPDATE
                    `path`           = VALUES(`path`),
                    `target`         = VALUES(`target`),
                    last_synced_at   = NOW()
            ");

            $seenIds = [];
            foreach ($shopifyRedirects as $r) {
                $rid = (int)($r['id'] ?? 0);
                if ($rid <= 0) {
                    continue;
                }
                $path   = (string)($r['path'] ?? '');
                $target = (string)($r['target'] ?? '');
                if ($path === '' || $target === '') {
                    continue;
                }
                $insertStmt->execute([
                    ':store'  => $activeStore,
                    ':rid'    => $rid,
                    ':path'   => $path,
                    ':target' => $target,
                ]);
                $seenIds[] = $rid;
                $syncedCount++;
            }

            // Remove stale local rows deleted on Shopify (keeps list accurate per store)
            if (!empty($seenIds)) {
                $placeholders = implode(',', array_fill(0, count($seenIds), '?'));
                $delStmt = $db->prepare(
                    "DELETE FROM shopify_redirects WHERE store_key = ? AND shopify_redirect_id NOT IN ({$placeholders})"
                );
                $delStmt->execute(array_merge([$activeStore], $seenIds));
            } else {
                // Shopify returned zero redirects – clear local cache for this store
                $delStmt = $db->prepare("DELETE FROM shopify_redirects WHERE store_key = :store");
                $delStmt->execute([':store' => $activeStore]);
            }

            $message = "✅ Successfully synchronized <strong>{$syncedCount}</strong> URL redirect(s) from <strong>{$successfulDomain}</strong> ({$shopCfg['name']}).";
            recordUserLog('sync_success', 'redirects', "Synced {$syncedCount} redirects from {$successfulDomain}", 'redirect', null, 'success');
        } else {
            if ($apiError) {
                $message = "ERROR: Shopify API failed for {$shopCfg['name']}. {$apiError}";
            } else {
                $message = "ERROR: No redirects were returned from the Shopify API for {$shopCfg['name']}. Please check your API credentials and store configuration.";
            }
            recordUserLog('sync_error', 'redirects', $message, 'redirect', null, 'error');
        }
    } catch (Throwable $e) {
        $message = "ERROR: Sync crashed – " . htmlspecialchars($e->getMessage()) .
                   " (file: " . basename($e->getFile()) . " line " . $e->getLine() . ")";
        recordUserLog('sync_crash', 'redirects', $message, 'redirect', null, 'error');
    }
}

// C. CREATE REDIRECT (uses createShopifyRedirect() helper)
if (isset($_POST['action']) && $_POST['action'] === 'create_redirect') {
    try {
        $result  = createShopifyRedirect(
            $db,
            $shopCfg,
            $activeStore,
            $currentUser,
            (string)($_POST['path'] ?? ''),
            (string)($_POST['target'] ?? '')
        );
        $message = "✅ " . htmlspecialchars($result['message']);
    } catch (Throwable $e) {
        $message = "ERROR: Create failed – " . htmlspecialchars($e->getMessage());
        recordUserLog('redirect_create_failed', 'redirects', $message, 'redirect', null, 'error');
    }
}

// D. UPDATE REDIRECT (Shopify PUT + local update)
if (isset($_POST['action']) && $_POST['action'] === 'update_redirect') {
    try {
        $localId = (int)($_POST['redirect_id'] ?? 0);
        $path    = normaliseRedirectPath((string)($_POST['path'] ?? ''));
        $target  = trim((string)($_POST['target'] ?? ''));

        if ($localId <= 0) {
            throw new Exception('Missing redirect ID.');
        }
        if ($path === '' || $target === '') {
            throw new Exception('Both "From URL" (path) and "To URL" (target) are required.');
        }
        if (!isValidRedirectTarget($target)) {
            throw new Exception('Invalid target. Use a path starting with "/" or a full https:// URL.');
        }

        if ($db) {
            $stmt = $db->prepare("SELECT * FROM shopify_redirects WHERE id = :id AND store_key = :store LIMIT 1");
            $stmt->execute([':id' => $localId, ':store' => $activeStore]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$row) {
                throw new Exception('Redirect not found for the active store.');
            }

            $shopifyRid  = (int)$row['shopify_redirect_id'];
            $adminDomain = getShopifyAdminDomain($shopCfg, $activeStore);
            $version     = !empty($shopCfg['version']) ? $shopCfg['version'] : '2025-10';
            $putUrl      = "https://{$adminDomain}/admin/api/{$version}/redirects/{$shopifyRid}.json";
            $payload     = json_encode(['redirect' => ['id' => $shopifyRid, 'path' => $path, 'target' => $target]]);

            $ch = curl_init($putUrl);
            curl_setopt_array($ch, [
                CURLOPT_CUSTOMREQUEST  => 'PUT',
                CURLOPT_POSTFIELDS     => $payload,
                CURLOPT_HTTPHEADER     => [
                    "X-Shopify-Access-Token: " . ($shopCfg['access_token'] ?? ''),
                    "Content-Type: application/json"
                ],
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_TIMEOUT        => 15,
            ]);
            $res      = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($httpCode >= 200 && $httpCode < 300) {
                $upStmt = $db->prepare("
                    UPDATE shopify_redirects
                    SET `path` = :path, `target` = :target, last_pushed_at = NOW(), updated_by = :user
                    WHERE id = :id AND store_key = :store
                ");
                $upStmt->execute([
                    ':path'  => $path,
                    ':target'=> $target,
                    ':user'  => $currentUser,
                    ':id'    => $localId,
                    ':store' => $activeStore,
                ]);
                $message = "✅ Redirect updated: <strong>" . htmlspecialchars($path) . "</strong> → <strong>" . htmlspecialchars($target) . "</strong>";
                recordUserLog('redirect_updated', 'redirects', "Updated {$path} → {$target}", 'redirect', (string)$shopifyRid, 'success');
            } else {
                $rJson = json_decode(is_string($res) ? $res : '', true);
                $errDetail = $rJson['errors'] ?? substr(is_string($res) ? $res : '', 0, 300);
                throw new Exception("Shopify API returned HTTP {$httpCode}. " . (is_string($errDetail) ? $errDetail : json_encode($errDetail)));
            }
        }
    } catch (Throwable $e) {
        $message = "ERROR: Update failed – " . htmlspecialchars($e->getMessage());
        recordUserLog('redirect_update_failed', 'redirects', "Redirect update failed: " . $e->getMessage(), 'redirect', null, 'error');
    }
}

// E. DELETE REDIRECT (Shopify DELETE + local delete)
if (isset($_POST['action']) && $_POST['action'] === 'delete_redirect') {
    try {
        $localId = (int)($_POST['redirect_id'] ?? 0);
        if ($localId <= 0) {
            throw new Exception('Missing redirect ID.');
        }

        if ($db) {
            $stmt = $db->prepare("SELECT * FROM shopify_redirects WHERE id = :id AND store_key = :store LIMIT 1");
            $stmt->execute([':id' => $localId, ':store' => $activeStore]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$row) {
                throw new Exception('Redirect not found for the active store.');
            }

            $shopifyRid  = (int)$row['shopify_redirect_id'];
            $adminDomain = getShopifyAdminDomain($shopCfg, $activeStore);
            $version     = !empty($shopCfg['version']) ? $shopCfg['version'] : '2025-10';
            $delUrl      = "https://{$adminDomain}/admin/api/{$version}/redirects/{$shopifyRid}.json";

            $ch = curl_init($delUrl);
            curl_setopt_array($ch, [
                CURLOPT_CUSTOMREQUEST  => 'DELETE',
                CURLOPT_HTTPHEADER     => [
                    "X-Shopify-Access-Token: " . ($shopCfg['access_token'] ?? ''),
                    "Content-Type: application/json"
                ],
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_TIMEOUT        => 15,
            ]);
            $res      = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($httpCode >= 200 && $httpCode < 300) {
                $delStmt = $db->prepare("DELETE FROM shopify_redirects WHERE id = :id AND store_key = :store");
                $delStmt->execute([':id' => $localId, ':store' => $activeStore]);
                $message = "✅ Redirect deleted: <strong>" . htmlspecialchars($row['path'] ?? '') . "</strong>";
                recordUserLog('redirect_deleted', 'redirects', "Deleted " . ($row['path'] ?? $localId), 'redirect', (string)$shopifyRid, 'success');
            } else {
                throw new Exception("Shopify API returned HTTP {$httpCode}. Please verify the redirect still exists.");
            }
        }
    } catch (Throwable $e) {
        $message = "ERROR: Delete failed – " . htmlspecialchars($e->getMessage());
        recordUserLog('redirect_delete_failed', 'redirects', "Redirect delete failed: " . $e->getMessage(), 'redirect', null, 'error');
    }
}

// -----------------------------------------------------------------------------
// PAGINATION & QUERY (20 per page, search path / target)
// -----------------------------------------------------------------------------
$itemsPerPage = 20;
$currentPage  = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$offset       = ($currentPage - 1) * $itemsPerPage;
$searchQuery  = trim($_GET['search'] ?? '');
$storeKey     = $activeStore;

$whereClauses = ["store_key = :store"];
$params       = [':store' => $activeStore];

if (!empty($searchQuery)) {
    $whereClauses[]    = "(`path` LIKE :search OR `target` LIKE :search)";
    $params[':search'] = '%' . $searchQuery . '%';
}

$whereSql = implode(' AND ', $whereClauses);

$totalRedirects = 0;
$lastSyncedAt   = null;

if ($db) {
    try {
        $countStmt = $db->prepare("SELECT COUNT(*) FROM shopify_redirects WHERE {$whereSql}");
        $countStmt->execute($params);
        $totalRedirects = (int)$countStmt->fetchColumn();

        $sStmt = $db->prepare("SELECT MAX(last_synced_at) FROM shopify_redirects WHERE store_key = :store");
        $sStmt->execute([':store' => $activeStore]);
        $lastSyncedAt = $sStmt->fetchColumn();
    } catch (Throwable $e) {
        // silent – render empty state
    }
}

$totalPages = max(1, (int)ceil($totalRedirects / $itemsPerPage));
if ($currentPage > $totalPages) {
    $currentPage = $totalPages;
    $offset = ($currentPage - 1) * $itemsPerPage;
}

$redirectsList = [];
if ($db) {
    try {
        $querySql = "SELECT * FROM shopify_redirects WHERE {$whereSql} ORDER BY id ASC LIMIT {$itemsPerPage} OFFSET {$offset}";
        $stmt     = $db->prepare($querySql);
        $stmt->execute($params);
        $redirectsList = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        $redirectsList = [];
    }
}

$storeDomain = $shopCfg['domain'] ?? getShopifyAdminDomain($shopCfg, $activeStore);
$apiVersion  = $shopCfg['version'] ?? '2025-10';

$pageTitle = 'URL Redirects';
include __DIR__ . '/../includes/header.php';
include __DIR__ . '/../includes/sidebar.php';
?>

<div class="content-wrapper">
  <div class="content-header">
    <div class="container-fluid">

      <?php if (!empty($message)): ?>
        <div class="alert <?php
          echo (strpos($message, 'ERROR:') === 0 || strpos($message, '❌') !== false)
            ? 'alert-danger'
            : (strpos($message, '✅') !== false ? 'alert-success' : 'alert-info');
        ?> alert-dismissible fade show shadow-sm" role="alert">
          <?php echo $message; ?>
          <button type="button" class="close" data-dismiss="alert" aria-label="Close">
            <span aria-hidden="true">&times;</span>
          </button>
        </div>
      <?php endif; ?>

      <div class="row mb-2 align-items-center">
        <div class="col-sm-6">
          <h1 class="m-0 font-weight-bold" style="color: #003087;">URL Redirects</h1>
          <p class="text-muted small mb-0">
            All URL redirects on the active store: <strong><?php echo htmlspecialchars($shopCfg['name'] ?? $activeStore); ?></strong>
          </p>
        </div>

        <div class="col-sm-6 text-right d-flex justify-content-end align-items-center gap-2 flex-wrap">
          <form method="POST" class="d-inline mr-2">
            <input type="hidden" name="action" value="test_connection">
            <button type="submit" class="btn font-weight-bold text-white shadow-sm" style="background-color: #007bff;">
              <i class="fas fa-plug mr-1"></i> Test Connection
            </button>
          </form>

          <form method="POST" class="d-inline mr-2" id="syncForm">
            <input type="hidden" name="action" value="sync_redirects">
            <button type="submit" id="btnSyncRedirects" class="btn font-weight-bold shadow-sm" style="background-color: #FFCC00; color: #1f2937; border: 1px solid #eab308;">
              <i class="fas fa-sync-alt mr-1" id="syncIcon"></i> Sync Redirects
            </button>
          </form>

          <button type="button" class="btn font-weight-bold text-white shadow-sm" style="background-color: #16a34a;" data-toggle="modal" data-target="#addRedirectModal">
            <i class="fas fa-plus mr-1"></i> Add Redirect
          </button>
        </div>
      </div>
    </div>
  </div>

  <section class="content">
    <div class="container-fluid">

      <!-- KPI Cards -->
      <div class="row mb-3">
        <div class="col-6 col-md-3">
          <div class="card p-3 shadow-xs border-0 rounded-lg">
            <span class="text-muted small font-weight-bold text-uppercase" style="font-size: 11px;">Total Redirects</span>
            <h3 class="font-weight-bold mb-0 text-dark mt-1"><?php echo $totalRedirects; ?></h3>
          </div>
        </div>
        <div class="col-6 col-md-3">
          <div class="card p-3 shadow-xs border-0 rounded-lg">
            <span class="text-muted small font-weight-bold text-uppercase" style="font-size: 11px;">Active Store</span>
            <h6 class="font-weight-bold mb-0 text-primary mt-2 text-truncate" title="<?php echo htmlspecialchars($shopCfg['name'] ?? $activeStore); ?>">
              <?php echo htmlspecialchars($shopCfg['name'] ?? $activeStore); ?>
            </h6>
          </div>
        </div>
        <div class="col-6 col-md-3">
          <div class="card p-3 shadow-xs border-0 rounded-lg">
            <span class="text-muted small font-weight-bold text-uppercase" style="font-size: 11px;">API Version</span>
            <h6 class="font-weight-bold mb-0 text-dark mt-2"><?php echo htmlspecialchars($apiVersion); ?></h6>
          </div>
        </div>
        <div class="col-6 col-md-3">
          <div class="card p-3 shadow-xs border-0 rounded-lg">
            <span class="text-muted small font-weight-bold text-uppercase" style="font-size: 11px;">Last Synced</span>
            <h6 class="font-weight-bold mb-0 text-info mt-2"><?php echo $lastSyncedAt ? htmlspecialchars($lastSyncedAt) : 'Never'; ?></h6>
          </div>
        </div>
      </div>

      <!-- Add New Redirect (inline form — same createShopifyRedirect() backend as the modal) -->
      <div class="card p-3 mb-4 shadow-sm border-0" style="border-radius: 12px; border-top: 4px solid #16a34a !important;">
        <form method="POST" action="redirect.php" class="row align-items-end">
          <input type="hidden" name="action" value="create_redirect">
          <div class="col-md-12 mb-2">
            <h5 class="font-weight-bold mb-0 text-dark"><i class="fas fa-plus-circle text-success mr-2"></i>Add New URL Redirect</h5>
            <small class="text-muted">Creates the redirect live on <strong><?php echo htmlspecialchars($shopCfg['name'] ?? $activeStore); ?></strong> via the Shopify API.</small>
          </div>
          <div class="col-md-5 mb-2 mb-md-0">
            <label class="font-weight-bold small text-secondary mb-1">From URL (old path)</label>
            <input type="text" name="path" class="form-control font-mono" placeholder="/old-page-url"
                   value="<?php echo isset($_POST['action'], $_POST['path']) && $_POST['action'] === 'create_redirect' ? htmlspecialchars((string)$_POST['path']) : ''; ?>" required>
            <small class="form-text text-muted">Must start with <code>/</code>.</small>
          </div>
          <div class="col-md-5 mb-2 mb-md-0">
            <label class="font-weight-bold small text-secondary mb-1">To URL (target)</label>
            <input type="text" name="target" class="form-control font-mono" placeholder="/new-page-url or https://..."
                   value="<?php echo isset($_POST['action'], $_POST['target']) && $_POST['action'] === 'create_redirect' ? htmlspecialchars((string)$_POST['target']) : ''; ?>" required>
            <small class="form-text text-muted">A <code>/path</code> or full <code>https://</code> URL.</small>
          </div>
          <div class="col-md-2">
            <button type="submit" class="btn btn-block font-weight-bold text-white" style="background-color: #16a34a;">
              <i class="fas fa-plus mr-1"></i> Add
            </button>
          </div>
        </form>
      </div>

      <!-- Search -->
      <div class="card p-3 mb-4 shadow-sm border-0" style="border-radius: 12px;">
        <form method="GET" action="redirect.php" class="row align-items-center">
          <input type="hidden" name="store" value="<?php echo htmlspecialchars($storeKey); ?>">
          <div class="col-md-9 mb-2 mb-md-0">
            <div class="input-group">
              <div class="input-group-prepend">
                <span class="input-group-text bg-white border-right-0"><i class="fas fa-search text-muted"></i></span>
              </div>
              <input type="text" name="search" class="form-control border-left-0"
                     placeholder="Search by from-URL (path) or to-URL (target)..."
                     value="<?php echo htmlspecialchars($searchQuery); ?>">
            </div>
          </div>
          <div class="col-md-3">
            <button type="submit" class="btn btn-block font-weight-bold text-white" style="background-color: #003087;">
              <i class="fas fa-search mr-1"></i> Search
            </button>
          </div>
        </form>
      </div>

      <!-- Pagination info -->
      <div class="d-flex justify-content-between align-items-center mb-3 text-muted small px-1">
        <div>
          Showing <strong><?php echo $totalRedirects > 0 ? $offset + 1 : 0; ?></strong> to
          <strong><?php echo min($offset + $itemsPerPage, $totalRedirects); ?></strong> of
          <strong><?php echo $totalRedirects; ?></strong> redirects (20 per page)
        </div>
        <div>Page <strong><?php echo $currentPage; ?></strong> of <strong><?php echo $totalPages; ?></strong></div>
      </div>

      <!-- Redirects Table -->
      <div class="card shadow-sm border-0" style="border-radius: 12px; overflow: hidden;">
        <div class="card-header bg-white border-bottom d-flex justify-content-between align-items-center py-3">
          <h5 class="font-weight-bold mb-0 text-dark">
            <i class="fas fa-exchange-alt text-primary mr-2"></i>Redirects on <?php echo htmlspecialchars($shopCfg['name'] ?? $activeStore); ?>
          </h5>
          <span class="badge badge-primary" style="font-size: 11px;"><?php echo htmlspecialchars($storeDomain); ?></span>
        </div>
        <div class="card-body p-0">
          <?php if (empty($redirectsList)): ?>
            <div class="text-center py-5 px-4">
              <i class="fas fa-exchange-alt fa-3x text-muted mb-3"></i>
              <h5 class="font-weight-bold text-secondary">No Redirects Found</h5>
              <p class="text-muted small mb-3">Click "Sync Redirects" to fetch the full list of URL redirects for <?php echo htmlspecialchars($shopCfg['name'] ?? $activeStore); ?>.</p>
              <form method="POST">
                <input type="hidden" name="action" value="sync_redirects">
                <button type="submit" class="btn font-weight-bold" style="background-color: #FFCC00; color: #1f2937;">
                  <i class="fas fa-sync-alt mr-1"></i> Sync Redirects Now
                </button>
              </form>
            </div>
          <?php else: ?>
            <div class="table-responsive">
              <table class="table table-hover table-striped mb-0">
                <thead class="thead-light">
                  <tr>
                    <th style="width: 32%;">From URL (path)</th>
                    <th style="width: 32%;">To URL (target)</th>
                    <th style="width: 12%;">Shopify ID</th>
                    <th style="width: 24%;" class="text-right">Actions</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($redirectsList as $r): ?>
                    <?php
                      $rId   = (int)$r['id'];
                      $rPath = $r['path'] ?? '';
                      $rTarg = $r['target'] ?? '';
                      $rSid  = $r['shopify_redirect_id'] ?? '';
                      $fromUrl = 'https://' . $storeDomain . $rPath;
                    ?>
                    <tr>
                      <td>
                        <form method="POST" action="redirect.php?page=<?php echo $currentPage; ?>&search=<?php echo urlencode($searchQuery); ?>" id="edit-form-<?php echo $rId; ?>">
                          <input type="hidden" name="action" value="update_redirect">
                          <input type="hidden" name="redirect_id" value="<?php echo $rId; ?>">
                          <div class="input-group input-group-sm">
                            <input type="text" name="path" class="form-control font-mono" value="<?php echo htmlspecialchars($rPath); ?>" required>
                          </div>
                          <a href="<?php echo htmlspecialchars($fromUrl); ?>" target="_blank" rel="noreferrer" class="small text-primary">
                            <i class="fas fa-external-link-alt mr-1"></i><?php echo htmlspecialchars($rPath); ?>
                          </a>
                      </td>
                      <td>
                          <div class="input-group input-group-sm">
                            <input type="text" name="target" class="form-control font-mono" value="<?php echo htmlspecialchars($rTarg); ?>" required>
                          </div>
                          <span class="small text-muted text-truncate d-block" style="max-width: 320px;" title="<?php echo htmlspecialchars($rTarg); ?>">
                            → <?php echo htmlspecialchars($rTarg); ?>
                          </span>
                      </td>
                      <td><span class="badge badge-light border text-muted">#<?php echo htmlspecialchars((string)$rSid); ?></span></td>
                      <td class="text-right text-nowrap">
                          <button type="submit" class="btn btn-sm btn-primary font-weight-bold" title="Save changes to Shopify">
                            <i class="fas fa-save mr-1"></i>Save
                          </button>
                        </form>
                        <form method="POST" action="redirect.php?page=<?php echo $currentPage; ?>&search=<?php echo urlencode($searchQuery); ?>" class="d-inline" onsubmit="return confirm('Delete redirect <?php echo htmlspecialchars($rPath, ENT_QUOTES); ?>?');">
                          <input type="hidden" name="action" value="delete_redirect">
                          <input type="hidden" name="redirect_id" value="<?php echo $rId; ?>">
                          <button type="submit" class="btn btn-sm btn-outline-danger font-weight-bold" title="Delete from Shopify">
                            <i class="fas fa-trash-alt"></i>
                          </button>
                        </form>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          <?php endif; ?>
        </div>
      </div>

      <!-- Pagination -->
      <?php if ($totalPages > 1): ?>
        <div class="card p-3 mt-4 mb-4 shadow-sm border-0" style="border-radius: 12px;">
          <div class="d-flex flex-column flex-lg-row justify-content-between align-items-center gap-3">
            <div class="small text-muted">
              Showing page <strong><?php echo $currentPage; ?></strong> of <strong><?php echo $totalPages; ?></strong>
              (<strong><?php echo $totalRedirects; ?></strong> total redirects)
            </div>
            <nav>
              <ul class="pagination pagination-sm m-0">
                <li class="page-item <?php echo $currentPage <= 1 ? 'disabled' : ''; ?>">
                  <a class="page-link" href="?store=<?php echo urlencode($storeKey); ?>&page=<?php echo max(1, $currentPage - 1); ?>&search=<?php echo urlencode($searchQuery); ?>">
                    Prev
                  </a>
                </li>
                <?php
                  $pageLinks = [];
                  if ($totalPages <= 7) {
                    for ($p = 1; $p <= $totalPages; $p++) $pageLinks[] = $p;
                  } elseif ($currentPage <= 3) {
                    $pageLinks = [1, 2, 3, 4, '...', $totalPages];
                  } elseif ($currentPage >= $totalPages - 2) {
                    $pageLinks = [1, '...', $totalPages - 3, $totalPages - 2, $totalPages - 1, $totalPages];
                  } else {
                    $pageLinks = [1, '...', $currentPage - 1, $currentPage, $currentPage + 1, '...', $totalPages];
                  }
                  foreach ($pageLinks as $pItem):
                    if ($pItem === '...'):
                ?>
                  <li class="page-item disabled"><span class="page-link">&hellip;</span></li>
                <?php else: ?>
                  <li class="page-item <?php echo $currentPage === $pItem ? 'active' : ''; ?>">
                    <a class="page-link" href="?store=<?php echo urlencode($storeKey); ?>&page=<?php echo $pItem; ?>&search=<?php echo urlencode($searchQuery); ?>"
                       style="<?php echo $currentPage === $pItem ? 'background-color:#003087;border-color:#003087;color:#fff;' : ''; ?>">
                      <?php echo $pItem; ?>
                    </a>
                  </li>
                <?php endif; endforeach; ?>
                <li class="page-item <?php echo $currentPage >= $totalPages ? 'disabled' : ''; ?>">
                  <a class="page-link" href="?store=<?php echo urlencode($storeKey); ?>&page=<?php echo min($totalPages, $currentPage + 1); ?>&search=<?php echo urlencode($searchQuery); ?>">
                    Next
                  </a>
                </li>
              </ul>
            </nav>
          </div>
        </div>
      <?php endif; ?>

    </div>
  </section>
</div>

<!-- Add Redirect Modal -->
<div class="modal fade" id="addRedirectModal" tabindex="-1" role="dialog" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered" role="document">
    <div class="modal-content" style="border-radius: 14px;">
      <form method="POST" action="redirect.php">
        <input type="hidden" name="action" value="create_redirect">
        <div class="modal-header border-bottom">
          <h5 class="modal-title font-weight-bold" style="color: #003087;">
            <i class="fas fa-plus-circle mr-1 text-success"></i> Add URL Redirect
          </h5>
          <button type="button" class="close" data-dismiss="modal" aria-label="Close">
            <span aria-hidden="true">&times;</span>
          </button>
        </div>
        <div class="modal-body">
          <div class="form-group">
            <label class="font-weight-bold small text-secondary">From URL (old path)</label>
            <div class="input-group">
              <div class="input-group-prepend">
                <span class="input-group-text bg-light text-muted" style="font-size: 12px;"><i class="fas fa-link"></i></span>
              </div>
              <input type="text" name="path" class="form-control font-mono" placeholder="/old-page-url" required>
            </div>
            <small class="form-text text-muted">Must start with <code>/</code>, e.g. <code>/old-mattress-page</code>.</small>
          </div>
          <div class="form-group mb-0">
            <label class="font-weight-bold small text-secondary">To URL (target)</label>
            <div class="input-group">
              <div class="input-group-prepend">
                <span class="input-group-text bg-light text-muted" style="font-size: 12px;"><i class="fas fa-arrow-right"></i></span>
              </div>
              <input type="text" name="target" class="form-control font-mono" placeholder="/new-page-url or https://..." required>
            </div>
            <small class="form-text text-muted">A store path starting with <code>/</code> or a full <code>https://</code> URL.</small>
          </div>
        </div>
        <div class="modal-footer border-top">
          <button type="button" class="btn btn-secondary btn-sm" data-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-sm font-weight-bold text-white" style="background-color: #16a34a;">
            <i class="fas fa-plus mr-1"></i> Create Redirect
          </button>
        </div>
      </form>
    </div>
  </div>
</div>

<script>
document.getElementById('syncForm')?.addEventListener('submit', function () {
  const icon = document.getElementById('syncIcon');
  const btn  = document.getElementById('btnSyncRedirects');
  if (icon && btn) {
    icon.classList.add('fa-spin');
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-sync-alt fa-spin mr-1"></i> Synchronizing...';
  }
});
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
