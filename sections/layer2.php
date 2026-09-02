<?php
/** @var SimpleXMLElement $cfg @var Resolve $R */
$T = fn($n) => Resolve::text($n);

/* ---- VLANs ---- */
$rows = [];
foreach ($cfg->vlans->vlan ?? [] as $v) {
    $rows[] = [
        '<code>' . Render::esc($T($v->vlanif)) . '</code>',
        '<code>' . Render::esc($T($v->if)) . '</code>',
        '<strong>' . Render::val($T($v->tag)) . '</strong>',
        Render::val($T($v->pcp), 'default'),
        Render::val($T($v->descr), 'no description'),
    ];
}
echo '<h3>VLANs</h3>';
echo Render::table(['Interface', 'Parent port', 'Tag', 'PCP', 'Description'], $rows);

/* ---- LAGGs ---- */
$rows = [];
foreach ($cfg->laggs->lagg ?? [] as $l) {
    $members = array_filter(array_map('trim', explode(',', $T($l->members))));
    $rows[] = [
        '<code>' . Render::esc($T($l->laggif)) . '</code>',
        implode(' ', array_map(fn($m) => '<code>' . Render::esc($m) . '</code>', $members)),
        Render::badge(strtoupper($T($l->proto)), 'info'),
        Render::val($T($l->lacptimeout), 'slow'),
        Render::val($T($l->lagghash)),
        Render::val($T($l->descr), 'no description'),
    ];
}
echo '<h3>Link aggregation</h3>';
echo Render::table(['Interface', 'Members', 'Protocol', 'LACP timeout', 'Hash', 'Description'], $rows);

/* ---- PPPoE / PPP ---- */
$rows = [];
foreach ($cfg->ppps->ppp ?? [] as $p) {
    $rows[] = [
        '<code>' . Render::esc($T($p->if)) . '</code>',
        Render::badge(strtoupper($T($p->type)), 'info'),
        '<code>' . Render::esc($T($p->ports)) . '</code>',
        '<span class="mono">' . Render::val($T($p->username)) . '</span>',
        Secrets::redact($T($p->password)),
        Render::val($T($p->provider), 'none'),
    ];
}
echo '<h3>PPP / PPPoE links</h3>';
echo Render::table(['Interface', 'Type', 'Parent port', 'Username', 'Password', 'Provider'], $rows);
echo '<p class="note">The PPPoE password is stored base64-encoded in the backup, which is encoding, not encryption &mdash; revealing it shows the decoded value.</p>';

/* ---- Interface groups ---- */
$rows = [];
foreach ($cfg->ifgroups->ifgroupentry ?? [] as $g) {
    $members = $T($g->members);
    $rows[] = [
        '<strong>' . Render::esc($T($g->ifname)) . '</strong>',
        $members !== '' ? $R->ifList($members) : '<span class="empty">no members</span>',
        Render::val($T($g->descr), 'no description'),
    ];
}
echo '<h3>Interface groups</h3>';
echo Render::table(['Group', 'Members', 'Description'], $rows);

/* ---- Bridges / QinQ ---- */
$bridges = [];
foreach ($cfg->bridges->bridged ?? [] as $b) {
    $bridges[] = [
        '<code>' . Render::esc($T($b->bridgeif)) . '</code>',
        $R->ifList($T($b->members)),
        Render::val($T($b->descr)),
    ];
}
echo '<h3>Bridges</h3>';
echo $bridges
    ? Render::table(['Interface', 'Members', 'Description'], $bridges)
    : '<p class="empty">No bridges configured.</p>';
