<?php
/**
 * Image Manager (image.php) - lists Shopify Files (images), where they are used, and edits Alt Text.
 * Requires Admin API scopes: read_files, write_files, read_products, read_content.
 */
require_once __DIR__ . '/../config/config.php';

if (!isset($_SESSION['user_logged_in'])) {
    header("Location: ../login.php");
    exit;
}

if (isset($_GET['switch_store']) && in_array($_GET['switch_store'], ['retail', 'business'], true)) {
    $_SESSION['active_store'] = $_GET['switch_store'];
}

$activeStore = $_SESSION['active_store'] ?? 'business';
$shopCfg     = $shopConfig[$activeStore] ?? $shopConfig['business'];
$storeDomain = !empty($shopCfg['domain']) ? $shopCfg['domain'] : ($shopCfg['url'] ?? '');

if (empty($_SESSION['img_csrf'])) {
    $_SESSION['img_csrf'] = bin2hex(random_bytes(16));
}

/** Filename without extension/query, used to match images across resources. */
function imgKey(?string $url): string
{
    if (!$url) {
        return '';
    }
    $path = parse_url($url, PHP_URL_PATH) ?: '';
    return strtolower(pathinfo(basename($path), PATHINFO_FILENAME));
}

/** Candidate Shopify file queries for a search term, from most to least specific (quoted wildcards don't match). */
function fileQueryVariants(string $term): array
{
    $base = 'media_type:IMAGE';
    $term = trim(str_replace(['"', '\\', '(', ')', ':', '*'], '', $term));
    if ($term === '') {
        return [$base];
    }
    $v = [$base . ' AND filename:' . str_replace(' ', '', $term) . '*', $base . ' AND ' . $term . '*'];
    $tokens = preg_split('/[^A-Za-z0-9]+/', $term, -1, PREG_SPLIT_NO_EMPTY);
    if ($tokens && $tokens[0] !== $term) {
        $v[] = $base . ' AND filename:' . $tokens[0] . '*';
    }
    return $v;
}

/**
 * Builds a map of image key (media id / filename) => list of ['type','title','url'] usages.
 * Covers products, collections and blog articles. Cached in session for 10 minutes.
 */
function buildUsageMap(string $store, string $domain, bool $force): array
{
    $cacheKey = 'img_usage_' . $store;
    if (!$force && isset($_SESSION[$cacheKey]) && (time() - $_SESSION[$cacheKey]['at']) < 600) {
        return $_SESSION[$cacheKey]['map'];
    }

    $map  = [];
    $add  = function (string $key, array $use) use (&$map) {
        if ($key === '') {
            return;
        }
        foreach ($map[$key] ?? [] as $u) {
            if ($u['url'] === $use['url']) {
                return;
            }
        }
        $map[$key][] = $use;
    };
    $link = function (?string $online, string $path) use ($domain) {
        return $online ?: "https://{$domain}{$path}";
    };

    // Products (all media ids + featured/variant image filenames)
    $cursor = null;
    for ($i = 0; $i < 60; $i++) {
        $q = 'query($after:String){products(first:50,after:$after){pageInfo{hasNextPage endCursor}
              nodes{title handle onlineStoreUrl media(first:100){nodes{id}}}}}';
        $r = shopifyGraphQLRequest($q, ['after' => $cursor], $store);
        $p = $r['data']['data']['products'] ?? null;
        if (!$p) {
            break;
        }
        foreach ($p['nodes'] as $n) {
            $use = ['type' => 'Product', 'title' => $n['title'], 'url' => $link($n['onlineStoreUrl'], '/products/' . $n['handle'])];
            foreach ($n['media']['nodes'] ?? [] as $m) {
                $add($m['id'], $use);
            }
        }
        if (empty($p['pageInfo']['hasNextPage'])) {
            break;
        }
        $cursor = $p['pageInfo']['endCursor'];
    }

    // Collections
    $cursor = null;
    for ($i = 0; $i < 40; $i++) {
        $q = 'query($after:String){collections(first:100,after:$after){pageInfo{hasNextPage endCursor}
              nodes{title handle image{url}}}}';
        $r = shopifyGraphQLRequest($q, ['after' => $cursor], $store);
        $c = $r['data']['data']['collections'] ?? null;
        if (!$c) {
            break;
        }
        foreach ($c['nodes'] as $n) {
            if (!empty($n['image']['url'])) {
                $add(imgKey($n['image']['url']), ['type' => 'Collection', 'title' => $n['title'], 'url' => "https://{$domain}/collections/{$n['handle']}"]);
            }
        }
        if (empty($c['pageInfo']['hasNextPage'])) {
            break;
        }
        $cursor = $c['pageInfo']['endCursor'];
    }

    // Blog articles
    $cursor = null;
    for ($i = 0; $i < 40; $i++) {
        $q = 'query($after:String){articles(first:100,after:$after){pageInfo{hasNextPage endCursor}
              nodes{title handle blog{handle} image{url}}}}';
        $r = shopifyGraphQLRequest($q, ['after' => $cursor], $store);
        $a = $r['data']['data']['articles'] ?? null;
        if (!$a) {
            break;
        }
        foreach ($a['nodes'] as $n) {
            if (!empty($n['image']['url'])) {
                $add(imgKey($n['image']['url']), ['type' => 'Article', 'title' => $n['title'], 'url' => "https://{$domain}/blogs/{$n['blog']['handle']}/{$n['handle']}"]);
            }
        }
        if (empty($a['pageInfo']['hasNextPage'])) {
            break;
        }
        $cursor = $a['pageInfo']['endCursor'];
    }

    $_SESSION[$cacheKey] = ['at' => time(), 'map' => $map];
    return $map;
}

