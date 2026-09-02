<?php
/** @var SimpleXMLElement $cfg @var Resolve $R */
$T = fn($n) => Resolve::text($n);
$ip = $cfg->installedpackages;

$rows = [];
foreach ($ip->package ?? [] as $p) {
    $rows[] = [
        '<strong>' . Render::esc($T($p->name)) . '</strong>',
        '<code>' . Render::val($T($p->version)) . '</code>',
        Render::val($T($p->descr), '—'),
        $T($p->pkginfolink) !== ''
            ? '<a href="' . Render::esc($T($p->pkginfolink)) . '" target="_blank" rel="noreferrer noopener">project page</a>'
            : '<span class="empty">—</span>',
        '<code>' . Render::val($T($p->configurationfile), '—') . '</code>',
    ];
}
echo '<h3>Installed packages</h3>';
echo Render::table(['Package', 'Version', 'Description', 'Link', 'Config file'], $rows);

/* Services the packages register */
$rows = [];
foreach ($ip->service ?? [] as $s) {
    $rows[] = [
        '<strong>' . Render::esc($T($s->name)) . '</strong>',
        '<code>' . Render::val($T($s->executable)) . '</code>',
        '<code>' . Render::val($T($s->rcfile)) . '</code>',
        Render::val($T($s->description), '—'),
    ];
}
if ($rows) {
    echo '<h3>Package services</h3>';
    echo Render::table(['Service', 'Executable', 'rc script', 'Description'], $rows);
}

/* Menu entries added to the GUI */
$rows = [];
foreach ($ip->menu ?? [] as $m) {
    $rows[] = [
        Render::val($T($m->section), '—'),
        '<strong>' . Render::esc($T($m->name)) . '</strong>',
        '<code>' . Render::val($T($m->url)) . '</code>',
        Render::val($T($m->tooltiptext), '—'),
    ];
}
if ($rows) {
    echo '<h3>Menu entries added</h3>';
    echo Render::table(['GUI section', 'Name', 'URL', 'Tooltip'], $rows);
}

/* Per-package settings blocks. VPN-related ones live in the VPN section. */
$elsewhere = ['package', 'service', 'menu', 'tab', 'tailscale', 'tailscaleauth', 'wireguard', 'sudo'];
foreach ($ip->children() as $name => $node) {
    $name = (string) $name;
    if (in_array($name, $elsewhere, true)) {
        continue;
    }
    echo '<h3>Settings: ' . Render::esc($name) . '</h3>';
    if (Render::isEmptyNode($node)) {
        echo '<p class="empty">Present but empty &mdash; the package has no saved settings.</p>';
    } else {
        echo Render::dump($node, 0, $name);
    }
}
