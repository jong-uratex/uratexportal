<?php
require_once __DIR__ . '/../config/config.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_logged_in'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized access.']);
    exit;
}

$db = getDbConnection();
if (!$db) {
    echo json_encode(['success' => false, 'message' => 'Database connection failed.']);
    exit;
}

try {
    // 1. Retrieve Client Credentials stored in settings table
    $stmt = $db->query("SELECT `handle`, `keys` FROM `settings` WHERE `handle` IN ('client_id', 'secret')");
    $credentials = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

    $clientId = $credentials['client_id'] ?? '';
    $clientSecret = $credentials['secret'] ?? '';

    if (empty($clientId) || empty($clientSecret)) {
        echo json_encode(['success' => false, 'message' => 'Shopify Client ID or Secret missing in database settings.']);
        exit;
    }

    $activeStoreKey = $_SESSION['active_store'] ?? 'retail';
    $storeDomain = $shopConfig[$activeStoreKey]['url'] ?? '';

    if (empty($storeDomain)) {
        echo json_encode(['success' => false, 'message' => 'Store URL not configured for target store.']);
        exit;
    }

    // 2. Request new access token from Shopify Admin OAuth API
    $tokenEndpoint = "https://{$storeDomain}/admin/oauth/access_token";
    $payload = [
        'client_id' => $clientId,
        'client_secret' => $clientSecret,
        'grant_type' => 'client_credentials' // Adjust if using code exchange: 'code' => $authCode
    ];

    $ch = curl_init($tokenEndpoint);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($payload));
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $responseData = json_decode($response, true);

    if ($httpCode === 200 && !empty($responseData['access_token'])) {
        $newToken = $responseData['access_token'];
        $settingHandle = ($activeStoreKey === 'business') ? 'business_access_token' : 'retail_access_token';

        // 3. Save new token directly into the MySQL database settings table
        $updateStmt = $db->prepare("UPDATE `settings` SET `keys` = :token, `updated_at` = NOW() WHERE `handle` = :handle");
        $updateStmt->execute([
            ':token' => $newToken,
            ':handle' => $settingHandle
        ]);

        // 4. Log the action into audit trail
        recordUserLog('Renew Token', 'Shopify API', "Successfully updated access token for {$activeStoreKey} store.");

        echo json_encode([
            'success' => true,
            'message' => "Access token for " . ucfirst($activeStoreKey) . " store renewed and saved successfully!"
        ]);
    } else {
        $errorMsg = $responseData['error_description'] ?? $responseData['error'] ?? "HTTP $httpCode response from Shopify.";
        
        recordUserLog('Renew Token Failed', 'Shopify API', "Failed renewing token: $errorMsg", 'system', null, 'failed');

        echo json_encode([
            'success' => false,
            'message' => "Failed to renew token from Shopify: $errorMsg"
        ]);
    }

} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => 'Exception occurred: ' . $e->getMessage()
    ]);
}