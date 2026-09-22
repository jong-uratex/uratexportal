<?php
/**
 * One-time collection title cleanup.
 *
 * Visible collection titles lose a trailing "| Uratex".
 * Search engine listing titles retain exactly one trailing "| Uratex".
 */
require_once __DIR__ . '/config/config.php';

@set_time_limit(60);
@ini_set('max_execution_time', '60');

if (!isset($_SESSION['user_logged_in'])) {
    http_response_code(403);
    exit('Authentication required.');
}

$db = getDbConnection();
if (!$db) {
    http_response_code(500);
    exit('Database connection unavailable.');
}

$adminDomain = static function (array $config, string $storeKey): string {
    return $config['url'] ?: ($config['fallback_url'] ?: ($storeKey === 'business'
        ? 'uratex-business.myshopify.com'
        : 'uratex-philippines.myshopify.com'));
};

$request = static function (string $method, string $url, string $token, ?array $payload = null): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_HTTPHEADER     => [
            "X-Shopify-Access-Token: {$token}",
            'Content-Type: application/json',
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT        => 8,
    ]);
    if ($payload !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    }
    $body = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    return [$code, $body, $error];
};

$cleanSuffix = static function (string $value): string {
    return trim((string)preg_replace('/(?:\s*\|\s*Uratex)+\s*$/i', '', trim($value)));
};

$withSuffix = static function (string $value) use ($cleanSuffix): string {
    $base = $cleanSuffix($value);
    return $base === '' ? 'Uratex' : $base . ' | Uratex';
};

$escape = static function (string $value): string {
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
};

$confirmationToken = 'REMOVE_COLLECTION_URATEX_SUFFIX';
$confirmed = isset($_POST['confirm']) && hash_equals($confirmationToken, (string)$_POST['confirm']);
$lastId = max(0, (int)($_POST['last_id'] ?? 0));
$batchSize = 2;
$rows = [];
$errors = [];
$updated = 0;
$processed = 0;
$total = (int)$db->query('SELECT COUNT(*) FROM shopify_collections')->fetchColumn();
$nextLastId = $lastId;
$hasMore = false;