$notice     = '';
$noticeType = 'success';

// Test connection / scopes
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'test_connection') {
    if (!hash_equals($_SESSION['img_csrf'], $_POST['csrf'] ?? '')) {
        $notice = 'Invalid CSRF token.';
        $noticeType = 'danger';
    } else {
        $sc = shopifyGraphQLRequest('{shop{name} currentAppInstallation{accessScopes{handle}}}', [], $activeStore);
        $granted = array_column($sc['data']['data']['currentAppInstallation']['accessScopes'] ?? [], 'handle');
        if ($sc['status'] === 200 && !empty($sc['data']['data']['shop']['name'])) {
            $missing = array_diff(['read_files', 'write_files'], $granted);
            $notice = 'Connected to ' . htmlspecialchars($sc['data']['data']['shop']['name']) . '.';
            if ($missing) {
                $notice .= ' Missing scopes: ' . implode(', ', $missing) . '.';
                $noticeType = 'warning';
            }
        } else {
            $notice = 'Connection failed: ' . htmlspecialchars($sc['data']['errors'][0]['message'] ?? ($sc['error'] ?: 'Unknown error.'));
            $noticeType = 'danger';
        }
    }
}

// Sync: rebuild the usage cache
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'sync_images') {
    if (hash_equals($_SESSION['img_csrf'], $_POST['csrf'] ?? '')) {
        buildUsageMap($activeStore, $storeDomain, true);
        $notice = 'Image usage refreshed from Shopify.';
    } else {
        $notice = 'Invalid CSRF token.';
        $noticeType = 'danger';
    }
}

