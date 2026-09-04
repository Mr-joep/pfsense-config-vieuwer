<?php
/**
 * @var array $diff      result of Diff::compare()
 * @var array $fileA     config metadata (left)
 * @var array $fileB     config metadata (right)
 * @var bool  $hideNoise
 */
$stats = $diff['stats'];
$grouped = Diff::bySection($diff['entries']);

$titles = [
    'version' => 'Config version', 'system' => 'System', 'interfaces' => 'Interfaces',
    'vlans' => 'VLANs', 'laggs' => 'Link aggregation', 'ppps' => 'PPP / PPPoE',
    'ifgroups' => 'Interface groups', 'bridges' => 'Bridges', 'filter' => 'Firewall rules',
    'nat' => 'NAT', 'aliases' => 'Aliases', 'dhcpd' => 'DHCP', 'dhcpdv6' => 'DHCPv6',
    'dhcpbackend' => 'DHCP backend', 'unbound' => 'DNS Resolver', 'dnsmasq' => 'DNS Forwarder',
    'gateways' => 'Gateways', 'staticroutes' => 'Static routes', 'openvpn' => 'OpenVPN',
    'ovpnserver' => 'OpenVPN wizard state', 'ipsec' => 'IPsec', 'cert' => 'Certificates',
    'ca' => 'Certificate authorities', 'crl' => 'Revocation lists', 'cron' => 'Scheduled jobs',
    'installedpackages' => 'Packages', 'revision' => 'Revision', 'widgets' => 'Dashboard widgets',
    'snmpd' => 'SNMP', 'syslog' => 'Syslog', 'rrd' => 'RRD settings', 'diag' => 'Diagnostics',
    'ntpd' => 'NTP', 'lastchange' => 'Last change',
];

$kindBadge = [
    Diff::CHANGED => ['changed', 'warn'],
    Diff::ADDED   => ['only in B', 'ok'],
    Diff::REMOVED => ['only in A', 'bad'],
];

/** Render one side of a comparison. */
$side = function (?string $v, string $field): string {
    if ($v === null) {
        return '<span class="empty">absent</span>';
    }
    if ($v === '') {
        return '<span class="empty">(set, no value)</span>';
    }
    if (Secrets::isSecret($field)) {
        return Secrets::redact($v);
    }
    if (strlen($v) > 160) {
        return '<details><summary>' . Render::esc(Render::bytes(strlen($v))) . ' of data</summary>'
            . '<pre>' . Render::esc($v) . '</pre></details>';
    }
    return '<span class="mono">' . Render::esc($v) . '</span>';
};

/** Path shown as section-relative, with the identity key pulled out. */
$prettyPath = function (string $path): string {
    $rest = $path;
    if (preg_match('~^([a-zA-Z0-9_-]+)(.*)$~', $path, $m)) {
        $rest = $m[2];
    }
    $rest = ltrim($rest, '/');
    if ($rest === '') {
        $rest = $path;
    }
    $out = Render::esc($rest);
    // Highlight the [identity] portions so the entry is easy to spot.
    return preg_replace('~\[([^\]]*)\]~', '<span class="id-key">$1</span>', $out) ?? $out;
};
?>

<div class="diff-head">
  <div class="diff-side">
    <div class="diff-tag">A</div>
    <div>
      <div class="diff-name"><?= Render::esc($fileA['hostname']) ?></div>
      <div class="diff-sub">
        <?= Render::esc($fileA['label']) ?>
        <?php if ($fileA['revision']): ?> &middot; <?= Render::esc(date('Y-m-d H:i', $fileA['revision'])) ?><?php endif; ?>
        &middot; pfSense <?= Render::esc($fileA['version'] ?: '?') ?>
      </div>
    </div>
  </div>
  <div class="diff-arrow">&rarr;</div>
  <div class="diff-side">
    <div class="diff-tag diff-tag-b">B</div>
    <div>
      <div class="diff-name"><?= Render::esc($fileB['hostname']) ?></div>
      <div class="diff-sub">
        <?= Render::esc($fileB['label']) ?>
        <?php if ($fileB['revision']): ?> &middot; <?= Render::esc(date('Y-m-d H:i', $fileB['revision'])) ?><?php endif; ?>
        &middot; pfSense <?= Render::esc($fileB['version'] ?: '?') ?>
      </div>
    </div>
  </div>
</div>

<div class="diff-stats">
  <span class="stat stat-warn"><b><?= (int) $stats['changed'] ?></b> changed</span>
  <span class="stat stat-ok"><b><?= (int) $stats['added'] ?></b> only in B</span>
  <span class="stat stat-bad"><b><?= (int) $stats['removed'] ?></b> only in A</span>
  <span class="stat"><b><?= (int) $stats['same'] ?></b> identical</span>
  <?php if ($stats['noise']): ?>
    <span class="stat"><b><?= (int) $stats['noise'] ?></b> timestamp/bookkeeping
      <?= $hideNoise ? 'hidden' : 'shown' ?></span>
  <?php endif; ?>
</div>

<?php if (!$diff['entries']): ?>
  <p class="lead" style="margin-top:16px">
    <?= $stats['noise'] && $hideNoise
        ? 'No differences apart from timestamps and revision bookkeeping. Untick &ldquo;hide bookkeeping&rdquo; above to see those.'
        : 'These two configs are identical.' ?>
  </p>
<?php else: ?>

  <?php foreach ($grouped as $section => $entries):
      $counts = ['changed' => 0, 'added' => 0, 'removed' => 0];
      foreach ($entries as $e) { $counts[$e['kind']]++; }
  ?>
    <h3 class="iface-head">
      <strong><?= Render::esc($titles[$section] ?? $section) ?></strong>
      <small class="muted"><?= Render::esc($section) ?></small>
      <span class="count"><?= count($entries) ?></span>
      <?php if ($counts['changed']): ?><span class="badge badge-warn"><?= $counts['changed'] ?> changed</span><?php endif; ?>
      <?php if ($counts['added']): ?><span class="badge badge-ok"><?= $counts['added'] ?> added</span><?php endif; ?>
      <?php if ($counts['removed']): ?><span class="badge badge-bad"><?= $counts['removed'] ?> removed</span><?php endif; ?>
    </h3>
    <?php
    $rows = [];
    foreach ($entries as $e) {
        [$label, $kind] = $kindBadge[$e['kind']];
        $rows[] = [
            '_class' => 'diff-' . $e['kind'],
            Render::badge($label, $kind),
            '<span class="path">' . $prettyPath($e['path']) . '</span>',
            $side($e['a'], $e['field']),
            $side($e['b'], $e['field']),
        ];
    }
    echo Render::table(['', 'Setting', 'A', 'B'], $rows, 'diff');
    ?>
  <?php endforeach; ?>

<?php endif; ?>
