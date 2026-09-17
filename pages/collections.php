<?php
/**
 * Collections SEO Module (collections.php) - Uratex Shopify SEO Partner Portal
 *
 * Features:
 *  1. Syncs ALL collections (custom + smart) from Shopify REST API with cursor pagination
 *  2. Saves & persists collections in MySQL table `shopify_collections`
 *  3. Categorized strictly according to active store (retail / business)
 *  4. Editable fields: ONLY Collection SEO Title, Meta Description, and URL Handle
 *  5. 20 Collections Per Page Pagination
 *  6. Single & Bulk Save Drafts / Push to Shopify API
 *  7. Uses REAL Shopify publish status (published_at → published / draft)
 *  8. Live View button next to title
 *  9. No image fetching / display (collections have no images in Shopify)
 * 10. Defensive error handling – never dies with HTTP 500
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
    recordUserLog('Switch Store', 'Active Store', "Switched active store to '{$_GET['switch_store']}' from Collections module.", 'system', null, 'success');
}

$db          = getDbConnection();
$activeStore = $_SESSION['active_store'] ?? 'business';
$currentUser = $_SESSION['user_name'] ?? 'Jenor Ricafort';
$userRole    = $_SESSION['user_role'] ?? 'admin';
$shopCfg     = $shopConfig[$activeStore] ?? $shopConfig['business'];
$message     = '';
$messageType = 'success';

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
 * Map Shopify collection publish state to portal status.
 */
function mapCollectionStatus(?string $publishedAt): string
{
    return (!empty($publishedAt)) ? 'published' : 'draft';
}

/**
 * Minimal cURL wrapper for the Shopify Admin API.
 */
function shopifySeoApiRequest(string $method, string $url, string $token, ?array $payload = null): array
{
    $ch   = curl_init($url);
    $opts = [
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_HTTPHEADER     => [
            "X-Shopify-Access-Token: {$token}",
            "Content-Type: application/json"
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_TIMEOUT        => 15,
    ];
    if ($payload !== null) {
        $opts[CURLOPT_POSTFIELDS] = json_encode($payload);
    }
    curl_setopt_array($ch, $opts);
    $res  = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$code, $res];
}

/**
 * Create or update the "global" title_tag/description_tag metafield for a collection.
 *
 * NOTE: Shopify's top-level "metafields_global_title_tag" / "metafields_global_description_tag"
 * shorthand only WRITES a metafield the first time it is set. Once the metafield already
 * exists, sending that shorthand again on a collection PUT is silently ignored by the API
 * (it still returns 2xx), so the live <title>/meta description never changes after the
 * first push. We must look up the existing metafield and update it directly by ID.
 */
function upsertGlobalSeoMetafield(string $adminDomain, string $version, string $token, int $ownerId, string $key, string $type, string $value): array
{
    $listUrl = "https://{$adminDomain}/admin/api/{$version}/metafields.json"
             . "?metafield[owner_id]={$ownerId}&metafield[owner_resource]=collection"
             . "&namespace=global&key={$key}";
    [$code, $res] = shopifySeoApiRequest('GET', $listUrl, $token);

    $existingId = null;
    if ($code >= 200 && $code < 300) {
        $data = json_decode((string)$res, true);
        if (!empty($data['metafields'][0]['id'])) {
            $existingId = $data['metafields'][0]['id'];
        }
    }

    if ($existingId) {
        $putUrl = "https://{$adminDomain}/admin/api/{$version}/metafields/{$existingId}.json";
        return shopifySeoApiRequest('PUT', $putUrl, $token, [
            'metafield' => ['id' => $existingId, 'value' => $value, 'type' => $type]
        ]);
    }

    $postUrl = "https://{$adminDomain}/admin/api/{$version}/metafields.json";
    return shopifySeoApiRequest('POST', $postUrl, $token, [
        'metafield' => [
            'namespace'      => 'global',
            'key'            => $key,
            'value'          => $value,
            'type'           => $type,
            'owner_id'       => $ownerId,
            'owner_resource' => 'collection'
        ]
    ]);
}

/**
 * Fetch the live "global" title_tag/description_tag metafields for a collection —
 * these, not the plain collection title/body, drive the actual live <title> and
 * meta description. Sync must read them so the portal reflects what's really live.
 */
function fetchGlobalSeoMetafields(string $adminDomain, string $version, string $token, int $ownerId): array
{
    $listUrl = "https://{$adminDomain}/admin/api/{$version}/metafields.json"
             . "?metafield[owner_id]={$ownerId}&metafield[owner_resource]=collection&namespace=global";
    [$code, $res] = shopifySeoApiRequest('GET', $listUrl, $token);

    $result = ['title_tag' => null, 'description_tag' => null];
    if ($code >= 200 && $code < 300) {
        $data = json_decode((string)$res, true);
        foreach ($data['metafields'] ?? [] as $mf) {
            if (($mf['key'] ?? '') === 'title_tag') {
                $result['title_tag'] = $mf['value'] ?? null;
            } elseif (($mf['key'] ?? '') === 'description_tag') {
                $result['description_tag'] = $mf['value'] ?? null;
            }
        }
    }
    return $result;
}

/**
 * Push a single collection's SEO fields (title, handle, global title_tag/description_tag
 * metafields) to Shopify and persist the result locally. Used by both the individual
 * "Push to Shopify" action and the bulk push loop so both paths perform the same write.
 */