// Export all images (File ID, name, alt text, URL, and usage references) as CSV
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'export_images') {
    if (!hash_equals($_SESSION['img_csrf'], $_POST['csrf'] ?? '')) {
        http_response_code(403);
        exit('Invalid CSRF token.');
    }
    set_time_limit(300);
    $exVariants = fileQueryVariants(trim((string)($_POST['q'] ?? '')));
    $exQuery = $exVariants[0];
    foreach ($exVariants as $cand) {
        $probe = shopifyGraphQLRequest('query($query:String){files(first:1,query:$query){nodes{id}}}', ['query' => $cand], $activeStore);
        if (!empty($probe['data']['data']['files']['nodes'])) {
            $exQuery = $cand;
            break;
        }
    }
    $exportUsage = buildUsageMap($activeStore, $storeDomain, false);
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="uratex_images_' . $activeStore . '_' . date('Y-m-d_His') . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['File ID', 'File Name', 'Alt Text', 'Image URL', 'Used in']);
    $cursor = null;
    for ($i = 0; $i < 200; $i++) {
        $q = 'query($after:String,$query:String){files(first:100,after:$after,query:$query,sortKey:CREATED_AT,reverse:true){
                pageInfo{hasNextPage endCursor} nodes{id alt ... on MediaImage{image{url}}}}}';
        $r = shopifyGraphQLRequest($q, ['after' => $cursor, 'query' => $exQuery], $activeStore);
        $f = $r['data']['data']['files'] ?? null;
        if (!$f) {
            break;
        }
        foreach ($f['nodes'] as $n) {
            if (empty($n['image']['url'])) {
                continue;
            }
            $usages = array_merge($exportUsage[$n['id']] ?? [], $exportUsage[imgKey($n['image']['url'])] ?? []);
            $usedIn = [];
            foreach ($usages as $usage) {
              $usedIn[$usage['url']] = $usage['type'] . ': ' . $usage['title'] . ' (' . $usage['url'] . ')';
            }
            fputcsv($out, [
              $n['id'],
              basename(parse_url($n['image']['url'], PHP_URL_PATH)),
              $n['alt'] ?? '',
              $n['image']['url'],
              $usedIn ? implode('; ', $usedIn) : 'No product/collection/article reference found'
            ]);
        }
        if (empty($f['pageInfo']['hasNextPage'])) {
            break;
        }
        $cursor = $f['pageInfo']['endCursor'];
    }
    fclose($out);
    exit;
}

// Import CSV (File ID + Alt Text) and push to the live store immediately
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'import_images') {
    set_time_limit(300);
    if (!hash_equals($_SESSION['img_csrf'], $_POST['csrf'] ?? '')) {
        $notice = 'Invalid CSRF token.';
        $noticeType = 'danger';
    } elseif (empty($_FILES['images_csv']['tmp_name']) || $_FILES['images_csv']['error'] !== UPLOAD_ERR_OK) {
        $notice = 'Please choose a valid CSV file to import.';
        $noticeType = 'danger';
    } else {
        $h = fopen($_FILES['images_csv']['tmp_name'], 'r');
        $headers = $h ? fgetcsv($h) : false;
        if ($headers && isset($headers[0])) {
            $headers[0] = preg_replace('/^\xEF\xBB\xBF/', '', $headers[0]);
        }
        $map = $headers ? array_flip(array_map('trim', $headers)) : [];
        if (!$h || !isset($map['File ID'], $map['Alt Text'])) {
            $notice = 'The CSV must include File ID and Alt Text columns.';
            $noticeType = 'danger';
        } else {
            $batch = [];
            $pushed = 0;
            $skipped = 0;
            $failed = 0;
            $firstErr = '';
            $flush = function () use (&$batch, &$pushed, &$failed, &$firstErr, $activeStore) {
                if (!$batch) {
                    return;
                }
                $m = 'mutation($files:[FileUpdateInput!]!){fileUpdate(files:$files){files{id}userErrors{field message}}}';
                $r = shopifyGraphQLRequest($m, ['files' => $batch], $activeStore);
                $errs = $r['data']['data']['fileUpdate']['userErrors'] ?? [];
                if ($r['status'] !== 200 || !empty($r['data']['errors'])) {
                    $failed += count($batch);
                    $firstErr = $firstErr ?: ($r['data']['errors'][0]['message'] ?? ($r['error'] ?: 'Shopify request failed.'));
                } else {
                    $pushed += count($r['data']['data']['fileUpdate']['files'] ?? []);
                    if ($errs) {
                        $failed += count($batch) - count($r['data']['data']['fileUpdate']['files'] ?? []);
                        $firstErr = $firstErr ?: $errs[0]['message'];
                    }
                }
                $batch = [];
            };
            $seen = [];
            while (($row = fgetcsv($h)) !== false) {
                $id  = trim($row[$map['File ID']] ?? '');
                $alt = trim($row[$map['Alt Text']] ?? '');
                if (!preg_match('#^gid://shopify/MediaImage/\d+$#', $id) || isset($seen[$id])) {
                    $skipped++;
                    continue;
                }
                $seen[$id] = true;
                $batch[] = ['id' => $id, 'alt' => mb_substr($alt, 0, 512)];
                if (count($batch) >= 25) {
                    $flush();
                }
            }
            $flush();
            fclose($h);
            $notice = "Pushed alt text for <strong>{$pushed}</strong> image(s) to " . htmlspecialchars($shopCfg['name']) . ".";
            if ($skipped) {
                $notice .= " {$skipped} row(s) skipped (invalid or duplicate File ID).";
            }
            if ($failed) {
                $notice .= " {$failed} failed: " . htmlspecialchars($firstErr);
                $noticeType = 'warning';
            }
            if (function_exists('recordUserLog')) {
                recordUserLog('Image Import', 'Image', "Pushed {$pushed} alt text update(s) to {$shopCfg['name']}; skipped {$skipped}; failed {$failed}.", 'image', null, $failed ? 'warning' : 'success');
            }
        }
    }
}

