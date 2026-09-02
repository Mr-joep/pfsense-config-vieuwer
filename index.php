<?php
declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '1');

require __DIR__ . '/lib/Render.php';
require __DIR__ . '/lib/Secrets.php';
require __DIR__ . '/lib/Resolve.php';
require __DIR__ . '/lib/Loader.php';

$notice = null;
$noticeOk = false;

/* ---- Upload ---- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['config'])) {
    $res = Loader::handleUpload($_FILES['config']);
    if ($res['ok']) {
        header('Location: ?file=' . urlencode(basename($res['path'])) . '&src=upload&uploaded=1');
        exit;
    }
    $notice = $res['message'];
}
if (isset($_GET['uploaded'])) {
    $notice = 'Config uploaded and loaded.';
    $noticeOk = true;
}

/* ---- Pick a config ---- */
$configs = Loader::listConfigs();
$selected = null;

if (isset($_GET['file'])) {
    $want = basename((string) $_GET['file']);
    foreach ($configs as $c) {
        if ($c['name'] === $want) {
            $selected = $c;
            break;
        }
    }
    if ($selected === null) {
        $notice = 'That config file is no longer available.';
    }
}
if ($selected === null && $configs) {
    $selected = $configs[0];
}

$cfg = null;
$rrd = ['present' => false, 'datasets' => 0, 'bytes' => 0];
$loadError = null;

if ($selected !== null) {
    try {
        $parsed = Loader::load($selected['path']);
        $cfg = $parsed['xml'];
        $rrd = $parsed['rrd'];
    } catch (Throwable $e) {
        $loadError = $e->getMessage();
    }
}

$sections = [
    ['overview',   'Overview'],
    ['interfaces', 'Interfaces'],
    ['layer2',     'VLANs, LAGG &amp; PPPoE'],
    ['firewall',   'Firewall rules'],
    ['nat',        'NAT'],
    ['aliases',    'Aliases'],
    ['dhcp',       'DHCP'],
    ['dns',        'DNS'],
    ['gateways',   'Gateways &amp; routing'],
    ['vpn',        'VPN'],
    ['certs',      'Certificates'],
    ['users',      'Users &amp; access'],
    ['system',     'System settings'],
    ['packages',   'Packages'],
    ['cron',       'Scheduled jobs'],
    ['other',      'Everything else'],
];

$counts = [];
if ($cfg !== null) {
    $R = new Resolve($cfg);
    $R->prescan();

    $staticMaps = 0;
    foreach ($cfg->dhcpd->children() ?? [] as $scope) {
        $staticMaps += count($scope->staticmap ?? []);
    }

    $counts = [
        'interfaces' => count($R->interfaces()),
        'rules'      => count($cfg->filter->rule ?? []),
        'nat'        => count($cfg->nat->rule ?? []),
        'aliases'    => count($cfg->aliases->alias ?? []),
        'staticmaps' => $staticMaps,
        'gateways'   => count($cfg->gateways->gateway_item ?? []),
        'certs'      => count($cfg->cert ?? []) + count($cfg->ca ?? []),
        'users'      => count($cfg->system->user ?? []),
        'packages'   => count($cfg->installedpackages->package ?? []),
        'cron'       => count($cfg->cron->item ?? []),
        'vlans'      => count($cfg->vlans->vlan ?? []),
        'laggs'      => count($cfg->laggs->lagg ?? []),
    ];
}

$pageTitle = $selected
    ? ($selected['hostname'] !== '' ? $selected['hostname'] : $selected['name'])
    : 'pfSense Config Viewer';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= Render::esc($pageTitle) ?> — pfSense config</title>
<link rel="stylesheet" href="assets/app.css">
</head>
<body>

<header class="topbar">
  <div class="brand">
    <span class="logo">pf</span>
    <div>
      <div class="brand-name"><?= Render::esc($pageTitle) ?></div>
      <?php if ($selected): ?>
        <div class="brand-sub">
          <?= Render::esc($selected['name']) ?>
          &middot; pfSense <?= Render::esc($selected['version'] ?: '?') ?>
          &middot; <?= Render::esc(Render::bytes($selected['size'])) ?>
          <?php if ($selected['revision']): ?>
            &middot; saved <?= Render::esc(date('Y-m-d H:i', $selected['revision'])) ?>
          <?php endif; ?>
        </div>
      <?php endif; ?>
    </div>
  </div>

  <div class="topbar-tools">
    <input type="search" id="search" placeholder="Filter everything…" autocomplete="off" spellcheck="false">
    <form method="get" class="picker">
      <select name="file" onchange="this.form.submit()">
        <?php foreach ($configs as $c): ?>
          <option value="<?= Render::esc($c['name']) ?>" <?= ($selected && $c['name'] === $selected['name']) ? 'selected' : '' ?>>
            <?= Render::esc($c['hostname']) ?>
            <?= $c['revision'] ? '— ' . Render::esc(date('Y-m-d', $c['revision'])) : '' ?>
            (<?= Render::esc($c['source']) ?>)
          </option>
        <?php endforeach; ?>
      </select>
    </form>
    <form method="post" enctype="multipart/form-data" class="uploader">
      <label class="btn">
        Upload config
        <input type="file" name="config" accept=".xml,text/xml" onchange="this.form.submit()">
      </label>
    </form>
  </div>
</header>

<?php if ($notice): ?>
  <div class="notice <?= $noticeOk ? 'notice-ok' : 'notice-bad' ?>"><?= Render::esc($notice) ?></div>
<?php endif; ?>

<?php if ($cfg === null): ?>
  <main class="empty-state">
    <h1>No config loaded</h1>
    <?php if ($loadError): ?>
      <p class="notice notice-bad"><?= Render::esc($loadError) ?></p>
    <?php endif; ?>
    <p>Upload a pfSense backup <code>.xml</code> using the button above, or drop one into
       <code><?= Render::esc(Loader::scanDir()) ?></code> named <code>config*.xml</code>.</p>
  </main>
<?php else: ?>

<div class="layout">
  <nav class="sidenav">
    <ol>
      <?php foreach ($sections as [$id, $title]): ?>
        <li><a href="#<?= $id ?>" data-nav="<?= $id ?>"><?= $title ?></a></li>
      <?php endforeach; ?>
    </ol>
    <div class="sidenav-foot">
      <button type="button" id="expand-all" class="btn btn-ghost">Expand all</button>
      <button type="button" onclick="window.print()" class="btn btn-ghost">Print / PDF</button>
    </div>
  </nav>

  <main>
    <?php foreach ($sections as [$id, $title]): ?>
      <section id="<?= $id ?>" class="section">
        <h2><?= $title ?></h2>
        <?php
        $file = __DIR__ . '/sections/' . $id . '.php';
        if (is_file($file)) {
            try {
                include $file;
            } catch (Throwable $e) {
                echo '<p class="notice notice-bad">This section could not be rendered: '
                    . Render::esc($e->getMessage()) . '</p>';
            }
        }
        ?>
      </section>
    <?php endforeach; ?>

    <footer class="foot">
      Rendered from <code><?= Render::esc($selected['name']) ?></code>.
      This viewer is read-only and never writes back to the config.
    </footer>
  </main>
</div>

<?php endif; ?>

<div id="no-results" class="no-results" hidden>Nothing matches that filter.</div>
<script src="assets/app.js"></script>
</body>
</html>
