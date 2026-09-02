<?php
/** @var SimpleXMLElement $cfg @var Resolve $R */
$T = fn($n) => Resolve::text($n);

/* ---------- OpenVPN servers ---------- */
$modes = [
    'server_tls'      => 'SSL/TLS only',
    'server_user'     => 'User auth only',
    'server_tls_user' => 'SSL/TLS + user auth',
    'p2p_tls'         => 'Peer to peer (SSL/TLS)',
    'p2p_shared_key'  => 'Peer to peer (shared key)',
];

$any = false;
foreach ($cfg->openvpn->{'openvpn-server'} ?? [] as $s) {
    $any = true;
    $desc = $T($s->description) ?: ('Server ' . $T($s->vpnid));
    echo '<h3 class="iface-head">' . Render::esc($desc)
        . ' <span class="count">vpnid ' . Render::esc($T($s->vpnid)) . '</span>'
        . (isset($s->disable) ? ' ' . Render::badge('disabled', 'off') : ' ' . Render::badge('enabled', 'ok'))
        . '</h3>';

    echo Render::kv(array_filter([
        'Mode'             => Render::esc($modes[$T($s->mode)] ?? $T($s->mode)),
        'Auth backend'     => Render::val($T($s->authmode), '—'),
        'Listening on'     => $R->ifLabel($T($s->interface)) . ' <code>'
                              . Render::esc($T($s->protocol)) . ':' . Render::esc($T($s->local_port)) . '</code>',
        'Device mode'      => Render::esc($T($s->dev_mode)) . ' / topology ' . Render::esc($T($s->topology)),
        'Tunnel network'   => $T($s->tunnel_network) !== '' ? '<code>' . Render::esc($T($s->tunnel_network)) . '</code>' : null,
        'Local networks pushed' => $T($s->local_network) !== '' ? '<code>' . Render::esc($T($s->local_network)) . '</code>' : '<span class="empty">none</span>',
        'Remote networks'  => $T($s->remote_network) !== '' ? '<code>' . Render::esc($T($s->remote_network)) . '</code>' : null,
        'Redirect gateway' => Render::yesNo(isset($s->gwredir), 'all traffic through VPN', 'split tunnel'),
        'DNS pushed'       => implode(' ', array_filter([
                                $T($s->dns_server1), $T($s->dns_server2), $T($s->dns_server3), $T($s->dns_server4),
                              ])) !== ''
                              ? '<code>' . Render::esc(implode(' ', array_filter([$T($s->dns_server1), $T($s->dns_server2), $T($s->dns_server3), $T($s->dns_server4)]))) . '</code>'
                              : null,
        'Certificate authority' => $R->caName($T($s->caref)),
        'Server certificate'    => $R->certName($T($s->certref)),
        'TLS key'          => $T($s->tls) !== '' ? Secrets::redact($T($s->tls)) . ' <small class="muted">tls-' . Render::esc($T($s->tls_type)) . '</small>' : null,
        'Data ciphers'     => '<code>' . Render::esc($T($s->data_ciphers)) . '</code>',
        'Fallback cipher'  => '<code>' . Render::esc($T($s->data_ciphers_fallback)) . '</code>',
        'Digest'           => Render::esc($T($s->digest)),
        'DH parameters'    => Render::esc($T($s->dh_length)) . ' bit',
        'Max clients'      => Render::val($T($s->maxclients), 'unlimited')
                              . ($T($s->connlimit) !== '' ? ' <small class="muted">' . Render::esc($T($s->connlimit)) . ' per user</small>' : ''),
        'Duplicate CN'     => Render::yesNo(isset($s->duplicate_cn), 'allowed', 'not allowed'),
        'Client to client' => Render::yesNo(isset($s->client2client)),
        'Compression'      => Render::val($T($s->allow_compression), 'no'),
        'Keepalive'        => $T($s->keepalive_interval) !== ''
                              ? Render::esc($T($s->keepalive_interval)) . ' s / ' . Render::esc($T($s->keepalive_timeout)) . ' s timeout'
                              : null,
        'Inactive timeout' => $T($s->inactive_seconds) !== '' ? Render::esc($T($s->inactive_seconds)) . ' s' : null,
        'Custom options'   => $T($s->custom_options) !== '' ? '<pre>' . Render::esc($T($s->custom_options)) . '</pre>' : null,
    ], fn($v) => $v !== null));
}