// AJAX: update alt text
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_alt') {
    header('Content-Type: application/json');
    if (!hash_equals($_SESSION['img_csrf'], $_POST['csrf'] ?? '')) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Invalid CSRF token.']);
        exit;
    }
    $fileId = $_POST['file_id'] ?? '';
    $alt    = trim((string)($_POST['alt'] ?? ''));
    if (!preg_match('#^gid://shopify/MediaImage/\d+$#', $fileId)) {
        echo json_encode(['ok' => false, 'error' => 'Invalid file ID.']);
        exit;
    }
    $m = 'mutation($files:[FileUpdateInput!]!){fileUpdate(files:$files){files{id alt}userErrors{field message}}}';
    $r = shopifyGraphQLRequest($m, ['files' => [['id' => $fileId, 'alt' => $alt]]], $activeStore);
    $errs = $r['data']['data']['fileUpdate']['userErrors'] ?? [];
    if ($r['status'] !== 200 || !empty($r['data']['errors']) || $errs) {
        $msg = $errs[0]['message'] ?? ($r['data']['errors'][0]['message'] ?? ($r['error'] ?: 'Shopify request failed.'));
        echo json_encode(['ok' => false, 'error' => $msg]);
        exit;
    }
    if (function_exists('recordUserLog')) {
        recordUserLog('Update Alt Text', 'Image', "Updated alt text for {$fileId}.", 'image', null, 'success');
    }
    echo json_encode(['ok' => true]);
    exit;
}

// List files
$search  = trim($_GET['q'] ?? '');
$page    = max(1, (int)($_GET['page'] ?? 1));
$pgKey   = 'img_cursors_' . $activeStore . '_' . md5(trim($_GET['q'] ?? ''));
$cursors = $_SESSION[$pgKey] ?? [];
$after   = $page > 1 ? ($cursors[$page] ?? null) : null;
if ($page > 1 && $after === null) {
    $page = 1;
}
$refresh = isset($_GET['refresh']);
$error   = '';
$debug   = '';
$images  = [];
$pageInfo = ['hasNextPage' => false, 'endCursor' => null];

$variants = fileQueryVariants($search);
$vIdx     = $page > 1 ? min((int)($_SESSION[$pgKey . '_v'] ?? 0), count($variants) - 1) : 0;
$searchQuery = $variants[$vIdx];

$q = 'query($after:String,$query:String){files(first:25,after:$after,query:$query,sortKey:CREATED_AT,reverse:true){
        pageInfo{hasNextPage endCursor}
        nodes{id alt createdAt fileStatus
          ... on MediaImage{image{url width height} originalSource{fileSize}}}}}';
