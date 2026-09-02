<?php
/** @var SimpleXMLElement $cfg @var Resolve $R */
$T = fn($n) => Resolve::text($n);

/* Build a "used by" index so each cert says what depends on it. */
$usedBy = [];
$add = function (string $refid, string $what) use (&$usedBy) {
    if ($refid !== '') {
        $usedBy[$refid][] = $what;
    }
};
$add($T($cfg->unbound->sslcertref), 'DNS Resolver (TLS)');
$add($T($cfg->system->webgui->{'ssl-certref'} ?? null), 'Web GUI');
foreach ($cfg->openvpn->{'openvpn-server'} ?? [] as $s) {
    $label = $T($s->description) ?: ('OpenVPN server ' . $T($s->vpnid));
    $add($T($s->certref), $label . ' (server cert)');
    $add($T($s->caref), $label . ' (CA)');
}
foreach ($cfg->openvpn->{'openvpn-client'} ?? [] as $c) {
    $add($T($c->certref), 'OpenVPN client ' . $T($c->description));
    $add($T($c->caref), 'OpenVPN client ' . $T($c->description) . ' (CA)');
}
foreach ($cfg->system->user ?? [] as $u) {
    $add($T($u->cert), 'User ' . $T($u->name));
}

$renderCert = function (SimpleXMLElement $c, bool $isCa) use ($T, $usedBy, $R) {
    $refid = $T($c->refid);
    $info  = Secrets::describeCert($T($c->crt));
    $hasKey = $T($c->prv) !== '';

    if ($info === null) {
        $subject = '<span class="empty">could not decode</span>';
        $expiry  = '<span class="empty">—</span>';
        $issuer  = '<span class="empty">—</span>';
        $sans    = '<span class="empty">—</span>';
    } elseif (!empty($info['unparsed'])) {
        $subject = '<span class="empty">present, ' . Render::bytes($info['bytes']) . ' (openssl unavailable)</span>';
        $expiry = $issuer = $sans = '<span class="empty">—</span>';
    } else {
        $subject = '<span class="mono">' . Render::esc($info['cn'] !== '' ? $info['cn'] : $info['subject']) . '</span>';
        $issuer  = $info['self_signed']
            ? Render::badge('self-signed', 'info')
            : '<span class="mono">' . Render::esc($info['issuer_cn'] !== '' ? $info['issuer_cn'] : $info['issuer']) . '</span>';

        $state = Secrets::expiryState($info['days_left']);
        $d = $info['days_left'];
        $label = $state === 'expired'
            ? 'expired ' . abs($d) . ' days ago'
            : $d . ' days left';
        $kind = ['expired' => 'bad', 'soon' => 'warn', 'ok' => 'ok'][$state] ?? 'info';
        $expiry = Render::badge($label, $kind)
            . '<div class="rule-meta">' . Render::esc(date('Y-m-d', (int) $info['from']))
            . ' &rarr; ' . Render::esc(date('Y-m-d', (int) $info['to'])) . '</div>';

        $sansList = $info['sans'];
        $sans = $sansList
            ? '<div class="chips">' . implode('', array_map(fn($s) => '<code>' . Render::esc($s) . '</code>', $sansList)) . '</div>'
            : '<span class="empty">none</span>';
    }

    $uses = $usedBy[$refid] ?? [];

    return [
        '<strong>' . Render::esc($T($c->descr)) . '</strong>'
            . '<div class="rule-meta">' . Render::esc($refid) . '</div>',
        $isCa ? Render::badge('CA', 'info') : Render::badge($T($c->type) ?: 'cert', 'info'),
        $subject,
        $issuer,
        $sans,
        $expiry,
        $hasKey ? Secrets::redact($T($c->prv)) : '<span class="empty">no private key</span>',
        $uses ? Render::esc(implode('; ', array_unique($uses))) : '<span class="empty">not referenced</span>',
    ];
};

$headers = ['Name', 'Type', 'Subject CN', 'Issued by', 'Subject alt names', 'Validity', 'Private key', 'Used by'];

$rows = [];
foreach ($cfg->ca ?? [] as $c) {
    $rows[] = $renderCert($c, true);
}
echo '<h3>Certificate authorities</h3>';
echo Render::table($headers, $rows);

$rows = [];
foreach ($cfg->cert ?? [] as $c) {
    $rows[] = $renderCert($c, false);
}
echo '<h3>Certificates</h3>';
echo Render::table($headers, $rows);

/* Certificate revocation lists */
$rows = [];
foreach ($cfg->crl ?? [] as $c) {
    $rows[] = [
        Render::val($T($c->descr)),
        Render::esc($T($c->refid)),
        $R->caName($T($c->caref)),
        (string) count($c->cert ?? []),
    ];
}
if ($rows) {
    echo '<h3>Revocation lists</h3>';
    echo Render::table(['Name', 'Ref ID', 'CA', 'Revoked certs'], $rows);
}

echo '<p class="note">Every certificate in this backup ships with its private key. Anyone holding this file '
    . 'holds those keys.</p>';
