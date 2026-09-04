<?php
declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '1');

require __DIR__ . '/lib/Render.php';
require __DIR__ . '/lib/Secrets.php';
require __DIR__ . '/lib/Resolve.php';
require __DIR__ . '/lib/Loader.php';
require __DIR__ . '/lib/Flatten.php';
require __DIR__ . '/lib/Diff.php';

$notice = null;
$noticeOk = false;

$mode      = (($_GET['mode'] ?? '') === 'diff') ? 'diff' : 'view';
$hideNoise = ($_GET['noise'] ?? '1') !== '0';

/* ---- Upload (available in both modes) ---- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['config'])) {
    $res = Loader::handleUpload($_FILES['config']);
    if ($res['ok']) {
        $new = basename($res['path']);
        if (($_POST['mode'] ?? '') === 'diff') {
            // Slot the new file into whichever side is still empty.
            $a = basename((string) ($_POST['a'] ?? ''));
            $b = basename((string) ($_POST['b'] ?? ''));
            if ($a === '') {
                $a = $new;
            } else {
                $b = $new;
            }
            $q = ['mode' => 'diff', 'a' => $a, 'b' => $b, 'uploaded' => 1];
        } else {
            $q = ['file' => $new, 'uploaded' => 1];
        }
        header('Location: ?' . http_build_query($q));
        exit;
    }
    $notice = $res['message'];
}
if (isset($_GET['uploaded'])) {
    $notice = 'Config uploaded.';
    $noticeOk = true;
}

$configs = Loader::listConfigs();

/** Find a stored config by its filename. */
$find = function (?string $name) use ($configs) {
    if ($name === null || $name === '') {
        return null;
    }
    $name = basename($name);
    foreach ($configs as $c) {
        if ($c['name'] === $name) {
            return $c;
        }
    }
    return null;
};

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

/* view mode state */
$cfg = null;
$rrd = ['present' => false, 'datasets' => 0, 'bytes' => 0];
$loadError = null;
$selected = null;
$counts = [];

/* diff mode state */
$fileA = null;
$fileB = null;
$diff = null;
$diffError = null;

if ($mode === 'view') {
    $selected = $find($_GET['file'] ?? null);
    if ($selected === null && isset($_GET['file'])) {
        $notice = 'That config is no longer available.';
    }
    if ($selected === null && $configs) {
        $selected = $configs[0];
    }

    if ($selected !== null) {
        try {
            $parsed = Loader::load($selected['path']);
            $cfg = $parsed['xml'];
            $rrd = $parsed['rrd'];
        } catch (Throwable $e) {
            $loadError = $e->getMessage();
        }
    }

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
} else {
    $fileA = $find($_GET['a'] ?? null);
    $fileB = $find($_GET['b'] ?? null);

    // With nothing chosen yet, default to the two most recent uploads.
    if ($fileA === null && $fileB === null && count($configs) >= 2) {
        $fileA = $configs[1];
        $fileB = $configs[0];
    }

    if ($fileA !== null && $fileB !== null) {
        if ($fileA['name'] === $fileB['name']) {
            $diffError = 'Both sides point at the same file. Pick two different configs.';
        } else {
            try {
                $pa = Loader::load($fileA['path']);
                $pb = Loader::load($fileB['path']);
                $diff = Diff::compare(
                    Flatten::run($pa['xml']),
                    Flatten::run($pb['xml']),
                    $hideNoise
                );
            } catch (Throwable $e) {
                $diffError = $e->getMessage();
            }
        }
    }
}

$pageTitle = 'pfSense Config Viewer';
if ($mode === 'diff') {
    $pageTitle = 'Compare configs';
} elseif ($selected) {
    $pageTitle = $selected['hostname'] !== '' ? $selected['hostname'] : $selected['label'];
}

/** Build a URL from the current query string plus overrides (null removes). */
function qs(array $overrides): string
{
    $base = [
        'mode'  => $_GET['mode']  ?? null,
        'file'  => $_GET['file']  ?? null,
        'a'     => $_GET['a']     ?? null,
        'b'     => $_GET['b']     ?? null,
        'noise' => $_GET['noise'] ?? null,
    ];
    $merged = array_merge($base, $overrides);
    $clean = array_filter($merged, fn($v) => $v !== null && $v !== '');
    return '?' . http_build_query($clean);
}
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
      <?php if ($mode === 'view' && $selected): ?>
        <div class="brand-sub">
          <?= Render::esc($selected['label']) ?>
          &middot; pfSense <?= Render::esc($selected['version'] ?: '?') ?>
          &middot; <?= Render::esc(Render::bytes($selected['size'])) ?>
          <?php if ($selected['revision']): ?>
            &middot; saved <?= Render::esc(date('Y-m-d H:i', $selected['revision'])) ?>
          <?php endif; ?>
        </div>
      <?php elseif ($mode === 'diff'): ?>
        <div class="brand-sub"><?= count($configs) ?> config<?= count($configs) === 1 ? '' : 's' ?> stored</div>
      <?php endif; ?>
    </div>
  </div>

  <div class="topbar-tools">
    <div class="modeswitch">
      <a class="<?= $mode === 'view' ? 'on' : '' ?>" href="<?= Render::esc(qs(['mode' => null, 'a' => null, 'b' => null, 'noise' => null])) ?>">View</a>
      <a class="<?= $mode === 'diff' ? 'on' : '' ?>" href="<?= Render::esc(qs(['mode' => 'diff', 'file' => null])) ?>">Compare</a>
    </div>

    <input type="search" id="search" placeholder="Filter everything…" autocomplete="off" spellcheck="false">

    <?php if ($mode === 'view' && $configs): ?>
      <form method="get" class="picker">
        <select name="file" onchange="this.form.submit()">
          <?php foreach ($configs as $c): ?>
            <option value="<?= Render::esc($c['name']) ?>" <?= ($selected && $c['name'] === $selected['name']) ? 'selected' : '' ?>>
              <?= Render::esc($c['hostname']) ?><?= $c['revision'] ? ' — ' . Render::esc(date('Y-m-d', $c['revision'])) : '' ?>
            </option>
          <?php endforeach; ?>
        </select>
      </form>
    <?php endif; ?>

    <form method="post" enctype="multipart/form-data" class="uploader">
      <input type="hidden" name="mode" value="<?= Render::esc($mode) ?>">
      <input type="hidden" name="a" value="<?= Render::esc($fileA['name'] ?? '') ?>">
      <input type="hidden" name="b" value="<?= Render::esc($fileB['name'] ?? '') ?>">
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

