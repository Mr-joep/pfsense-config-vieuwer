<?php
/** @var SimpleXMLElement $cfg @var Resolve $R */
$T = fn($n) => Resolve::text($n);

/* uid -> group names */
$memberOf = [];
foreach ($cfg->system->group ?? [] as $g) {
    foreach ($g->member ?? [] as $m) {
        $memberOf[(string) $m][] = $T($g->name);
    }
}

$rows = [];
foreach ($cfg->system->user ?? [] as $u) {
    $uid = $T($u->uid);
    $privs = [];
    foreach ($u->priv ?? [] as $p) {
        $privs[] = (string) $p;
    }
    $groups = $memberOf[$uid] ?? [];

    $secrets = [];
    foreach (['bcrypt-hash' => 'password hash', 'md5-hash' => 'MD5 hash', 'ipsecpsk' => 'IPsec PSK'] as $field => $label) {
        if ($T($u->{$field}) !== '') {
            $secrets[] = Render::esc($label) . ': ' . Secrets::redact($T($u->{$field}));
        }
    }
    $keys = $T($u->authorizedkeys);
    if ($keys !== '') {
        $secrets[] = 'SSH keys: ' . Secrets::redact($keys);
    }

    $rows[] = [
        '<strong>' . Render::esc($T($u->name)) . '</strong>',
        '<code>' . Render::val($uid) . '</code>',
        Render::badge($T($u->scope) ?: 'user', $T($u->scope) === 'system' ? 'info' : ''),
        Render::val($T($u->descr), '—'),
        $groups ? Render::esc(implode(', ', $groups)) : '<span class="empty">none</span>',
        $privs ? '<div class="chips">' . implode('', array_map(fn($p) => '<code>' . Render::esc($p) . '</code>', $privs)) . '</div>' : '<span class="empty">none direct</span>',
        $T($u->cert) !== '' ? $R->certName($T($u->cert)) : '<span class="empty">none</span>',
        $T($u->expires) !== '' ? Render::esc($T($u->expires)) : '<span class="empty">never</span>',
        $secrets ? '<div class="stack">' . implode('', array_map(fn($s) => '<div>' . $s . '</div>', $secrets)) . '</div>' : '<span class="empty">—</span>',
    ];
}
echo '<h3>Users</h3>';
echo Render::table(
    ['Name', 'UID', 'Scope', 'Description', 'Groups', 'Privileges', 'Certificate', 'Expires', 'Credentials'],
    $rows
);

$rows = [];
foreach ($cfg->system->group ?? [] as $g) {
    $privs = [];
    foreach ($g->priv ?? [] as $p) {
        $privs[] = (string) $p;
    }
    $members = [];
    foreach ($g->member ?? [] as $m) {
        $members[] = (string) $m;
    }
    // Map uids back to names where we can.
    $names = [];
    foreach ($members as $uid) {
        $found = $uid;
        foreach ($cfg->system->user ?? [] as $u) {
            if ($T($u->uid) === $uid) {
                $found = $T($u->name);
                break;
            }
        }
        $names[] = $found;
    }

    $rows[] = [
        '<strong>' . Render::esc($T($g->name)) . '</strong>',
        '<code>' . Render::val($T($g->gid)) . '</code>',
        Render::badge($T($g->scope) ?: 'local', $T($g->scope) === 'system' ? 'info' : ''),
        Render::val($T($g->description), '—'),
        $names ? Render::esc(implode(', ', $names)) : '<span class="empty">no members</span>',
        $privs ? '<div class="chips">' . implode('', array_map(fn($p) => '<code>' . Render::esc($p) . '</code>', $privs)) . '</div>' : '<span class="empty">none</span>',
    ];
}
echo '<h3>Groups</h3>';
echo Render::table(['Name', 'GID', 'Scope', 'Description', 'Members', 'Privileges'], $rows);

/* Web GUI / auth settings */
$w = $cfg->system->webgui;
echo '<h3>Web GUI access</h3>';
echo Render::kv(array_filter([
    'Protocol'        => Render::val($T($w->protocol)),
    'Port'            => $T($w->port) !== '' ? Render::esc($T($w->port)) : '<span class="empty">default</span>',
    'Certificate'     => $T($w->{'ssl-certref'}) !== '' ? $R->certName($T($w->{'ssl-certref'})) : null,
    'Login auto-complete' => Render::yesNo(isset($w->loginautocomplete)),
    'Anti-lockout'    => Render::yesNo(!isset($cfg->system->webgui->noantilockout), 'enabled', 'DISABLED'),
    'Max login attempts' => $T($w->max_login_attempts) !== '' ? Render::esc($T($w->max_login_attempts)) : null,
    'Session timeout' => $T($cfg->system->{'webgui-session-timeout'}) !== '' ? Render::esc($T($cfg->system->{'webgui-session-timeout'})) . ' min' : null,
    'SSH enabled'     => Render::yesNo(isset($cfg->system->enablesshd)),
    'SSH port'        => $T($cfg->system->ssh->port ?? null) !== '' ? Render::esc($T($cfg->system->ssh->port)) : null,
], fn($v) => $v !== null));

/* sudo package */
$sudo = $cfg->installedpackages->sudo->config ?? null;
if ($sudo !== null) {
    $rows = [];
    foreach ($sudo->row ?? [] as $r) {
        $rows[] = [
            '<code>' . Render::val($T($r->username)) . '</code>',
            '<code>' . Render::val($T($r->runas)) . '</code>',
            '<code>' . Render::val($T($r->cmdlist)) . '</code>',
            $T($r->nopasswd) !== '' ? Render::badge('no password', 'warn') : '<span class="empty">password required</span>',
        ];
    }
    echo '<h3>sudo rules</h3>';
    echo Render::table(['Who', 'Run as', 'Commands', 'Password'], $rows);
}
