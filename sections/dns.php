<?php
/** @var SimpleXMLElement $cfg @var Resolve $R */
$T = fn($n) => Resolve::text($n);
$u = $cfg->unbound;

$custom = $T($u->custom_options);
$decoded = '';
if ($custom !== '') {
    $try = base64_decode($custom, true);
    $decoded = ($try !== false && $try !== '' && mb_check_encoding($try, 'UTF-8')) ? $try : $custom;
}

echo '<h3>DNS Resolver (unbound)</h3>';
echo Render::kv(array_filter([
    'Enabled'             => Render::yesNo(isset($u->enable)),
    'DNSSEC'              => Render::yesNo(isset($u->dnssec)),
    'Listening on'        => $T($u->active_interface) !== '' ? $R->ifList($T($u->active_interface)) : '<em>all</em>',
    'Outgoing via'        => $T($u->outgoing_interface) !== '' ? $R->ifList($T($u->outgoing_interface)) : '<em>all</em>',
    'Port'                => $T($u->port) !== '' ? Render::esc($T($u->port)) : '<span class="empty">53 (default)</span>',
    'DNS over TLS port'   => $T($u->tlsport) !== '' ? Render::esc($T($u->tlsport)) : null,
    'SSL certificate'     => $T($u->sslcertref) !== '' ? $R->certName($T($u->sslcertref)) : null,
    'Hide identity'       => Render::yesNo(isset($u->hideidentity)),
    'Hide version'        => Render::yesNo(isset($u->hideversion)),
    'Local zone type'     => $T($u->system_domain_local_zone_type) !== '' ? Render::esc($T($u->system_domain_local_zone_type)) : null,
    'Custom options'      => $decoded !== '' ? '<pre>' . Render::esc($decoded) . '</pre>' : null,
], fn($v) => $v !== null));

/* Host and domain overrides */
$rows = [];
foreach ($u->hosts ?? [] as $h) {
    $rows[] = [
        Render::val($T($h->host)),
        Render::val($T($h->domain)),
        '<code>' . Render::val($T($h->ip)) . '</code>',
        Render::val($T($h->descr), '—'),
    ];
}
if ($rows) {
    echo '<h3>Host overrides</h3>';
    echo Render::table(['Host', 'Domain', 'IP', 'Description'], $rows);
}

$rows = [];
foreach ($u->domainoverrides ?? [] as $d) {
    $rows[] = [
        Render::val($T($d->domain)),
        '<code>' . Render::val($T($d->ip)) . '</code>',
        Render::val($T($d->descr), '—'),
    ];
}
if ($rows) {
    echo '<h3>Domain overrides</h3>';
    echo Render::table(['Domain', 'Forward to', 'Description'], $rows);
}

/* DNS forwarder */
if (!Render::isEmptyNode($cfg->dnsmasq)) {
    echo '<h3>DNS Forwarder (dnsmasq)</h3>';
    echo Render::dump($cfg->dnsmasq, 0, 'dnsmasq');
} else {
    echo '<h3>DNS Forwarder (dnsmasq)</h3><p class="empty">Not configured &mdash; the resolver above handles DNS.</p>';
}

/* System-level DNS */
echo '<h3>System DNS servers</h3>';
$rows = [];
$gws = [$T($cfg->system->dns1gw), $T($cfg->system->dns2gw), $T($cfg->system->dns3gw), $T($cfg->system->dns4gw)];
$idx = 0;
foreach ($cfg->system->dnsserver ?? [] as $d) {
    $ip = trim((string) $d);
    if ($ip === '') { $idx++; continue; }
    $rows[] = [
        '<code>' . Render::esc($ip) . '</code>',
        ($gws[$idx] ?? '') !== '' && ($gws[$idx] ?? '') !== 'none'
            ? Render::esc($gws[$idx])
            : '<span class="empty">any gateway</span>',
    ];
    $idx++;
}
echo Render::table(['Server', 'Reached via'], $rows);
echo '<p class="note">Both upstream servers are pinned to <code>WAN_PPPOE</code>, so they are queried over the KPN link even when the failover group switches.</p>';