if ($confirmed) {
    $select = $db->prepare('SELECT * FROM shopify_collections WHERE id > :last_id ORDER BY id LIMIT :batch_size');
    $select->bindValue(':last_id', $lastId, PDO::PARAM_INT);
    $select->bindValue(':batch_size', $batchSize, PDO::PARAM_INT);
    $select->execute();
    $collections = $select->fetchAll(PDO::FETCH_ASSOC);

    foreach ($collections as $collection) {
        $processed++;
        $nextLastId = (int)$collection['id'];
        $storeKey = $collection['store_key'];
        $config = $shopConfig[$storeKey] ?? [];
        $token = $config['access_token'] ?? '';
        $version = $config['version'] ?: '2025-10';
        $domain = $adminDomain($config, $storeKey);
        $collectionType = ($collection['collection_type'] ?? 'custom') === 'smart' ? 'smart_collections' : 'custom_collections';
        $payloadKey = $collectionType === 'smart_collections' ? 'smart_collection' : 'custom_collection';
        $shopifyId = (int)$collection['shopify_collection_id'];

        $visibleTitle = $cleanSuffix((string)$collection['collection_title']);
        $seoTitle = $withSuffix((string)$collection['title']);
        if ($seoTitle === 'Uratex') {
            $seoTitle = $withSuffix($visibleTitle);
        }

        if (!$token || !$shopifyId) {
            $errors[] = "{$storeKey} #{$collection['id']}: missing Shopify credentials or collection ID.";
            continue;
        }

        $collectionUrl = "https://{$domain}/admin/api/{$version}/{$collectionType}/{$shopifyId}.json";
        [$code, $body, $curlError] = $request('PUT', $collectionUrl, $token, [
            $payloadKey => [
                'id' => $shopifyId,
                'title' => $visibleTitle,
            ],
        ]);
        if ($code < 200 || $code >= 300) {
            $errors[] = "{$storeKey} #{$collection['id']}: collection update failed (HTTP {$code})" . ($curlError ? " {$curlError}" : '');
            continue;
        }

        $metafieldListUrl = "https://{$domain}/admin/api/{$version}/metafields.json?metafield[owner_id]={$shopifyId}&metafield[owner_resource]=collection&namespace=global&key=title_tag";
        [$code, $body, $curlError] = $request('GET', $metafieldListUrl, $token);
        $metafields = json_decode((string)$body, true);
        $metafieldId = $metafields['metafields'][0]['id'] ?? null;

        if ($metafieldId) {
            $metafieldUrl = "https://{$domain}/admin/api/{$version}/metafields/{$metafieldId}.json";
            $metafieldPayload = ['metafield' => [
                'id' => $metafieldId,
                'value' => $seoTitle,
                'type' => 'single_line_text_field',
            ]];
            [$code, $body, $curlError] = $request('PUT', $metafieldUrl, $token, $metafieldPayload);
        } else {
            $metafieldUrl = "https://{$domain}/admin/api/{$version}/metafields.json";
            $metafieldPayload = ['metafield' => [
                'namespace' => 'global',
                'key' => 'title_tag',
                'value' => $seoTitle,
                'type' => 'single_line_text_field',
                'owner_id' => $shopifyId,
                'owner_resource' => 'collection',
            ]];
            [$code, $body, $curlError] = $request('POST', $metafieldUrl, $token, $metafieldPayload);
        }

        if ($code < 200 || $code >= 300) {
            $errors[] = "{$storeKey} #{$collection['id']}: SEO title update failed (HTTP {$code})" . ($curlError ? " {$curlError}" : '');
            continue;
        }

        $update = $db->prepare("UPDATE shopify_collections SET collection_title = :collection_title, title = :seo_title, updated_by = :updated_by WHERE id = :id");
        $update->execute([
            ':collection_title' => $visibleTitle,
            ':seo_title' => $seoTitle,
            ':updated_by' => $_SESSION['user_name'] ?? 'quick-fix',
            ':id' => $collection['id'],
        ]);
        $updated++;
        $rows[] = "{$storeKey} #{$collection['id']}: {$visibleTitle} / {$seoTitle}";
    }
    $hasMore = count($collections) === $batchSize;
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>Collection title quick fix</title>
  <style>
    body { font-family: sans-serif; max-width: 900px; margin: 40px auto; padding: 0 20px; }
    .warning { background: #fff3cd; border: 1px solid #ffecb5; padding: 16px; }
    .success { background: #d1e7dd; border: 1px solid #a3cfbb; padding: 16px; }
    .error { background: #f8d7da; border: 1px solid #f1aeb5; padding: 16px; }
    button { background: #b42318; color: #fff; border: 0; padding: 10px 16px; cursor: pointer; }
    li { margin: 6px 0; }
  </style>
</head>
<body>
  <h1>Collection title quick fix</h1>
  <?php if (!$confirmed): ?>
    <div class="warning">
    <p>This will update <?php echo $total; ?> local collection records and their Shopify records in batches of <?php echo $batchSize; ?>.</p>
      <p>Visible collection names will lose a trailing <strong>| Uratex</strong>. Search engine listing titles will end with exactly one <strong>| Uratex</strong>.</p>
      <form method="post">
        <input type="hidden" name="confirm" value="<?php echo $escape($confirmationToken); ?>">
        <button type="submit">Run quick fix</button>
      </form>
    </div>
  <?php else: ?>
    <div class="success"><strong><?php echo $updated; ?></strong> collection records updated in this batch (<?php echo $processed; ?> processed).</div>
    <?php if ($errors): ?>
      <div class="error"><strong>Errors</strong><ul><?php foreach ($errors as $error): ?><li><?php echo $escape($error); ?></li><?php endforeach; ?></ul></div>
    <?php endif; ?>
    <?php if ($rows): ?>
      <h2>Updated records</h2>
      <ul><?php foreach ($rows as $row): ?><li><?php echo $escape($row); ?></li><?php endforeach; ?></ul>
    <?php endif; ?>
        <?php if ($hasMore): ?>
            <p id="batch-status">Next batch starts automatically in <strong>10</strong> seconds.</p>
            <form method="post" id="continue-batch-form">
                <input type="hidden" name="confirm" value="<?php echo $escape($confirmationToken); ?>">
                <input type="hidden" name="last_id" value="<?php echo $nextLastId; ?>">
                <button type="submit">Run next batch now</button>
            </form>
        <?php else: ?>
            <p>Quick fix complete. You can remove <code>quick-fix.php</code> from the server now.</p>
        <?php endif; ?>
  <?php endif; ?>
    <?php if ($confirmed && $hasMore): ?>
        <script>
            (function () {
                var seconds = 10;
                var status = document.getElementById('batch-status');
                var form = document.getElementById('continue-batch-form');
                var timer = setInterval(function () {
                    seconds -= 1;
                    if (seconds <= 0) {
                        clearInterval(timer);
                        form.submit();
                        return;
                    }
                    status.innerHTML = 'Next batch starts automatically in <strong>' + seconds + '</strong> seconds.';
                }, 1000);
            }());
        </script>
    <?php endif; ?>
</body>
</html>