foreach ($cfg->openvpn->{'openvpn-client'} ?? [] as $c) {
    $any = true;
    echo '<h3>OpenVPN client: ' . Render::val($T($c->description), 'unnamed') . '</h3>';
    echo Render::kv([
        'Server'         => '<code>' . Render::esc($T($c->server_addr)) . ':' . Render::esc($T($c->server_port)) . '</code>',
        'Mode'           => Render::val($T($c->mode)),
        'Interface'      => $R->ifLabel($T($c->interface)),
        'Tunnel network' => Render::val($T($c->tunnel_network)),
        'CA'             => $R->caName($T($c->caref)),
        'Certificate'    => $R->certName($T($c->certref)),
    ]);
}

if (!$any) {
    echo '<h3>OpenVPN</h3><p class="empty">No OpenVPN servers or clients configured.</p>';
}

/* ---------- Wizard leftovers ---------- */
if (!Render::isEmptyNode($cfg->ovpnserver)) {
    echo '<h3>OpenVPN wizard state</h3>';
    echo '<p class="note">Saved answers from the last run of the OpenVPN setup wizard. These are not live settings &mdash; '
        . 'note the tunnel network and local network here differ from the running server above, so the wizard was re-run '
        . 'with different values than were finally saved.</p>';
    echo Render::dump($cfg->ovpnserver, 0, 'ovpnserver');
}

/* ---------- Tailscale ---------- */
$ts = $cfg->installedpackages->tailscale->config ?? null;
$tsAuth = $cfg->installedpackages->tailscaleauth->config ?? null;
if ($ts !== null || $tsAuth !== null) {
    echo '<h3>Tailscale</h3>';
    $routes = [];
    foreach ($ts->row ?? [] as $row) {
        $v = $T($row->advertisedroutevalue);
        if ($v === '') continue;
        $routes[] = '<code>' . Render::esc($v) . '</code>'
            . ($T($row->advertisedroutedescr) !== '' ? ' <small class="muted">' . Render::esc($T($row->advertisedroutedescr)) . '</small>' : '');
    }
    echo Render::kv(array_filter([
        'Enabled'           => Render::yesNo($T($ts->enable ?? null) === 'on'),
        'Listen port'       => $T($ts->listenport ?? null) !== '' ? '<code>' . Render::esc($T($ts->listenport)) . '</code>' : null,
        'Exit node'         => Render::yesNo($T($ts->exitnode ?? null) === 'on', 'advertising as exit node', 'no'),
        'Accept DNS'        => Render::yesNo($T($ts->acceptdns ?? null) === 'on'),
        'Accept routes'     => Render::yesNo($T($ts->acceptroutes ?? null) === 'on'),
        'Advertised routes' => $routes ? '<div class="stack">' . implode('', array_map(fn($r) => '<div>' . $r . '</div>', $routes)) . '</div>' : '<span class="empty">none</span>',
        'State directory'   => $T($ts->statedir ?? null) !== '' ? '<code>' . Render::esc($T($ts->statedir)) . '</code>' : null,
        'Login server'      => $T($tsAuth->loginserver ?? null) !== '' ? Render::esc($T($tsAuth->loginserver)) : null,
        'Pre-auth key'      => $T($tsAuth->preauthkey ?? null) !== '' ? Secrets::redact($T($tsAuth->preauthkey)) : null,
    ], fn($v) => $v !== null));
    echo '<p class="note">Tailscale creates the <code>Tailscale</code> interface group that some firewall rules target.</p>';
}

/* ---------- WireGuard ---------- */
$wg = $cfg->installedpackages->wireguard ?? null;
if ($wg !== null && !Render::isEmptyNode($wg)) {
    echo '<h3>WireGuard</h3>';
    echo Render::dump($wg, 0, 'wireguard');
}

/* ---------- IPsec ---------- */
echo '<h3>IPsec</h3>';
if (Render::isEmptyNode($cfg->ipsec) || (count($cfg->ipsec->phase1 ?? []) === 0)) {
    echo '<p class="empty">No IPsec tunnels configured'
        . (Render::isEmptyNode($cfg->ipsec->client ?? null) ? '' : ' (mobile client settings present)')
        . '. A firewall rule still exists on <code>enc0</code>, the IPsec interface.</p>';
} else {
    echo Render::dump($cfg->ipsec, 0, 'ipsec');
}
