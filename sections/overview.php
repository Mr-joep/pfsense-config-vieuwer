<?php
/** @var SimpleXMLElement $cfg @var Resolve $R @var array $rrd @var array $counts */
$sys = $cfg->system;
$T = fn($n) => Resolve::text($n);

$host = $T($sys->hostname);
$domain = $T($sys->domain);

$dns = [];
foreach ($sys->dnsserver ?? [] as $d) {
    $ip = $T($d);
    if ($ip !== '') $dns[] = '<code>' . Render::esc($ip) . '</code>';
}
$dnsGw = array_filter([$T($sys->dns1gw), $T($sys->dns2gw)], fn($x) => $x !== '' && $x !== 'none');

$rev = $cfg->revision;
$defaultGw = $T($cfg->gateways->defaultgw4);
$defaultGw6 = $T($cfg->gateways->defaultgw6);
?>
<div class="cards">
  <div class="card">
    <h3>Firewall</h3>
    <?= Render::kv([
        'Hostname'      => '<strong>' . Render::val($host) . '</strong>' . ($domain !== '' ? ' <small class="muted">.' . Render::esc($domain) . '</small>' : ''),
        'pfSense version' => Render::val(Resolve::text($cfg->version)),
        'Theme / language' => Render::val($T($sys->language)),
        'Timezone'      => Render::val($T($sys->timezone)),
        'Time servers'  => Render::val($T($sys->timeservers)),
    ]) ?>
  </div>

  <div class="card">
    <h3>Last change</h3>
    <?= Render::kv([
        'When'        => Render::when($T($rev->time)),
        'By'          => Render::val($T($rev->username)),
        'Description' => Render::val($T($rev->description)),
    ]) ?>
  </div>

  <div class="card">
    <h3>Routing &amp; DNS</h3>
    <?= Render::kv([
        'Default gateway (v4)' => $defaultGw !== '' ? '<strong>' . Render::esc($defaultGw) . '</strong>' : '<span class="empty">automatic</span>',
        'Default gateway (v6)' => ($defaultGw6 !== '' && $defaultGw6 !== '-') ? Render::esc($defaultGw6) : '<span class="empty">none</span>',
        'DNS servers'          => $dns ? implode(' ', $dns) : '<span class="empty">none set</span>',
        'DNS via gateway'      => $dnsGw ? Render::esc(implode(', ', $dnsGw)) : '<span class="empty">any</span>',
        'Prefer IPv4'          => Render::yesNo(isset($sys->prefer_ipv4)),
    ]) ?>
  </div>
</div>

<h3>What is in this config</h3>
<div class="counts">
<?php
$map = [
    'Interfaces'      => ['interfaces', $counts['interfaces']],
    'Firewall rules'  => ['firewall', $counts['rules']],
    'NAT forwards'    => ['nat', $counts['nat']],
    'Aliases'         => ['aliases', $counts['aliases']],
    'DHCP static maps'=> ['dhcp', $counts['staticmaps']],
    'Gateways'        => ['gateways', $counts['gateways']],
    'Certificates'    => ['certs', $counts['certs']],
    'Users'           => ['users', $counts['users']],
    'Packages'        => ['packages', $counts['packages']],
    'Cron jobs'       => ['cron', $counts['cron']],
    'VLANs'           => ['layer2', $counts['vlans']],
    'LAGGs'           => ['layer2', $counts['laggs']],
];
foreach ($map as $label => [$anchor, $n]): ?>
  <a class="count-card" href="#<?= Render::esc($anchor) ?>">
    <span class="count-n"><?= (int) $n ?></span>
    <span class="count-l"><?= Render::esc($label) ?></span>
  </a>
<?php endforeach; ?>
</div>

<?php
$notes = [];

$undef = $R->undefinedInterfaces();
if ($undef) {
    $notes[] = 'Rules and package settings reference interfaces that have no entry in this backup: <code>'
        . implode('</code>, <code>', array_map([Render::class, 'esc'], $undef))
        . '</code>. Those are stale references — pfSense keeps them after an interface is removed.';
}
if ($rrd['present']) {
    $notes[] = 'This backup includes RRD graph data (' . Render::esc(Render::bytes($rrd['bytes']))
        . ', ' . (int) $rrd['datasets'] . ' datasets). It is base64 traffic-graph history and is not displayed here — '
        . 'it accounts for almost the entire file size.';
}
if (Resolve::text($cfg->snmpd->rocommunity) === 'public') {
    $notes[] = 'The SNMP read community string is <code>public</code>, the default value.';
}
$early = Resolve::text($cfg->system->earlyshellcmd);
if ($early !== '') {
    $notes[] = 'An <code>earlyshellcmd</code> runs at boot: <code>' . Render::esc($early) . '</code>';
}
if ($notes): ?>
<h3>Worth knowing</h3>
<ul class="notes">
  <?php foreach ($notes as $n): ?><li><?= $n ?></li><?php endforeach; ?>
</ul>
<?php endif; ?>