function pushCollectionSeoToShopify(PDO $db, array $shopCfg, string $activeStore, array $col, string $currentUser, ?string $title = null, ?string $metaDescription = null, ?string $handle = null): array
{
    $collectionId = (int)$col['id'];
    $shopifyCid   = $col['shopify_collection_id'];
    $colType      = $col['collection_type'] ?? 'custom';
    $adminDomain  = getShopifyAdminDomain($shopCfg, $activeStore);
    $version      = !empty($shopCfg['version']) ? $shopCfg['version'] : '2025-10';

    $endpoint   = ($colType === 'smart') ? 'smart_collections' : 'custom_collections';
    $putUrl     = "https://{$adminDomain}/admin/api/{$version}/{$endpoint}/{$shopifyCid}.json";
    $payloadKey = ($colType === 'smart') ? 'smart_collection' : 'custom_collection';
    $token      = $shopCfg['access_token'] ?? '';
    $finalTitle = $title ?: $col['title'];
    $finalMeta  = $metaDescription ?: $col['meta_description'];
    $finalHandle = $handle ?: $col['handle'];

    $payload = [
        $payloadKey => [
            "id"        => $shopifyCid,
            "title"     => $finalTitle,
            "handle"    => $finalHandle,
            "body_html" => $finalMeta
        ]
    ];

    [$httpCode] = shopifySeoApiRequest('PUT', $putUrl, $token, $payload);

    // Collection fields alone don't update the live <title>/meta description —
    // those live in the "global" title_tag/description_tag metafields, which must
    // be upserted directly (see upsertGlobalSeoMetafield doc comment).
    [$titleTagCode] = upsertGlobalSeoMetafield($adminDomain, $version, $token, (int)$shopifyCid, 'title_tag', 'single_line_text_field', $finalTitle);
    [$descTagCode]  = upsertGlobalSeoMetafield($adminDomain, $version, $token, (int)$shopifyCid, 'description_tag', 'single_line_text_field', $finalMeta);

    if (!($titleTagCode >= 200 && $titleTagCode < 300) || !($descTagCode >= 200 && $descTagCode < 300)) {
        $httpCode = max($httpCode, $titleTagCode, $descTagCode, 500);
    }

    $success = ($httpCode >= 200 && $httpCode < 300);

    // Only mark the record as published locally if Shopify actually accepted the write.
    $upStmt = $db->prepare("
        UPDATE shopify_collections
        SET title = :title,
            meta_description = :meta_desc,
            handle = :handle,
            status = :status,
            last_pushed_at = NOW(),
            updated_by = :user
        WHERE id = :id
    ");
    $upStmt->execute([
        ':title'     => $finalTitle,
        ':meta_desc' => $finalMeta,
        ':handle'    => $finalHandle,
        ':status'    => $success ? 'published' : 'needs_optimization',
        ':user'      => $currentUser,
        ':id'        => $collectionId
    ]);

    return [
        'success'   => $success,
        'http_code' => $httpCode,
        'title'     => $finalTitle,
        'id'        => $collectionId,
    ];
}

// -----------------------------------------------------------------------------
// AUTO-CREATE / MIGRATE TABLE
// -----------------------------------------------------------------------------
if ($db) {
    try {
        $db->exec("
            CREATE TABLE IF NOT EXISTS `shopify_collections` (
                `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                `store_key` VARCHAR(50) NOT NULL DEFAULT 'business',
                `shopify_collection_id` BIGINT UNSIGNED NOT NULL,
                `collection_type` VARCHAR(20) NOT NULL DEFAULT 'custom',
                `collection_title` VARCHAR(255) NOT NULL,
                `collection_url` VARCHAR(1000) NULL DEFAULT NULL,
                `title` VARCHAR(255) NOT NULL,
                `meta_description` TEXT NULL,
                `handle` VARCHAR(255) NOT NULL,
                `item_count` INT UNSIGNED DEFAULT 0,
                `status` ENUM('draft', 'published', 'needs_optimization', 'archived') NOT NULL DEFAULT 'draft',
                `seo_score` TINYINT UNSIGNED NOT NULL DEFAULT 85,
                `last_synced_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `last_pushed_at` DATETIME NULL DEFAULT NULL,
                `updated_by` VARCHAR(100) NULL DEFAULT NULL,
                `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uq_store_collection` (`store_key`, `shopify_collection_id`),
                KEY `idx_collections_store_status` (`store_key`, `status`),
                KEY `idx_collections_handle` (`handle`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ");

        // Migrate older tables that are missing collection_type
        $cols = $db->query("SHOW COLUMNS FROM `shopify_collections` LIKE 'collection_type'")->fetchAll();
        if (empty($cols)) {
            $db->exec("ALTER TABLE `shopify_collections` ADD COLUMN `collection_type` VARCHAR(20) NOT NULL DEFAULT 'custom' AFTER `shopify_collection_id`");
        }
    } catch (PDOException $e) {
        // keep going
    }
}

// -----------------------------------------------------------------------------
// ACTION HANDLERS
// -----------------------------------------------------------------------------

// A. EXPORT / IMPORT COLLECTION SEO DATA
if (isset($_POST['action']) && $_POST['action'] === 'export_collections') {
  if (!$db) {
    exit('Database connection unavailable.');
  }

  $exportStmt = $db->prepare('SELECT title, meta_description, handle FROM shopify_collections WHERE store_key = :store ORDER BY id ASC');
  $exportStmt->execute([':store' => $activeStore]);

  $filename = 'uratex_collections_' . $activeStore . '_' . date('Y-m-d_His') . '.csv';
  header('Content-Type: text/csv; charset=utf-8');
  header('Content-Disposition: attachment; filename="' . $filename . '"');
  $output = fopen('php://output', 'w');
  fputcsv($output, ['Collection SEO Title', 'Meta Description', 'URL Handle']);

  while ($collection = $exportStmt->fetch(PDO::FETCH_ASSOC)) {
    fputcsv($output, $collection);
  }

  fclose($output);
  exit;
}

if (isset($_POST['action']) && $_POST['action'] === 'import_collections') {
  $importedCount = 0;
  $skippedCount = 0;

  if (!$db || empty($_FILES['collections_csv']['tmp_name']) || $_FILES['collections_csv']['error'] !== UPLOAD_ERR_OK) {
    $message = 'ERROR: Please choose a valid collection CSV file to import.';
  } else {
    $handle = fopen($_FILES['collections_csv']['tmp_name'], 'r');
    $headers = $handle ? fgetcsv($handle) : false;
    $headerMap = $headers ? array_flip(array_map('trim', $headers)) : [];
    $requiredColumns = ['Collection SEO Title', 'Meta Description', 'URL Handle'];

    if (!$handle || !is_array($headers) || count($headers) !== count($requiredColumns) || count(array_intersect($requiredColumns, array_keys($headerMap))) !== count($requiredColumns)) {
      $message = 'ERROR: The CSV must include only Collection SEO Title, Meta Description, and URL Handle columns.';
      if ($handle) {
        fclose($handle);
      }
    } else {
      $updateStmt = $db->prepare("UPDATE shopify_collections SET title = :title, meta_description = :meta_description, status = 'draft', updated_by = :user WHERE store_key = :store AND handle = :handle");

      try {
        $db->beginTransaction();
        while (($row = fgetcsv($handle)) !== false) {
          $title = trim($row[$headerMap['Collection SEO Title']] ?? '');
          $metaDescription = trim($row[$headerMap['Meta Description']] ?? '');
          $urlHandle = trim($row[$headerMap['URL Handle']] ?? '');

          if ($title === '' || $urlHandle === '') {
            $skippedCount++;
            continue;
          }

          $updateStmt->execute([
            ':title' => $title,
            ':meta_description' => $metaDescription,
            ':store' => $activeStore,
            ':handle' => $urlHandle,
            ':user' => $currentUser,
          ]);
          if ($updateStmt->rowCount() === 1) {
            $importedCount++;
          } else {
            $skippedCount++;
          }
        }
        $db->commit();
        fclose($handle);
        $message = "Imported <strong>{$importedCount}</strong> collection(s) into the {$shopCfg['name']} database. No new collections were added.";
        if ($skippedCount > 0) {
          $message .= " {$skippedCount} row(s) were skipped because required values were missing or no collection matched the URL handle.";
        }
        recordUserLog('Collection Import', 'Collections', "Updated {$importedCount} existing collection SEO row(s) for {$shopCfg['name']}; skipped {$skippedCount}; no records added.", 'collection', null, 'success');
      } catch (Throwable $e) {
        if ($db->inTransaction()) {
          $db->rollBack();
        }
        fclose($handle);
        $message = 'ERROR: Collection import failed. No database records were changed.';
      }
    }
  }
}

// B. TEST CONNECTION
if (isset($_POST['action']) && $_POST['action'] === 'test_connection') {
    try {
        $storesToTest = ['retail', 'business'];
        $results = [];
        $allSuccess = true;
        
        foreach ($storesToTest as $storeKey) {
            $storeCfg = $shopConfig[$storeKey] ?? [];
            $targetUrl = getShopifyAdminDomain($storeCfg, $storeKey);
            $version   = !empty($storeCfg['version']) ? $storeCfg['version'] : '2025-10';
            $token     = $storeCfg['access_token'] ?? '';
            
            $storeResults = [];
            $storeSuccess = true;

            // Check if access token is available
            if (empty($token)) {
                $storeResults[] = "❌ Access Token: MISSING - No access token found for {$storeKey} store";
                $storeSuccess = false;
                $storeResults[] = "❌ Pull Collections: NOT TESTED - No access token";
                $storeResults[] = "❌ Push Collections: NOT TESTED - No access token";
                $results[$storeKey] = implode('<br>', $storeResults);
                $allSuccess = false;
                continue;
            }

            // Check if target URL is available
            if (empty($targetUrl)) {
                $storeResults[] = "❌ Store Configuration: MISSING - No URL configured for {$storeKey} store";
                $storeSuccess = false;
                $storeResults[] = "❌ Pull Collections: NOT TESTED - No store URL";
                $storeResults[] = "❌ Push Collections: NOT TESTED - No store URL";
                $results[$storeKey] = implode('<br>', $storeResults);
                $allSuccess = false;
                continue;
            }

            // Test 1: Basic connection test
            $testUrl = "https://{$targetUrl}/admin/api/{$version}/shop.json";
            
            $ch = curl_init($testUrl);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER     => [
                    "X-Shopify-Access-Token: {$token}",
                    "Content-Type: application/json"
                ],
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_TIMEOUT        => 15,
                CURLOPT_HEADER         => true,
            ]);

            $response   = curl_exec($ch);
            $httpCode   = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
            $curlError  = curl_error($ch);
            curl_close($ch);

            $bodyStr = substr($response, $headerSize);
            $json    = json_decode($bodyStr, true);

            if ($httpCode === 200 && !empty($json['shop'])) {
                $shopName   = $json['shop']['name'] ?? 'Unknown';
                $shopDomain = $json['shop']['myshopify_domain'] ?? $json['shop']['domain'] ?? $targetUrl;
                $storeResults[] = "✅ Basic Connection: SUCCESS - Store: <strong>{$shopName}</strong> ({$shopDomain}) | API Version: {$version}";
            } else {
                $errorDetails = $json['errors'] ?? substr($bodyStr, 0, 400);
                $errorMsg = "❌ Basic Connection: FAILED! HTTP {$httpCode}";
                if ($curlError) {
                    $errorMsg .= " | cURL: " . htmlspecialchars($curlError);
                }
                $errorMsg .= "<br><small>" . htmlspecialchars(is_string($errorDetails) ? $errorDetails : json_encode($errorDetails)) . "</small>";
                $storeResults[] = $errorMsg;
                $storeSuccess = false;
            }

            // Test 2: Test PULL capability for both custom and smart collections
            $collectionTypes = ['custom_collections', 'smart_collections'];
            $pullSuccessCount = 0;
            
            foreach ($collectionTypes as $collectionType) {
                $collectionsTestUrl = "https://{$targetUrl}/admin/api/{$version}/{$collectionType}.json?limit=1";
                
                $ch = curl_init($collectionsTestUrl);
                curl_setopt_array($ch, [
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_HTTPHEADER     => [
                        "X-Shopify-Access-Token: {$token}",
                        "Content-Type: application/json"
                    ],
                    CURLOPT_SSL_VERIFYPEER => false,
                    CURLOPT_TIMEOUT        => 15,
                    CURLOPT_HEADER         => true,
                ]);

                $response   = curl_exec($ch);
                $httpCode   = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
                $curlError  = curl_error($ch);
                curl_close($ch);

                $bodyStr = substr($response, $headerSize);
                $typeJson = json_decode($bodyStr, true);

                if ($httpCode === 200 && !empty($typeJson[$collectionType])) {
                    $pullSuccessCount++;
                }
            }
            
            if ($pullSuccessCount === count($collectionTypes)) {
                $storeResults[] = "✅ Pull Collections: SUCCESS - Can retrieve both custom and smart collections";
            } else {
                $errorMsg = "❌ Pull Collections: PARTIAL SUCCESS - Only {$pullSuccessCount}/" . count($collectionTypes) . " collection types accessible";
                $storeResults[] = $errorMsg;
                $storeSuccess = false;
            }

            // Test 3: Test PUSH capability - Try to find a collection and update it
            $pushTestMessage = "❌ Push Collections: NOT TESTED - No collections available";
            
            // Try to get at least one collection from either type
            foreach ($collectionTypes as $collectionType) {
                $collectionsTestUrl = "https://{$targetUrl}/admin/api/{$version}/{$collectionType}.json?limit=1";
                
                $ch = curl_init($collectionsTestUrl);
                curl_setopt_array($ch, [
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_HTTPHEADER     => [
                        "X-Shopify-Access-Token: {$token}",
                        "Content-Type: application/json"
                    ],
                    CURLOPT_SSL_VERIFYPEER => false,
                    CURLOPT_TIMEOUT        => 15,
                ]);

                $response   = curl_exec($ch);
                $httpCode   = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                curl_close($ch);

                if ($httpCode === 200) {
                    $bodyStr = $response;
                    $typeJson = json_decode($bodyStr, true);
                    
                    if (!empty($typeJson[$collectionType]) && is_array($typeJson[$collectionType]) && count($typeJson[$collectionType]) > 0) {
                        $testCollection = $typeJson[$collectionType][0];
                        $collectionId = $testCollection['id'] ?? '';
                        
                        if ($collectionId) {
                            $pushTestUrl = "https://{$targetUrl}/admin/api/{$version}/{$collectionType}/{$collectionId}.json";
                            $payloadKey = ($collectionType === 'smart_collections') ? 'smart_collection' : 'custom_collection';
                            
                            // Use the exact same data to avoid actual changes
                            $pushPayload = json_encode([
                                $payloadKey => [
                                    "id" => $collectionId,
                                    "title" => $testCollection['title'] ?? '',
                                    "handle" => $testCollection['handle'] ?? ''
                                ]
                            ]);

                            $ch = curl_init($pushTestUrl);
                            curl_setopt_array($ch, [
                                CURLOPT_CUSTOMREQUEST  => "PUT",
                                CURLOPT_POSTFIELDS     => $pushPayload,
                                CURLOPT_HTTPHEADER     => [
                                    "X-Shopify-Access-Token: {$token}",
                                    "Content-Type: application/json"
                                ],
                                CURLOPT_RETURNTRANSFER => true,
                                CURLOPT_SSL_VERIFYPEER => false,
                                CURLOPT_TIMEOUT        => 15,
                            ]);

                            $response   = curl_exec($ch);
                            $httpCode   = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                            $curlError  = curl_error($ch);
                            curl_close($ch);

                            $bodyStr = $response;
                            $pushJson = json_decode($bodyStr, true);

                            if ($httpCode >= 200 && $httpCode < 300) {
                                $pushTestMessage = "✅ Push Collections: SUCCESS - Can update collections (tested on {$collectionType} ID: {$collectionId})";
                                break; // Success, no need to test other types
                            } else {
                                $errorDetails = $pushJson['errors'] ?? $bodyStr;
                                $pushTestMessage = "❌ Push Collections: FAILED! HTTP {$httpCode}";
                                if ($curlError) {
                                    $pushTestMessage .= " | cURL: " . htmlspecialchars($curlError);
                                }
                                if (!empty($errorDetails)) {
                                    $pushTestMessage .= "<br><small>" . htmlspecialchars(is_string($errorDetails) ? $errorDetails : json_encode($errorDetails)) . "</small>";
                                }
                                $storeSuccess = false;
                                break; // Error, no need to test other types
                            }
                        } else {
                            // Collection found but no valid ID, try next type
                            continue;
                        }
                    }
                }
            }
            
            // If we still don't have a push test result, try count endpoints as fallback
            if (strpos($pushTestMessage, '✅') === false && strpos($pushTestMessage, '❌') === 0) {
                $countUrl = "https://{$targetUrl}/admin/api/{$version}/custom_collections/count.json";
                
                $ch = curl_init($countUrl);
                curl_setopt_array($ch, [
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_HTTPHEADER     => [
                        "X-Shopify-Access-Token: {$token}",
                        "Content-Type: application/json"
                    ],
                    CURLOPT_SSL_VERIFYPEER => false,
                    CURLOPT_TIMEOUT        => 15,
                ]);

                $response   = curl_exec($ch);
                $httpCode   = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                curl_close($ch);

                if ($httpCode === 200) {
                    $pushTestMessage = "✅ Push Collections: SUCCESS - Can access collections endpoint";
                } else {
                    $pushTestMessage = "❌ Push Collections: Unable to verify - No collections found to test with";
                }
            }
            
            $storeResults[] = $pushTestMessage;
            
            // Combine all results for this store
            $results[$storeKey] = implode('<br>', $storeResults);
            
            if (!$storeSuccess) {
                $allSuccess = false;
            }
        }
        
        $message = implode('<br><br>', $results);
        if ($allSuccess) {
            $message = "✅ All connections and API capabilities verified successfully!<br><br>" . $message;
        } else {
            $message = "⚠️ Some API capabilities failed!<br><br>" . $message;
        }
        recordUserLog('Test Connection', 'Collections API', "Tested Shopify API connection (collections) for retail + business stores — " . ($allSuccess ? 'all checks passed.' : 'some checks failed.'), 'collection', null, $allSuccess ? 'success' : 'error');
    } catch (Throwable $e) {
        $message = "ERROR: Test connection failed – " . htmlspecialchars($e->getMessage());
    }
}

// B. SYNC COLLECTIONS FROM SHOPIFY (High-performance GraphQL with REST fallback)
if (isset($_POST['action']) && $_POST['action'] === 'sync_collections') {
    @set_time_limit(300);
    @ini_set('max_execution_time', '300');
    @ini_set('memory_limit', '512M');

    try {
        $syncedCount      = 0;
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

        recordUserLog('sync_started', 'collections', "Starting collections sync for store: {$activeStore}", 'collection', null, 'info');

        $insertStmt = null;
        if ($db) {
            $insertStmt = $db->prepare("
                INSERT INTO shopify_collections (
                    store_key, shopify_collection_id, collection_type, collection_title,
                    collection_url, title, meta_description, handle, item_count,
                    status, seo_score, last_synced_at
                ) VALUES (
                    :store, :cid, :ctype, :cname,
                    :curl, :title, :meta_desc, :handle, :item_count,
                    :status, :seo_score, NOW()
                )
                ON DUPLICATE KEY UPDATE
                    collection_title  = VALUES(collection_title),
                    collection_type   = VALUES(collection_type),
                    collection_url    = VALUES(collection_url),
                    title             = IF(shopify_collections.status = 'draft' AND shopify_collections.title != '', shopify_collections.title, VALUES(title)),
                    meta_description  = IF(shopify_collections.status = 'draft' AND shopify_collections.meta_description != '', shopify_collections.meta_description, VALUES(meta_description)),
                    handle            = IF(shopify_collections.status = 'draft' AND shopify_collections.handle != '', shopify_collections.handle, VALUES(handle)),
                    item_count        = VALUES(item_count),
                    seo_score         = VALUES(seo_score),
                    status            = VALUES(status),
                    last_synced_at    = NOW()
            ");
        }

        $gqlSuccess = false;

        // 1. High-Performance GraphQL Sync (single batch with metadata)
        foreach ($domainsToTry as $domain) {
            $gqlUrl          = "https://{$domain}/admin/api/{$version}/graphql.json";
            $cursor          = null;
            $hasNextPage     = true;
            $batchCount      = 0;
            $batchLimit      = 40;
            $tempCollections = [];

            $gqlQuery = <<<'GQL'
query getCollections($cursor: String) {
  collections(first: 250, after: $cursor) {
    pageInfo {
      hasNextPage
      endCursor
    }
    nodes {
      id
      legacyResourceId
      title
      handle
      descriptionHtml
      productsCount {
        count
      }
      ruleSet {
        appliedDisjunctively
      }
      seo {
        title
        description
      }
      titleTag: metafield(namespace: "global", key: "title_tag") {
        value
      }
      descTag: metafield(namespace: "global", key: "description_tag") {
        value
      }
      updatedAt
    }
  }
}
GQL;

            while ($hasNextPage && $batchCount < $batchLimit) {
                $batchCount++;

                $payload = json_encode([
                    'query'     => $gqlQuery,
                    'variables' => ['cursor' => $cursor]
                ]);

                $ch = curl_init($gqlUrl);
                curl_setopt_array($ch, [
                    CURLOPT_POST           => true,
                    CURLOPT_POSTFIELDS     => $payload,
                    CURLOPT_HTTPHEADER     => [
                        "X-Shopify-Access-Token: {$token}",
                        "Content-Type: application/json",
                        "Accept: application/json"
                    ],
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_SSL_VERIFYPEER => false,
                    CURLOPT_TIMEOUT        => 30,
                ]);

                $response  = curl_exec($ch);
                $httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                $curlError = curl_error($ch);
                curl_close($ch);

                if ($httpCode === 200 && $response) {
                    $json  = json_decode($response, true);
                    $cData = $json['data']['collections'] ?? null;
                    if (!empty($cData['nodes']) && is_array($cData['nodes'])) {
                        $tempCollections = array_merge($tempCollections, $cData['nodes']);
                        $hasNextPage     = !empty($cData['pageInfo']['hasNextPage']);
                        $cursor          = $cData['pageInfo']['endCursor'] ?? null;
                    } else {
                        if (!empty($json['errors'])) {
                            $apiError = "GraphQL Error: " . json_encode($json['errors']);
                        }
                        break;
                    }
                } else {
                    $apiError = "HTTP {$httpCode} on GraphQL {$domain}";
                    if ($curlError) {
                        $apiError .= " | cURL: {$curlError}";
                    }
                    break;
                }
            }

            if (!empty($tempCollections) && $db && $insertStmt) {
                try {
                    $db->beginTransaction();
                    foreach ($tempCollections as $node) {
                        $rawId = (string)($node['legacyResourceId'] ?? $node['id'] ?? '');
                        $cid   = (int)preg_replace('/[^0-9]/', '', $rawId);
                        if (!$cid) {
                            continue;
                        }

                        $cname  = $node['title'] ?? 'Untitled Collection';
                        $handle = $node['handle'] ?? '';
                        $ctype  = !empty($node['ruleSet']) ? 'smart' : 'custom';

                        $colUrl = "https://" . (!empty($shopCfg['domain']) ? $shopCfg['domain'] : $domain) . "/collections/" . $handle;

                        $bodyClean    = strip_tags($node['descriptionHtml'] ?? '');
                        $fallbackMeta = mb_substr($bodyClean, 0, 160);
                        if (empty($fallbackMeta)) {
                            $fallbackMeta = "Explore our {$cname} collection at Uratex. Quality products designed for comfort and lasting support.";
                        }

                        $title    = !empty($node['titleTag']['value']) ? $node['titleTag']['value'] : (!empty($node['seo']['title']) ? $node['seo']['title'] : $cname);
                        $metaDesc = !empty($node['descTag']['value']) ? $node['descTag']['value'] : (!empty($node['seo']['description']) ? $node['seo']['description'] : $fallbackMeta);

                        $itemCount = isset($node['productsCount']['count']) ? (int)$node['productsCount']['count'] : 0;

                        $score = 85;
                        if (function_exists('calculateSeoHealth')) {
                            $seoAnalysis = calculateSeoHealth($title, $metaDesc, $handle);
                            $score       = $seoAnalysis['score'];
                        }

                        $status = 'published';

                        $insertStmt->execute([
                            ':store'      => $activeStore,
                            ':cid'        => $cid,
                            ':ctype'      => $ctype,
                            ':cname'      => $cname,
                            ':curl'       => $colUrl,
                            ':title'      => $title,
                            ':meta_desc'  => $metaDesc,
                            ':handle'     => $handle,
                            ':item_count' => $itemCount,
                            ':status'     => $status,
                            ':seo_score'  => $score
                        ]);
                        $syncedCount++;
                    }
                    $db->commit();
                    $gqlSuccess       = true;
                    $successfulDomain = $domain;
                    break;
                } catch (Throwable $e) {
                    if ($db->inTransaction()) {
                        $db->rollBack();
                    }
                    $apiError = "Database error during GraphQL collection sync: " . $e->getMessage();
                }
            }
        }

        // 2. Fallback to REST API if GraphQL returned no collections
        if (!$gqlSuccess) {
            $allCollections = [];
            $endpoints      = ['custom_collections', 'smart_collections'];

            foreach ($domainsToTry as $domain) {
                $domainSuccess   = true;
                $tempCollections = [];

                foreach ($endpoints as $endpoint) {
                    $nextUrl   = "https://{$domain}/admin/api/{$version}/{$endpoint}.json?limit=250";
                    $headers   = [
                        "X-Shopify-Access-Token: {$token}",
                        "Content-Type: application/json"
                    ];
                    $pageLimit = 20;
                    $pageCount = 0;

                    while (!empty($nextUrl) && $pageCount < $pageLimit) {
                        $pageCount++;

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
                            $headersStr = substr($response, 0, $headerSize);
                            $bodyStr    = substr($response, $headerSize);
                            $json       = json_decode($bodyStr, true);

                            $key = ($endpoint === 'custom_collections') ? 'custom_collections' : 'smart_collections';
                            if (!empty($json[$key]) && is_array($json[$key])) {
                                foreach ($json[$key] as $col) {
                                    $col['_type'] = ($endpoint === 'custom_collections') ? 'custom' : 'smart';
                                    $tempCollections[] = $col;
                                }
                            }

                            $nextUrl = '';
                            if (preg_match('/<([^>]+)>;\s*rel=["\']next["\']/i', $headersStr, $match)) {
                                $nextUrl = $match[1];
                            }
                        } else {
                            $bodyStr   = is_string($response) ? substr($response, $headerSize) : '';
                            $errorData = json_decode($bodyStr, true);
                            $apiError  = "HTTP {$httpCode} on {$domain}/{$endpoint}";
                            if ($curlError) {
                                $apiError .= " | cURL: {$curlError}";
                            }
                            if (!empty($errorData['errors'])) {
                                $apiError .= " | " . json_encode($errorData['errors']);
                            }
                            $domainSuccess = false;
                            break 2;
                        }
                    }
                }

                if ($domainSuccess && !empty($tempCollections)) {
                    $allCollections   = $tempCollections;
                    $successfulDomain = $domain;
                    break;
                }
            }

            if ($db && !empty($allCollections) && $insertStmt) {
                try {
                    $db->beginTransaction();
                    foreach ($allCollections as $c) {
                        $cid    = $c['id'] ?? 0;
                        $cname  = $c['title'] ?? 'Untitled Collection';
                        $handle = $c['handle'] ?? '';
                        $ctype  = $c['_type'] ?? 'custom';

                        $colUrl = "https://" . (!empty($shopCfg['domain']) ? $shopCfg['domain'] : $successfulDomain) . "/collections/" . $handle;

                        $bodyClean    = strip_tags($c['body_html'] ?? '');
                        $fallbackMeta = mb_substr($bodyClean, 0, 160);
                        if (empty($fallbackMeta)) {
                            $fallbackMeta = "Explore our {$cname} collection at Uratex. Quality products designed for comfort and lasting support.";
                        }

                        $title    = $c['title'] ?? $cname;
                        $metaDesc = $fallbackMeta;

                        $itemCount = (int)($c['products_count'] ?? 0);

                        $score = 85;
                        if (function_exists('calculateSeoHealth')) {
                            $seoAnalysis = calculateSeoHealth($title, $metaDesc, $handle);
                            $score       = $seoAnalysis['score'];
                        }

                        $status = mapCollectionStatus($c['published_at'] ?? null);

                        $insertStmt->execute([
                            ':store'      => $activeStore,
                            ':cid'        => $cid,
                            ':ctype'      => $ctype,
                            ':cname'      => $cname,
                            ':curl'       => $colUrl,
                            ':title'      => $title,
                            ':meta_desc'  => $metaDesc,
                            ':handle'     => $handle,
                            ':item_count' => $itemCount,
                            ':status'     => $status,
                            ':seo_score'  => $score
                        ]);
                        $syncedCount++;
                    }
                    $db->commit();
                } catch (Throwable $e) {
                    if ($db->inTransaction()) {
                        $db->rollBack();
                    }
                    $apiError = "Database error during REST collection sync: " . $e->getMessage();
                }
            }
        }

        if ($syncedCount > 0) {
            $message = "✅ Successfully synchronized <strong>{$syncedCount}</strong> collections from <strong>{$successfulDomain}</strong> ({$shopCfg['name']}).";
            recordUserLog('sync_success', 'collections', "Synced {$syncedCount} collections from {$successfulDomain}", 'collection', null, 'success');
        } else {
            if ($apiError) {
                $message = "ERROR: Shopify API failed for {$shopCfg['name']}. {$apiError}";
            } else {
                $message = "ERROR: No collections were returned from the Shopify API for {$shopCfg['name']}. Please check your API credentials and store configuration.";
            }
            recordUserLog('sync_error', 'collections', $message, 'collection', null, 'error');
        }
    } catch (Throwable $e) {
        $message = "ERROR: Sync crashed – " . htmlspecialchars($e->getMessage()) .
                   " (file: " . basename($e->getFile()) . " line " . $e->getLine() . ")";
        recordUserLog('sync_crash', 'collections', $message, 'collection', null, 'error');
    }
}

// C. SAVE DRAFT
if (isset($_POST['action']) && $_POST['action'] === 'save_draft') {
    try {
        $collectionId    = (int)($_POST['collection_id'] ?? 0);
        $title           = trim($_POST['title'] ?? '');
        $metaDescription = trim($_POST['meta_description'] ?? '');
        $handle          = trim($_POST['handle'] ?? '');

        if ($collectionId && !empty($title) && $db) {
            $stmt = $db->prepare("
                UPDATE shopify_collections
                SET title = :title,
                    meta_description = :meta_desc,
                    handle = :handle,
                    status = 'draft',
                    updated_by = :user,
                    updated_at = NOW()
                WHERE id = :id AND store_key = :store
            ");
            $stmt->execute([
                ':title'     => $title,
                ':meta_desc' => $metaDescription,
                ':handle'    => $handle,
                ':user'      => $currentUser,
                ':id'        => $collectionId,
                ':store'     => $activeStore
            ]);
            $message = "SEO Draft saved successfully for collection #{$collectionId}.";
            recordUserLog('Draft Saved', $title, "Saved SEO draft for collection #{$collectionId} (store: {$activeStore}). Title, meta description and handle updated locally.", 'collection', $collectionId, 'success');
        }
    } catch (Throwable $e) {
        $message = "ERROR: Save draft failed – " . htmlspecialchars($e->getMessage());
    }
}

// D. PUSH TO SHOPIFY
if (isset($_POST['action']) && $_POST['action'] === 'push_shopify') {
    try {
        $collectionId    = (int)($_POST['collection_id'] ?? 0);
        $title           = trim($_POST['title'] ?? '');
        $metaDescription = trim($_POST['meta_description'] ?? '');
        $handle          = trim($_POST['handle'] ?? '');

        if ($collectionId && $db) {
            $stmt = $db->prepare("SELECT * FROM shopify_collections WHERE id = :id AND store_key = :store LIMIT 1");
            $stmt->execute([':id' => $collectionId, ':store' => $activeStore]);
            $col = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($col) {
                $result = pushCollectionSeoToShopify($db, $shopCfg, $activeStore, $col, $currentUser, $title, $metaDescription, $handle);

                if ($result['success']) {
                    $message = "✅ Live SEO update pushed to Shopify store ({$shopCfg['name']}) successfully!";
                    recordUserLog('Shopify Push', $result['title'], "Pushed collection #{$collectionId} live to {$shopCfg['name']} (Shopify ID: {$col['shopify_collection_id']}). Title, handle and meta tags updated.", 'collection', $collectionId, 'success');
                } else {
                    $message = "⚠️ Shopify API returned HTTP {$result['http_code']}. The push failed and the record was NOT marked as published.";
                    recordUserLog('Shopify Push Failed', $result['title'], "Push of collection #{$collectionId} to {$shopCfg['name']} returned HTTP {$result['http_code']}. Record kept out of 'published' status.", 'collection', $collectionId, 'error');
                }
            }
        }
    } catch (Throwable $e) {
        $message = "ERROR: Push failed – " . htmlspecialchars($e->getMessage());
    }
}

// E. BULK APPROVE & PUSH
if (isset($_POST['action']) && $_POST['action'] === 'bulk_push') {
    @set_time_limit(300);
    @ini_set('max_execution_time', '300');

    try {
        if ($db) {
            $draftStmt = $db->prepare("SELECT * FROM shopify_collections WHERE store_key = :store AND status = 'draft'");
            $draftStmt->execute([':store' => $activeStore]);
            $drafts = $draftStmt->fetchAll(PDO::FETCH_ASSOC);

            $successCount = 0;
            $failures     = [];

            foreach ($drafts as $draftCol) {
                $result = pushCollectionSeoToShopify($db, $shopCfg, $activeStore, $draftCol, $currentUser);
                if ($result['success']) {
                    $successCount++;
                } else {
                    $failures[] = ($draftCol['handle'] ?: $draftCol['title']) . " (HTTP {$result['http_code']})";
                }
            }

            $failCount = count($failures);
            $total     = count($drafts);

            if ($failCount === 0) {
                $message = "✅ Bulk push complete: <strong>{$successCount}</strong> of <strong>{$total}</strong> draft collections pushed to Shopify ({$shopCfg['name']}) successfully.";
            } else {
                $failList = htmlspecialchars(implode(', ', array_slice($failures, 0, 10)));
                $more     = $failCount > 10 ? ' and ' . ($failCount - 10) . ' more' : '';
                $message  = "⚠️ Bulk push finished with errors: <strong>{$successCount}</strong> succeeded, <strong>{$failCount}</strong> failed out of {$total}. Failed: {$failList}{$more}.";
            }

            recordUserLog(
                'Bulk Approve & Push',
                'Collections',
                "Bulk pushed {$total} draft collection(s) for {$shopCfg['name']} (store: {$activeStore}): {$successCount} succeeded, {$failCount} failed.",
                'collection',
                null,
                $failCount === 0 ? 'success' : 'error'
            );
        }
    } catch (Throwable $e) {
        $message = "ERROR: Bulk push failed – " . htmlspecialchars($e->getMessage());
    }
}

// -----------------------------------------------------------------------------
// PAGINATION & QUERY
// -----------------------------------------------------------------------------
$itemsPerPage  = 20;
$currentPage   = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$offset        = ($currentPage - 1) * $itemsPerPage;
$searchQuery   = trim($_GET['search'] ?? '');
$statusFilter  = trim($_GET['status'] ?? 'All Statuses');
$storeKey      = $activeStore;

$whereClauses = ["store_key = :store"];
$params       = [':store' => $activeStore];

if (!empty($searchQuery)) {
    $whereClauses[]    = "(title LIKE :search OR handle LIKE :search OR collection_title LIKE :search)";
    $params[':search'] = '%' . $searchQuery . '%';
}

if ($statusFilter !== 'All Statuses' && !empty($statusFilter)) {
    $statusMap = [
        'Draft'              => 'draft',
        'Published'          => 'published',
        'Needs Optimization' => 'needs_optimization',
        'Archived'           => 'archived'
    ];
    $mappedStatus      = $statusMap[$statusFilter] ?? strtolower($statusFilter);
    $whereClauses[]    = "status = :status";
    $params[':status'] = $mappedStatus;
}

$whereSql = implode(' AND ', $whereClauses);

$totalCollections = 0;
$draftCount       = 0;

if ($db) {
    try {
        $countStmt = $db->prepare("SELECT COUNT(*) FROM shopify_collections WHERE {$whereSql}");
        $countStmt->execute($params);
        $totalCollections = (int)$countStmt->fetchColumn();

        $dStmt = $db->prepare("SELECT COUNT(*) FROM shopify_collections WHERE store_key = :store AND status = 'draft'");
        $dStmt->execute([':store' => $activeStore]);
        $draftCount = (int)$dStmt->fetchColumn();

    } catch (Throwable $e) {
        // silent
    }
}

$totalPages = max(1, ceil($totalCollections / $itemsPerPage));
if ($currentPage > $totalPages) {
    $currentPage = $totalPages;
}

$collectionsList = [];
if ($db) {
    try {
        $querySql = "SELECT * FROM shopify_collections WHERE {$whereSql} ORDER BY id ASC LIMIT {$itemsPerPage} OFFSET {$offset}";
        $stmt     = $db->prepare($querySql);
        $stmt->execute($params);
        $collectionsList = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        $collectionsList = [];
    }
}

$pageTitle = 'Collections SEO Module';
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
          <h1 class="m-0 font-weight-bold" style="color: #003087;">Collections SEO Module</h1>
          <p class="text-muted small mb-0">Optimize collection titles, meta descriptions, and handles.</p>
        </div>

        <div class="col-sm-6">
          <div class="row justify-content-end">
            <div class="col-md-4 mb-2 mb-md-0">
              <div class="card h-100 mb-0 shadow-sm border-0" style="border-top: 4px solid #007bff !important; border-radius: 8px;">
                <div class="card-body p-2">
                  <div class="small font-weight-bold text-dark"><i class="fas fa-plug text-primary mr-1"></i>Test Connection</div>
                  <div class="small text-muted mb-2">Verify Shopify API access.</div>
                  <form method="POST">
                    <input type="hidden" name="action" value="test_connection">
                    <button type="submit" class="btn btn-sm btn-primary btn-block font-weight-bold">Run Test</button>
                  </form>
                </div>
              </div>
            </div>
            <div class="col-md-4 mb-2 mb-md-0">
              <div class="card h-100 mb-0 shadow-sm border-0" style="border-top: 4px solid #eab308 !important; border-radius: 8px;">
                <div class="card-body p-2">
                  <div class="small font-weight-bold text-dark"><i class="fas fa-sync-alt text-warning mr-1"></i>Sync Collections</div>
                  <div class="small text-muted mb-2">Refresh the local catalog.</div>
                  <form method="POST">
                    <input type="hidden" name="action" value="sync_collections">
                    <button type="submit" class="btn btn-sm btn-warning btn-block font-weight-bold">Sync Now</button>
                  </form>
                </div>
              </div>
            </div>
            <div class="col-md-4">
              <div class="card h-100 mb-0 shadow-sm border-0" style="border-top: 4px solid #16a34a !important; border-radius: 8px;">
                <div class="card-body p-2">
                  <div class="small font-weight-bold text-dark"><i class="fas fa-layer-group text-success mr-1"></i>Bulk Push</div>
                  <div class="small text-muted mb-2">Export, import, or publish.</div>
                  <div class="d-flex flex-wrap">
                    <form method="POST" class="mr-1 mb-1">
                      <input type="hidden" name="action" value="export_collections">
                      <button type="submit" class="btn btn-sm btn-outline-secondary font-weight-bold" title="Export collection SEO data"><i class="fas fa-file-export mr-1"></i>Export</button>
                    </form>
                    <form method="POST" enctype="multipart/form-data" class="mr-1 mb-1">
                      <input type="hidden" name="action" value="import_collections">
                      <label class="btn btn-sm btn-outline-secondary font-weight-bold mb-0" title="Import collection SEO data"><i class="fas fa-file-import mr-1"></i>Import<input type="file" name="collections_csv" accept=".csv,text/csv" class="d-none" onchange="this.form.submit()"></label>
                    </form>
                    <form method="POST" class="mb-1">
                      <input type="hidden" name="action" value="bulk_push">
                      <button type="submit" class="btn btn-sm btn-success font-weight-bold" <?php echo $draftCount === 0 ? 'disabled' : ''; ?> title="Bulk push imported collections to Shopify"><i class="fas fa-check-double mr-1"></i>Bulk Push (<?php echo $draftCount; ?>)</button>
                    </form>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>

  <section class="content">
    <div class="container-fluid">

      <!-- Search & Filter -->
      <div class="card p-3 mb-4 shadow-sm border-0" style="border-radius: 12px;">
        <form method="GET" action="collections.php" class="row align-items-center">
          <input type="hidden" name="store" value="<?php echo htmlspecialchars($storeKey); ?>">
          <div class="col-md-5 mb-2 mb-md-0">
            <div class="input-group">
              <div class="input-group-prepend">
                <span class="input-group-text bg-white border-right-0"><i class="fas fa-search text-muted"></i></span>
              </div>
              <input type="text" name="search" class="form-control border-left-0"
                     placeholder="Search collection title or handle..."
                     value="<?php echo htmlspecialchars($searchQuery); ?>">
            </div>
          </div>
          <div class="col-md-4 mb-2 mb-md-0">
            <select name="status" class="form-control">
              <option value="All Statuses" <?php echo $statusFilter === 'All Statuses' ? 'selected' : ''; ?>>All Statuses</option>
              <option value="Draft" <?php echo $statusFilter === 'Draft' ? 'selected' : ''; ?>>Draft</option>
              <option value="Published" <?php echo $statusFilter === 'Published' ? 'selected' : ''; ?>>Published</option>
              <option value="Needs Optimization" <?php echo $statusFilter === 'Needs Optimization' ? 'selected' : ''; ?>>Needs Optimization</option>
            </select>
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
          Showing <strong><?php echo $totalCollections > 0 ? $offset + 1 : 0; ?></strong> to
          <strong><?php echo min($offset + $itemsPerPage, $totalCollections); ?></strong> of
          <strong><?php echo $totalCollections; ?></strong> collections (20 per page)
        </div>
        <div>Page <strong><?php echo $currentPage; ?></strong> of <strong><?php echo $totalPages; ?></strong></div>
      </div>

      <!-- Collections Grid -->
      <div class="row">
        <?php if (empty($collectionsList)): ?>
          <div class="col-12 text-center py-5">
            <div class="p-5 bg-white rounded-lg shadow-sm border">
              <i class="fas fa-layer-group fa-3x text-muted mb-3"></i>
              <h5 class="font-weight-bold text-secondary">No Collections Found</h5>
              <p class="text-muted small mb-3">Click "Sync Collections" to import live collections for <?php echo htmlspecialchars($shopCfg['name'] ?? $activeStore); ?>.</p>
              <form method="POST">
                <input type="hidden" name="action" value="sync_collections">
                <button type="submit" class="btn font-weight-bold" style="background-color: #FFCC00; color: #1f2937;">
                  <i class="fas fa-sync-alt mr-1"></i> Sync Collections Now
                </button>
              </form>
            </div>
          </div>
        <?php else: ?>
          <?php foreach ($collectionsList as $col): ?>
            <?php
              $score       = (int)($col['seo_score'] ?? 85);
              $status      = $col['status'] ?? 'draft';
              $statusBadge = $status === 'published' ? 'badge-primary'
                           : ($status === 'archived' ? 'badge-secondary' : 'badge-success');
              $colId       = (int)$col['id'];
              $colTitle    = $col['title'] ?? '';
              $colName     = $col['collection_title'] ?? $colTitle;
              $colMeta     = $col['meta_description'] ?? '';
              $colHandle   = $col['handle'] ?? '';
              $colUrl      = $col['collection_url']
                             ?? ("https://" . ($shopCfg['domain'] ?? '') . "/collections/" . $colHandle);
            ?>
            <div class="col-md-6 mb-4">
              <div class="card shadow-sm h-100 border-0" style="border-radius: 12px; overflow: hidden; border-top: 4px solid #003087 !important;">

                <!-- HEADER with Live View -->
                <div class="card-header bg-white d-flex justify-content-between align-items-center py-3 border-bottom">
                  <div class="d-flex align-items-center text-truncate mr-2" style="max-width: 65%;">
                    <i class="fas fa-layer-group text-primary mr-2 flex-shrink-0"></i>
                    <h6 class="font-weight-bold mb-0 text-truncate text-dark mr-2" title="<?php echo htmlspecialchars($colName); ?>">
                      <?php echo htmlspecialchars($colName); ?>
                    </h6>
                    <a href="<?php echo htmlspecialchars($colUrl); ?>"
                       target="_blank" rel="noreferrer"
                       class="btn btn-sm btn-info shadow-sm flex-shrink-0"
                       title="Live View"
                       style="padding: 0.2rem 0.5rem; font-size: 0.75rem;">
                      <i class="fas fa-eye"></i> <span class="d-none d-md-inline ml-1">Live View</span>
                    </a>
                  </div>
                  <div class="d-flex align-items-center flex-shrink-0">
                    <span class="badge badge-light border text-secondary mr-1" style="font-size: 10px;">
                      <?php echo (int)($col['item_count'] ?? 0); ?> Products
                    </span>
                    <span class="badge badge-info mr-1" style="font-size: 10px;"><?php echo $score; ?>% SEO</span>
                    <span class="badge <?php echo $statusBadge; ?> text-uppercase" style="font-size: 10px;">
                      <?php echo htmlspecialchars(ucfirst($status)); ?>
                    </span>
                  </div>
                </div>

                <div class="card-body p-4">
                  <form method="POST" action="collections.php?page=<?php echo $currentPage; ?>">
                    <input type="hidden" name="collection_id" value="<?php echo $colId; ?>">

                    <!-- Title -->
                    <div class="form-group mb-3">
                      <div class="d-flex justify-content-between align-items-center mb-1">
                        <label class="font-weight-bold small text-secondary mb-0">Collection SEO Title</label>
                        <span class="text-muted small"><span id="title-count-<?php echo $colId; ?>"><?php echo mb_strlen($colTitle); ?></span> / 60 chars</span>
                      </div>
                      <input type="text" name="title" class="form-control font-weight-bold"
                             value="<?php echo htmlspecialchars($colTitle); ?>"
                             data-char-counter="title-count-<?php echo $colId; ?>" required>
                    </div>

                    <!-- Meta -->
                    <div class="form-group mb-3">
                      <div class="d-flex justify-content-between align-items-center mb-1">
                        <label class="font-weight-bold small text-secondary mb-0">Meta Description</label>
                        <span class="text-muted small"><span id="meta-count-<?php echo $colId; ?>"><?php echo mb_strlen($colMeta); ?></span> / 160 chars</span>
                      </div>
                      <textarea name="meta_description" class="form-control" rows="3"
                                style="resize: vertical;" data-char-counter="meta-count-<?php echo $colId; ?>"><?php echo htmlspecialchars($colMeta); ?></textarea>
                    </div>

                    <!-- Handle -->
                    <div class="form-group mb-4">
                      <label class="font-weight-bold small text-secondary mb-1">URL Handle</label>
                      <div class="input-group">
                        <div class="input-group-prepend">
                          <span class="input-group-text bg-light text-muted" style="font-size: 12px;">/collections/</span>
                        </div>
                        <input type="text" name="handle" class="form-control font-mono"
                               value="<?php echo htmlspecialchars($colHandle); ?>" required>
                      </div>
                    </div>

                    <div class="d-flex justify-content-between pt-3 border-top">
                      <button type="submit" name="action" value="save_draft"
                              class="btn btn-light border font-weight-bold">
                        <i class="fas fa-save mr-1 text-secondary"></i> Save Draft
                      </button>
                      <button type="submit" name="action" value="push_shopify"
                              class="btn font-weight-bold text-white" style="background-color: #003087;">
                        <i class="fas fa-upload mr-1"></i> Push to Shopify
                      </button>
                    </div>
                  </form>
                </div>
              </div>
            </div>
          <?php endforeach; ?>
        <?php endif; ?>
      </div>

      <!-- Pagination -->
      <?php if ($totalPages > 1): ?>
        <div class="card p-3 mb-4 shadow-sm border-0" style="border-radius: 12px;">
          <div class="d-flex flex-column flex-lg-row justify-content-between align-items-center gap-3">
            <div class="small text-muted">
              Showing page <strong><?php echo $currentPage; ?></strong> of <strong><?php echo $totalPages; ?></strong>
              (<strong><?php echo $totalCollections; ?></strong> total collections
            </div>
            <nav>
              <ul class="pagination pagination-sm m-0">
                <li class="page-item <?php echo $currentPage <= 1 ? 'disabled' : ''; ?>">
                  <a class="page-link" href="?store=<?php echo urlencode($storeKey); ?>&page=<?php echo max(1, $currentPage - 1); ?>&search=<?php echo urlencode($searchQuery); ?>&status=<?php echo urlencode($statusFilter); ?>">
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
                    <a class="page-link" href="?store=<?php echo urlencode($storeKey); ?>&page=<?php echo $pItem; ?>&search=<?php echo urlencode($searchQuery); ?>&status=<?php echo urlencode($statusFilter); ?>"
                       style="<?php echo $currentPage === $pItem ? 'background-color:#003087;border-color:#003087;color:#fff;' : ''; ?>">
                      <?php echo $pItem; ?>
                    </a>
                  </li>
                <?php endif; endforeach; ?>
                <li class="page-item <?php echo $currentPage >= $totalPages ? 'disabled' : ''; ?>">
                  <a class="page-link" href="?store=<?php echo urlencode($storeKey); ?>&page=<?php echo min($totalPages, $currentPage + 1); ?>&search=<?php echo urlencode($statusFilter); ?>">
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

<?php include __DIR__ . '/../includes/footer.php'; ?>