$r = shopifyGraphQLRequest($q, ['after' => $after ?: null, 'query' => $searchQuery], $activeStore);
$files = $r['data']['data']['files'] ?? null;
if ($page === 1) {
    while ($files && !$files['nodes'] && $vIdx + 1 < count($variants)) {
        $vIdx++;
        $r = shopifyGraphQLRequest($q, ['after' => null, 'query' => $variants[$vIdx]], $activeStore);
        $files = $r['data']['data']['files'] ?? null;
    }
    $_SESSION[$pgKey . '_v'] = $vIdx;
}
if (!$files) {
    $error = $r['data']['errors'][0]['message'] ?? ($r['error'] ?: 'Unable to load files from Shopify (check API scopes read_files/write_files).');
    $sc = shopifyGraphQLRequest('{currentAppInstallation{accessScopes{handle}}}', [], $activeStore);
    $granted = array_column($sc['data']['data']['currentAppInstallation']['accessScopes'] ?? [], 'handle');
    $debug = 'Store: ' . ($shopCfg['url'] ?? '') . ' | API version: ' . ($shopCfg['version'] ?? '')
        . ' | Token has read_files: ' . (in_array('read_files', $granted, true) ? 'yes' : 'NO')
        . ' | write_files: ' . (in_array('write_files', $granted, true) ? 'yes' : 'NO')
        . ' | Granted scopes: ' . ($granted ? implode(', ', $granted) : ($sc['data']['errors'][0]['message'] ?? 'unavailable'));
} else {
    $pageInfo = $files['pageInfo'];
    if (!empty($pageInfo['hasNextPage'])) {
        $cursors[$page + 1] = $pageInfo['endCursor'];
    }
    $_SESSION[$pgKey] = $cursors;
    $usage    = buildUsageMap($activeStore, $storeDomain, $refresh);
    foreach ($files['nodes'] as $f) {
        if (empty($f['image']['url'])) {
            continue;
        }
        $f['usage'] = array_merge($usage[$f['id']] ?? [], $usage[imgKey($f['image']['url'])] ?? []);
        $images[] = $f;
    }
}

$pageTitle = 'Image Manager';
include __DIR__ . '/../includes/header.php';
include __DIR__ . '/../includes/sidebar.php';
?>

