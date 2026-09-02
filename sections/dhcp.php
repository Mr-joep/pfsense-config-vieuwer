<?php
/** @var SimpleXMLElement $cfg @var Resolve $R */
$T = fn($n) => Resolve::text($n);

$backend = $T($cfg->dhcpbackend) ?: 'isc';
echo Render::kv([
    'DHCP backend' => Render::badge(strtoupper($backend), 'info'),
]);

$allStatic = 0;
$scopes = 0;

foreach ($cfg->dhcpd->children() ?? [] as $key => $scope) {
    $key = (string) $key;
    // pfSense keeps a bookkeeping child here that is not an interface scope.
    $isScope = isset($scope->range) || isset($scope->staticmap) || isset($scope->enable);
    if (!$isScope) {
        continue;
    }
    $scopes++;

    $from = $T($scope->range->from ?? null);
    $to   = $T($scope->range->to ?? null);

    echo '<div class="scope">';
    echo '<h3 class="iface-head">' . $R->ifLabel($key)
        . ' ' . (isset($scope->enable) ? Render::badge('enabled', 'ok') : Render::badge('disabled', 'off')) . '</h3>';

    echo Render::kv(array_filter([
        'Pool range'      => ($from !== '' || $to !== '')
            ? '<code>' . Render::esc($from) . '</code> &ndash; <code>' . Render::esc($to) . '</code>'
            : '<span class="empty">no pool (static mappings only)</span>',
        'Gateway'         => $T($scope->gateway) !== '' ? '<code>' . Render::esc($T($scope->gateway)) . '</code>' : null,
        'DNS servers'     => implode(' ', array_map(
            fn($d) => '<code>' . Render::esc((string) $d) . '</code>',
            array_filter(iterator_to_array($scope->dnsserver ?? [], false), fn($d) => trim((string) $d) !== '')
        )) ?: null,
        'Domain'          => $T($scope->domain) !== '' ? Render::esc($T($scope->domain)) : null,
        'Default lease'   => $T($scope->defaultleasetime) !== '' ? Render::esc($T($scope->defaultleasetime)) . ' s' : null,
        'Max lease'       => $T($scope->maxleasetime) !== '' ? Render::esc($T($scope->maxleasetime)) . ' s' : null,
        'DNS registration'=> $T($scope->dnsregpolicy) !== '' ? Render::esc($T($scope->dnsregpolicy)) : null,
    ], fn($v) => $v !== null));

    $rows = [];
    foreach ($scope->staticmap ?? [] as $m) {
        $allStatic++;
        $rows[] = [
            '<strong>' . Render::val($T($m->hostname), 'no hostname') . '</strong>',
            '<code>' . Render::val($T($m->ipaddr)) . '</code>',
            '<code class="mac">' . Render::val($T($m->mac), 'by client-id') . '</code>',
            Render::val($T($m->cid), '—'),
            Render::val($T($m->descr), '—'),
        ];
    }
    if ($rows) {
        echo '<h4>Static mappings <span class="count">' . count($rows) . '</span></h4>';
        echo Render::table(['Hostname', 'IP address', 'MAC', 'Client ID', 'Description'], $rows);
    }
    echo '</div>';
}

echo '<p class="note">' . $scopes . ' DHCP scopes, ' . $allStatic
    . ' static mappings in total. Static mappings are the closest thing this backup has to a device inventory.</p>';

/* IPv6 */
$v6 = [];
foreach ($cfg->dhcpdv6->children() ?? [] as $key => $scope) {
    if (!isset($scope->range) && !isset($scope->enable)) {
        continue;
    }
    $v6[] = [
        $R->ifLabel((string) $key, false),
        isset($scope->enable) ? Render::badge('enabled', 'ok') : Render::badge('disabled', 'off'),
        Render::val($T($scope->range->from ?? null)) . ' &ndash; ' . Render::val($T($scope->range->to ?? null)),
        Render::val($T($scope->ramode), '—'),
    ];
}
if ($v6) {
    echo '<h3>DHCPv6 / Router advertisements</h3>';
    echo Render::table(['Interface', 'State', 'Range', 'RA mode'], $v6);
}