<?php if (!$configs): ?>
  <main class="empty-state">
    <h1>No configs yet</h1>
    <p>Upload a pfSense backup <code>.xml</code> with the button above. Uploaded configs are kept in
       <code>uploads/</code>, which the web server is told not to serve — they are only ever read by
       this page.</p>
    <p>Upload two or more and the <strong>Compare</strong> tab will diff them.</p>
  </main>

<?php elseif ($mode === 'diff'): ?>

  <div class="layout layout-wide">
    <main>
      <section class="section" id="diff">
        <h2>Compare two configs</h2>

        <form method="get" class="diff-picker">
          <input type="hidden" name="mode" value="diff">
          <div class="diff-picker-row">
            <label>
              <span class="diff-tag">A</span>
              <select name="a">
                <option value="">— pick a config —</option>
                <?php foreach ($configs as $c): ?>
                  <option value="<?= Render::esc($c['name']) ?>" <?= ($fileA && $c['name'] === $fileA['name']) ? 'selected' : '' ?>>
                    <?= Render::esc($c['hostname']) ?><?= $c['revision'] ? ' — ' . Render::esc(date('Y-m-d H:i', $c['revision'])) : '' ?> (<?= Render::esc($c['label']) ?>)
                  </option>
                <?php endforeach; ?>
              </select>
            </label>

            <label>
              <span class="diff-tag diff-tag-b">B</span>
              <select name="b">
                <option value="">— pick a config —</option>
                <?php foreach ($configs as $c): ?>
                  <option value="<?= Render::esc($c['name']) ?>" <?= ($fileB && $c['name'] === $fileB['name']) ? 'selected' : '' ?>>
                    <?= Render::esc($c['hostname']) ?><?= $c['revision'] ? ' — ' . Render::esc(date('Y-m-d H:i', $c['revision'])) : '' ?> (<?= Render::esc($c['label']) ?>)
                  </option>
                <?php endforeach; ?>
              </select>
            </label>

            <label class="check">
              <input type="checkbox" name="noise" value="0" <?= $hideNoise ? '' : 'checked' ?>>
              Show timestamp / revision bookkeeping
            </label>

            <button type="submit" class="btn">Compare</button>
            <?php if ($fileA && $fileB): ?>
              <a class="btn btn-ghost btn-inline"
                 href="<?= Render::esc(qs(['a' => $fileB['name'], 'b' => $fileA['name']])) ?>">Swap A / B</a>
            <?php endif; ?>
          </div>
        </form>

        <?php if ($diffError): ?>
          <p class="notice notice-bad"><?= Render::esc($diffError) ?></p>
        <?php elseif (count($configs) < 2 && !($fileA && $fileB)): ?>
          <p class="lead" style="margin-top:16px">Only one config is stored. Upload another to compare against it.</p>
        <?php elseif (!$fileA || !$fileB): ?>
          <p class="lead" style="margin-top:16px">Pick a config for each side, then press Compare.</p>
        <?php elseif ($diff !== null): ?>
          <?php include __DIR__ . '/sections/diff.php'; ?>
        <?php endif; ?>
      </section>

      <footer class="foot">
        Entries are matched by identity — a firewall rule's tracker, an alias's name, a certificate's
        ref ID — rather than by position, so inserting one rule does not mark everything below it as changed.
      </footer>
    </main>
  </div>

<?php else: ?>

  <?php if ($loadError): ?>
    <div class="notice notice-bad"><?= Render::esc($loadError) ?></div>
  <?php endif; ?>

  <?php if ($cfg !== null): ?>
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
        Rendered from <code><?= Render::esc($selected['label']) ?></code>.
        This viewer is read-only and never writes back to the config.
      </footer>
    </main>
  </div>
  <?php endif; ?>

<?php endif; ?>

<div id="no-results" class="no-results" hidden>Nothing matches that filter.</div>
<script src="assets/app.js"></script>
</body>
</html>