<div class="content-wrapper">
  <div class="content-header">
    <div class="container-fluid">
      <div class="row mb-2 align-items-center">
        <div class="col-sm-6">
          <h1 class="m-0 font-weight-bold" style="color: #003087;">Image Manager</h1>
          <p class="text-muted small mb-0">Browse store images, see where they are used, and edit alt text.</p>
        </div>
        <div class="col-sm-6">
          <div class="row justify-content-end">
            <div class="col-md-4 mb-2 mb-md-0">
              <div class="card h-100 mb-0 shadow-sm border-0" style="border-top: 4px solid #007bff !important; border-radius: 8px;">
                <div class="card-body p-2">
                  <div class="small font-weight-bold text-dark"><i class="fas fa-plug text-primary mr-1"></i>Test Connection</div>
                  <div class="small text-muted mb-2">Verify Shopify API access.</div>
                  <form method="POST">
                    <input type="hidden" name="csrf" value="<?php echo $_SESSION['img_csrf']; ?>">
                    <input type="hidden" name="action" value="test_connection">
                    <button type="submit" class="btn btn-sm btn-primary btn-block font-weight-bold">Run Test</button>
                  </form>
                </div>
              </div>
            </div>
            <div class="col-md-4 mb-2 mb-md-0">
              <div class="card h-100 mb-0 shadow-sm border-0" style="border-top: 4px solid #eab308 !important; border-radius: 8px;">
                <div class="card-body p-2">
                  <div class="small font-weight-bold text-dark"><i class="fas fa-sync-alt text-warning mr-1"></i>Sync Images</div>
                  <div class="small text-muted mb-2">Refresh where images are used.</div>
                  <form method="POST">
                    <input type="hidden" name="csrf" value="<?php echo $_SESSION['img_csrf']; ?>">
                    <input type="hidden" name="action" value="sync_images">
                    <button type="submit" class="btn btn-sm btn-warning btn-block font-weight-bold">Sync Now</button>
                  </form>
                </div>
              </div>
            </div>
            <div class="col-md-4">
              <div class="card h-100 mb-0 shadow-sm border-0" style="border-top: 4px solid #16a34a !important; border-radius: 8px;">
                <div class="card-body p-2">
                  <div class="small font-weight-bold text-dark"><i class="fas fa-layer-group text-success mr-1"></i>Bulk Updates</div>
                  <div class="small text-muted mb-2">Import pushes alt text live.</div>
                  <div class="d-flex flex-wrap">
                    <form method="POST" class="mr-1 mb-1">
                      <input type="hidden" name="csrf" value="<?php echo $_SESSION['img_csrf']; ?>">
                      <input type="hidden" name="action" value="export_images">
                      <input type="hidden" name="q" value="<?php echo htmlspecialchars($search); ?>">
                      <button type="submit" class="btn btn-sm btn-outline-secondary font-weight-bold" title="Export images and alt text"><i class="fas fa-file-export mr-1"></i>Export</button>
                    </form>
                    <form method="POST" enctype="multipart/form-data" class="mr-1 mb-1" onsubmit="return confirm('Import will push alt text to the live store immediately. Continue?');">
                      <input type="hidden" name="csrf" value="<?php echo $_SESSION['img_csrf']; ?>">
                      <input type="hidden" name="action" value="import_images">
                      <label class="btn btn-sm btn-outline-secondary font-weight-bold mb-0" title="Import alt text and push to Shopify"><i class="fas fa-file-import mr-1"></i>Import<input type="file" name="images_csv" accept=".csv,text/csv" class="d-none" onchange="if(this.form.onsubmit()){this.form.submit()}else{this.value=''}"></label>
                    </form>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>

      <form method="GET" class="form-inline mb-3">
        <input type="text" name="q" value="<?php echo htmlspecialchars($search); ?>" class="form-control form-control-sm mr-2" placeholder="Search file name">
        <button class="btn btn-sm btn-primary mr-2" type="submit">Search</button>
        <a class="btn btn-sm btn-outline-secondary" href="?refresh=1&q=<?php echo urlencode($search); ?>" title="Rebuild the usage cache">Refresh usage</a>
      </form>

      <?php if ($notice): ?>
        <div class="alert alert-<?php echo $noticeType; ?>"><?php echo $notice; ?></div>
      <?php endif; ?>

      <?php if ($error): ?>
        <div class="alert alert-danger"><?php echo htmlspecialchars($error); ?>
          <?php if ($debug): ?><div class="small mt-2"><?php echo htmlspecialchars($debug); ?></div><?php endif; ?>
        </div>
      <?php endif; ?>

      <div class="card shadow-sm">
        <div class="card-body p-0 table-responsive">
          <table class="table table-hover mb-0 align-middle">
            <thead class="thead-light">
              <tr>
                <th style="width:90px">Image</th>
                <th>File</th>
                <th style="width:34%">Alt text</th>
                <th style="width:26%">Used in</th>
              </tr>
            </thead>
            <tbody>
            <?php if (!$images && !$error): ?>
              <tr><td colspan="4" class="text-center text-muted py-4">No images found.</td></tr>
            <?php endif; ?>
            <?php foreach ($images as $img):
                $name = basename(parse_url($img['image']['url'], PHP_URL_PATH));
                $size = $img['originalSource']['fileSize'] ?? null;
            ?>
              <tr>
                <td><a href="<?php echo htmlspecialchars($img['image']['url']); ?>" target="_blank" rel="noopener"><img src="<?php echo htmlspecialchars($img['image']['url']); ?>&width=120" loading="lazy" style="width:80px;height:80px;object-fit:cover;border-radius:6px;border:1px solid #ddd" alt=""></a></td>
                <td>
                  <div class="font-weight-bold"><?php echo htmlspecialchars($name); ?></div>
                  <div class="small text-muted">
                    <?php echo (int)$img['image']['width']; ?>×<?php echo (int)$img['image']['height']; ?>
                    <?php if ($size): ?> · <?php echo round($size / 1024); ?> KB<?php endif; ?>
                    · <?php echo htmlspecialchars(substr($img['createdAt'], 0, 10)); ?>
                  </div>
                </td>
                <td>
                  <div class="input-group input-group-sm">
                    <textarea class="form-control alt-input" rows="2" maxlength="512" data-id="<?php echo htmlspecialchars($img['id']); ?>"><?php echo htmlspecialchars($img['alt'] ?? ''); ?></textarea>
                    <div class="input-group-append">
                      <button type="button" class="btn btn-success save-alt">Save</button>
                    </div>
                  </div>
                  <div class="small alt-status mt-1"></div>
                </td>
                <td>
                  <?php if (!$img['usage']): ?>
                    <span class="text-muted small">No product/collection/article reference found</span>
                  <?php else: foreach ($img['usage'] as $u): ?>
                    <div class="small">
                      <span class="badge badge-info"><?php echo htmlspecialchars($u['type']); ?></span>
                      <a href="<?php echo htmlspecialchars($u['url']); ?>" target="_blank" rel="noopener"><?php echo htmlspecialchars($u['title']); ?></a>
                    </div>
                  <?php endforeach; endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <?php
          $lastPage = $page + (!empty($pageInfo['hasNextPage']) ? 1 : 0);
          $lastPage = max($lastPage, $cursors ? max(array_keys($cursors)) : 1);
          $pageUrl  = function (int $n) use ($search) {
              return '?q=' . urlencode($search) . '&page=' . $n;
          };
        ?>
        <div class="card-footer">
          <ul class="pagination pagination-sm mb-0 justify-content-center flex-wrap">
            <li class="page-item <?php echo $page <= 1 ? 'disabled' : ''; ?>"><a class="page-link" href="<?php echo $pageUrl(max(1, $page - 1)); ?>">&laquo;</a></li>
            <?php for ($n = 1; $n <= $lastPage; $n++): ?>
              <li class="page-item <?php echo $n === $page ? 'active' : ''; ?>"><a class="page-link" href="<?php echo $pageUrl($n); ?>"><?php echo $n; ?></a></li>
            <?php endfor; ?>
            <li class="page-item <?php echo empty($pageInfo['hasNextPage']) ? 'disabled' : ''; ?>"><a class="page-link" href="<?php echo $pageUrl($page + 1); ?>">&raquo;</a></li>
          </ul>
        </div>
      </div>
    </div>
  </div>
</div>

<script>
document.querySelectorAll('.save-alt').forEach(function (btn) {
  btn.addEventListener('click', async function () {
    var row = btn.closest('td');
    var input = row.querySelector('.alt-input');
    var status = row.querySelector('.alt-status');
    var body = new URLSearchParams({
      action: 'update_alt',
      csrf: <?php echo json_encode($_SESSION['img_csrf']); ?>,
      file_id: input.dataset.id,
      alt: input.value
    });
    btn.disabled = true;
    status.className = 'small alt-status mt-1 text-muted';
    status.textContent = 'Saving...';
    try {
      var res = await fetch(location.pathname, { method: 'POST', body: body });
      var data = await res.json();
      status.className = 'small alt-status mt-1 ' + (data.ok ? 'text-success' : 'text-danger');
      status.textContent = data.ok ? 'Saved.' : (data.error || 'Failed.');
    } catch (e) {
      status.className = 'small alt-status mt-1 text-danger';
      status.textContent = 'Request failed.';
    }
    btn.disabled = false;
  });
});
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
