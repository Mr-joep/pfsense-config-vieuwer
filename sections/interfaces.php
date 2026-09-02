<?php
/** @var SimpleXMLElement $cfg @var Resolve $R */
$rows = [];
foreach ($R->interfaces() as $key => $i) {
    $node = $i['node'];
    $ip = $i['ipaddr'];

    if ($ip === 'dhcp') {
        $addr = Render::badge('DHCP client', 'info');
    } elseif ($ip === 'pppoe') {
        $addr = Render::badge('PPPoE', 'info');
    } elseif ($ip === 'ppp') {
        $addr = Render::badge('PPP', 'info');
    } elseif ($ip !== '') {
        $addr = '<code>' . Render::esc($ip . ($i['subnet'] !== '' ? '/' . $i['subnet'] : '')) . '</code>';
    } else {
        $addr = '<span class="empty">none</span>';
    }

    $media = array_filter([Resolve::text($node->media), Resolve::text($node->mediaopt)]);
    $extra = [];
    if (Resolve::text($node->spoofmac) !== '') {
        $extra[] = 'MAC spoof ' . Resolve::text($node->spoofmac);
    }
    if (Resolve::text($node->mtu) !== '') {
        $extra[] = 'MTU ' . Resolve::text($node->mtu);
    }
    if (Resolve::text($node->blockpriv) !== '' || isset($node->blockpriv)) {
        $extra[] = 'block private networks';
    }
    if (isset($node->blockbogons)) {
        $extra[] = 'block bogons';
    }

    $rows[] = [
        '<code>' . Render::esc($key) . '</code>',
        '<strong>' . Render::esc($i['descr']) . '</strong>',
        $R->physical($i['if']),
        $addr,
        $media ? Render::esc(implode(' ', $media)) : '<span class="empty">auto</span>',
        Render::yesNo($i['enabled'], 'enabled', 'disabled'),
        $extra ? Render::esc(implode(', ', $extra)) : '<span class="empty">—</span>',
    ];
}

echo Render::table(
    ['Key', 'Name', 'Physical port', 'Address', 'Media', 'State', 'Options'],
    $rows
);

$undef = $R->undefinedInterfaces();
if ($undef) {
    echo '<p class="note">Referenced elsewhere but not defined here: <code>'
        . implode('</code>, <code>', array_map([Render::class, 'esc'], $undef))
        . '</code></p>';
}
