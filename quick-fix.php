<?php
/**
 * One-time title cleanup for Products, Collections, Pages and Blogs.
 *
 * Visible titles lose any trailing "| Uratex".
 * Search engine listing titles (global.title_tag) remain completely untouched.
 */
require_once __DIR__ . '/config/config.php';

@set_time_limit(90);
@ini_set('max_execution_time', '90');

if (!isset($_SESSION['user_logged_in'])) {
    http_response_code(403);
    exit('Authentication required.');
}

$db = getDbConnection();
if (!$db) {
    http_response_code(500);
    exit('Database connection unavailable.');
}

/* --------------------------------------------------------------------------
   Helpers
   -------------------------------------------------------------------------- */
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
        CURLOPT_CONNECTTIMEOUT => 6,
        CURLOPT_TIMEOUT        => 12,
    ]);
    if ($payload !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    }
    $body  = curl_exec($ch);
    $code  = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    return [$code, $body, $error];
};

$cleanSuffix = static function (string $value): string {
    return trim((string) preg_replace('/(?:\s*\|\s*Uratex)+\s*$/i', '', trim($value)));
};

$escape = static function (string $value): string {
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
};

/* --------------------------------------------------------------------------
   Resource definitions
   -------------------------------------------------------------------------- */
$resources = [
    'products' => [
        'label'          => 'Products',
        'table'          => 'shopify_products',
        'id_col'         => 'id',
        'shopify_id_col' => 'shopify_product_id',
        'visible_col'    => 'product_title',   // visible title column
        'seo_col'        => 'title',           // SEO title – NEVER touched
        'store_col'      => 'store_key',
        'endpoint'       => 'products',
        'payload_key'    => 'product',
        'extra_where'    => '',
    ],
    'collections' => [
        'label'          => 'Collections',
        'table'          => 'shopify_collections',
        'id_col'         => 'id',
        'shopify_id_col' => 'shopify_collection_id',
        'visible_col'    => 'collection_title',
        'seo_col'        => 'title',
        'store_col'      => 'store_key',
        'endpoint'       => null,              // decided per row (custom/smart)
        'payload_key'    => null,
        'extra_where'    => '',
    ],
    'pages' => [
        'label'          => 'Pages',
        'table'          => 'shopify_pages',
        'id_col'         => 'id',
        'shopify_id_col' => 'shopify_page_id',
        'visible_col'    => 'page_title',
        'seo_col'        => 'title',
        'store_col'      => 'store_key',
        'endpoint'       => 'pages',
        'payload_key'    => 'page',
        'extra_where'    => '',
    ],
    'blogs' => [
        'label'          => 'Blogs',
        'table'          => 'shopify_blogs',
        'id_col'         => 'id',
        'shopify_id_col' => 'shopify_blog_id',
        'visible_col'    => 'blog_title',
        'seo_col'        => 'title',
        'store_col'      => 'store_key',
        'endpoint'       => 'blogs',
        'payload_key'    => 'blog',
        'extra_where'    => '',
    ],
];

/* --------------------------------------------------------------------------
   State
   -------------------------------------------------------------------------- */
$confirmationToken = 'REMOVE_URATEX_SUFFIX_ALL';
$confirmed   = isset($_POST['confirm']) && hash_equals($confirmationToken, (string) $_POST['confirm']);
$resourceKey = $_POST['resource'] ?? $_GET['resource'] ?? '';
$lastId      = max(0, (int) ($_POST['last_id'] ?? 0));
$batchSize   = 2;

$rows     = [];
$errors   = [];
$updated  = 0;
$processed = 0;
$nextLastId = $lastId;
$hasMore  = false;
$currentResource = $resources[$resourceKey] ?? null;

/* --------------------------------------------------------------------------
   Count totals for dashboard
   -------------------------------------------------------------------------- */
$counts = [];
foreach ($resources as $key => $res) {
    try {
        $counts[$key] = (int) $db->query("SELECT COUNT(*) FROM {$res['table']}")->fetchColumn();
    } catch (Exception $e) {
        $counts[$key] = 0;
    }
}

/* --------------------------------------------------------------------------
   Process a batch when confirmed
   -------------------------------------------------------------------------- */
