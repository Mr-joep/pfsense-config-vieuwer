<?php
/** @var SimpleXMLElement $cfg @var Resolve $R */
$T = fn($n) => Resolve::text($n);
$g = $cfg->gateways;

$default4 = $T($g->defaultgw4);
$default6 = $T($g->defaultgw6);

$rows = [];
foreach ($g->gateway_item ?? [] as $item) {
    $name = $T($item->name);
    $isDefault = ($name !== '' && $name === $default4);
    $rows[] = [
        '<strong>' . Render::esc($name) . '</strong>' . ($isDefault ? ' ' . Render::badge('default', 'ok') : ''),
        $R->ifLabel($T($item->interface)),
        $T($item->gateway) === 'dynamic'
            ? Render::badge('dynamic', 'info')
            : '<code>' . Render::val($T($item->gateway)) . '</code>',
        Render::esc(strtoupper(str_replace(['inet6', 'inet'], ['IPv6', 'IPv4'], $T($item->ipprotocol)))),
        $T($item->monitor) !== '' ? '<code>' . Render::esc($T($item->monitor)) . '</code>' : '<span class="empty">gateway itself</span>',
        Render::val($T($item->weight), '1'),
        Render::val($T($item->descr), '—'),
        isset($item->disabled) ? Render::badge('disabled', 'off') : Render::badge('enabled', 'ok'),
    ];
}
echo '<h3>Gateways</h3>';
echo Render::table(['Name', 'Interface', 'Address', 'Family', 'Monitor IP', 'Weight', 'Description', 'State'], $rows);

$rows = [];
foreach ($g->gateway_group ?? [] as $grp) {
    $name = $T($grp->name);
    $members = [];
    foreach ($grp->item ?? [] as $it) {
        // Format is "GATEWAY|tier|virtualip"
        $parts = explode('|', (string) $it);
        $members[] = '<div class="tier"><span class="tier-n">Tier ' . Render::esc($parts[1] ?? '?') . '</span> '
            . '<strong>' . Render::esc($parts[0] ?? '') . '</strong>'
            . (isset($parts[2]) && $parts[2] !== '' ? ' <small class="muted">' . Render::esc($parts[2]) . '</small>' : '')
            . '</div>';
    }
    $rows[] = [
        '<strong>' . Render::esc($name) . '</strong>' . ($name === $default4 ? ' ' . Render::badge('default', 'ok') : ''),
        implode('', $members) ?: '<span class="empty">no members</span>',
        Render::val($T($grp->trigger), 'member down'),
        Render::val($T($grp->descr), '—'),
    ];
}
echo '<h3>Gateway groups</h3>';
echo Render::table(['Name', 'Members', 'Failover trigger', 'Description'], $rows);
echo '<p class="note">Lower tier numbers win. Members in the same tier share load; a higher tier is only used '
    . 'when every lower tier fails the trigger condition.</p>';

/* Static routes */
$rows = [];
foreach ($cfg->staticroutes->route ?? [] as $r) {
    $rows[] = [
        '<code>' . Render::val($T($r->network)) . '</code>',
        Render::val($T($r->gateway)),
        Render::val($T($r->descr), '—'),
        isset($r->disabled) ? Render::badge('disabled', 'off') : Render::badge('enabled', 'ok'),
    ];
}
echo '<h3>Static routes</h3>';
echo $rows
    ? Render::table(['Network', 'Gateway', 'Description', 'State'], $rows)
    : '<p class="empty">No static routes &mdash; all routing is via the gateways above.</p>';
