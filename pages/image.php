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

if (($_SESSION['user_role'] ?? 'editor') !== 'admin') {
    header("Location: dashboard.php");
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
$after   = $_GET['after'] ?? null;
$refresh = isset($_GET['refresh']);
$error   = '';
$debug   = '';
$images  = [];
$pageInfo = ['hasNextPage' => false, 'endCursor' => null];

$searchQuery = 'media_type:IMAGE';
if ($search !== '') {
    $searchQuery .= ' AND filename:' . '"' . str_replace(['"', '\\'], '', $search) . '*"';
}

$q = 'query($after:String,$query:String){files(first:25,after:$after,query:$query,sortKey:CREATED_AT,reverse:true){
        pageInfo{hasNextPage endCursor}
        nodes{id alt createdAt fileStatus
          ... on MediaImage{image{url width height} originalSource{fileSize}}}}}';
$r = shopifyGraphQLRequest($q, ['after' => $after ?: null, 'query' => $searchQuery], $activeStore);
$files = $r['data']['data']['files'] ?? null;
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
          <form method="GET" class="form-inline justify-content-sm-end">
            <input type="text" name="q" value="<?php echo htmlspecialchars($search); ?>" class="form-control form-control-sm mr-2" placeholder="Search file name">
            <button class="btn btn-sm btn-primary mr-2" type="submit">Search</button>
            <a class="btn btn-sm btn-outline-secondary" href="?refresh=1&q=<?php echo urlencode($search); ?>" title="Rebuild the usage cache">Refresh usage</a>
          </form>
        </div>
      </div>

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
        <div class="card-footer d-flex justify-content-between">
          <a class="btn btn-sm btn-outline-secondary" href="?q=<?php echo urlencode($search); ?>">First page</a>
          <?php if (!empty($pageInfo['hasNextPage'])): ?>
            <a class="btn btn-sm btn-primary" href="?q=<?php echo urlencode($search); ?>&after=<?php echo urlencode($pageInfo['endCursor']); ?>">Next &raquo;</a>
          <?php endif; ?>
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