if ($confirmed && $currentResource) {
    $table       = $currentResource['table'];
    $idCol       = $currentResource['id_col'];
    $shopifyCol  = $currentResource['shopify_id_col'];
    $visibleCol  = $currentResource['visible_col'];
    $storeCol    = $currentResource['store_col'];

    $sql = "SELECT * FROM {$table} WHERE {$idCol} > :last_id ORDER BY {$idCol} LIMIT :batch_size";
    $select = $db->prepare($sql);
    $select->bindValue(':last_id', $lastId, PDO::PARAM_INT);
    $select->bindValue(':batch_size', $batchSize, PDO::PARAM_INT);
    $select->execute();
    $items = $select->fetchAll(PDO::FETCH_ASSOC);

    foreach ($items as $item) {
        $processed++;
        $nextLastId = (int) $item[$idCol];
        $storeKey   = $item[$storeCol] ?? 'retail';
        $config     = $shopConfig[$storeKey] ?? [];
        $token      = $config['access_token'] ?? '';
        $version    = $config['version'] ?: '2025-10';
        $domain     = $adminDomain($config, $storeKey);
        $shopifyId  = (int) ($item[$shopifyCol] ?? 0);

        $visibleTitle = $cleanSuffix((string) ($item[$visibleCol] ?? ''));

        if (!$token || !$shopifyId) {
            $errors[] = "{$storeKey} #{$item[$idCol]}: missing credentials or Shopify ID.";
            continue;
        }

        // ----- Determine endpoint & payload key (collections are special) -----
        if ($resourceKey === 'collections') {
            $isSmart     = ($item['collection_type'] ?? 'custom') === 'smart';
            $endpoint    = $isSmart ? 'smart_collections' : 'custom_collections';
            $payloadKey  = $isSmart ? 'smart_collection' : 'custom_collection';
        } else {
            $endpoint   = $currentResource['endpoint'];
            $payloadKey = $currentResource['payload_key'];
        }

        $apiUrl = "https://{$domain}/admin/api/{$version}/{$endpoint}/{$shopifyId}.json";

        // Update ONLY the visible title – never touch metafields
        [$code, $body, $curlError] = $request('PUT', $apiUrl, $token, [
            $payloadKey => [
                'id'    => $shopifyId,
                'title' => $visibleTitle,
            ],
        ]);

        if ($code < 200 || $code >= 300) {
            $errors[] = "{$storeKey} #{$item[$idCol]}: update failed (HTTP {$code})" . ($curlError ? " – {$curlError}" : '');
            continue;
        }

        // Update local DB – only the visible title column
        $update = $db->prepare("
            UPDATE {$table}
            SET {$visibleCol} = :visible_title,
                updated_by    = :updated_by
            WHERE {$idCol} = :id
        ");
        $update->execute([
            ':visible_title' => $visibleTitle,
            ':updated_by'    => $_SESSION['user_name'] ?? 'quick-fix-all',
            ':id'            => $item[$idCol],
        ]);

        $updated++;
        $original = (string) ($item[$visibleCol] ?? '');
        $rows[] = "{$storeKey} #{$item[$idCol]}: \"{$original}\" → \"{$visibleTitle}\"";
    }

    $hasMore = count($items) === $batchSize;
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>Title Quick-Fix – Products / Collections / Pages / Blogs</title>
  <style>
    body { font-family: system-ui, -apple-system, sans-serif; max-width: 960px; margin: 40px auto; padding: 0 20px; color: #1a1a1a; }
    h1 { font-size: 1.6rem; margin-bottom: 0.25rem; }
    .sub { color: #555; margin-bottom: 1.5rem; }
    .card { background: #f8f9fa; border: 1px solid #dee2e6; border-radius: 10px; padding: 20px; margin-bottom: 20px; }
    .warning { background: #fff3cd; border-color: #ffecb5; }
    .success { background: #d1e7dd; border-color: #a3cfbb; }
    .error   { background: #f8d7da; border-color: #f1aeb5; }
    .grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 12px; margin: 16px 0; }
    .stat { background: #fff; border: 1px solid #dee2e6; border-radius: 8px; padding: 14px; text-align: center; }
    .stat strong { display: block; font-size: 1.4rem; color: #003087; }
    button, .btn {
      background: #b42318; color: #fff; border: 0; padding: 10px 18px;
      border-radius: 6px; cursor: pointer; font-weight: 600; text-decoration: none; display: inline-block;
    }
    button.secondary { background: #6c757d; }
    button:hover, .btn:hover { opacity: 0.9; }
    ul { margin: 8px 0 0 18px; }
    li { margin: 4px 0; font-size: 0.95rem; }
    .resource-btn { margin: 4px 6px 4px 0; }
  </style>
</head>
<body>

<h1>Title Quick-Fix</h1>
<p class="sub">
  Removes trailing <strong>| Uratex</strong> from <em>visible</em> titles only.<br>
  Search engine listing titles (<code>global.title_tag</code>) are <strong>never touched</strong>.
</p>

<?php if (!$confirmed): ?>

  <div class="card warning">
    <p><strong>This is a one-time destructive operation.</strong> Make sure you have a recent backup.</p>

    <div class="grid">
      <?php foreach ($resources as $key => $res): ?>
        <div class="stat">
          <strong><?php echo number_format($counts[$key] ?? 0); ?></strong>
          <?php echo $escape($res['label']); ?>
        </div>
      <?php endforeach; ?>
    </div>

    <p>Select a resource type to clean:</p>
    <form method="post" style="margin-top:12px;">
      <input type="hidden" name="confirm" value="<?php echo $escape($confirmationToken); ?>">
      <?php foreach ($resources as $key => $res): ?>
        <button type="submit" name="resource" value="<?php echo $escape($key); ?>" class="resource-btn">
          Clean <?php echo $escape($res['label']); ?> (<?php echo number_format($counts[$key] ?? 0); ?>)
        </button>
      <?php endforeach; ?>
    </form>
  </div>

<?php else: ?>

  <div class="card success">
    <strong><?php echo $updated; ?></strong> <?php echo $escape($currentResource['label']); ?> updated
    in this batch (<?php echo $processed; ?> processed).
  </div>

  <?php if ($errors): ?>
    <div class="card error">
      <strong>Errors</strong>
      <ul>
        <?php foreach ($errors as $err): ?>
          <li><?php echo $escape($err); ?></li>
        <?php endforeach; ?>
      </ul>
    </div>
  <?php endif; ?>

  <?php if ($rows): ?>
    <div class="card">
      <strong>Updated in this batch</strong>
      <ul>
        <?php foreach ($rows as $row): ?>
          <li><?php echo $escape($row); ?></li>
        <?php endforeach; ?>
      </ul>
    </div>
  <?php endif; ?>

  <?php if ($hasMore): ?>
    <p id="batch-status">Next batch starts automatically in <strong>8</strong> seconds…</p>
    <form method="post" id="continue-batch-form">
      <input type="hidden" name="confirm"  value="<?php echo $escape($confirmationToken); ?>">
      <input type="hidden" name="resource" value="<?php echo $escape($resourceKey); ?>">
      <input type="hidden" name="last_id"  value="<?php echo $nextLastId; ?>">
      <button type="submit">Run next batch now</button>
      <a href="?resource=" class="btn secondary" style="margin-left:10px;">Cancel / choose another resource</a>
    </form>
  <?php else: ?>
    <div class="card success">
      <p><strong><?php echo $escape($currentResource['label']); ?> cleanup complete.</strong></p>
      <p>
        <a href="?" class="btn">← Back to resource selection</a>
      </p>
    </div>
  <?php endif; ?>

<?php endif; ?>

<?php if ($confirmed && $hasMore): ?>
<script>
(function () {
  var seconds = 8;
  var status  = document.getElementById('batch-status');
  var form    = document.getElementById('continue-batch-form');
  var timer   = setInterval(function () {
    seconds -= 1;
    if (seconds <= 0) {
      clearInterval(timer);
      form.submit();
      return;
    }
    status.innerHTML = 'Next batch starts automatically in <strong>' + seconds + '</strong> seconds…';
  }, 1000);
})();
</script>
<?php endif; ?>

</body>
</html>
