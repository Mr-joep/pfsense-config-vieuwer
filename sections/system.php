<?php
/** @var SimpleXMLElement $cfg @var Resolve $R */
$T = fn($n) => Resolve::text($n);
$sys = $cfg->system;

/* Rendered elsewhere — skip here so this section is "everything else in <system>". */
$handled = [
    'hostname', 'domain', 'user', 'group', 'webgui', 'dnsserver',
    'dns1gw', 'dns2gw', 'dns3gw', 'dns4gw', 'language', 'timezone', 'timeservers',
];

$plain = [];
$flags = [];
foreach ($sys->children() as $name => $node) {
    $name = (string) $name;
    if (in_array($name, $handled, true)) {
        continue;
    }
    if (count($node->children()) > 0) {
        continue; // nested blocks handled below
    }
    $v = trim((string) $node);
    if ($v === '') {
        $flags[] = $name;   // present-but-empty = an "on" toggle in pfSense
        continue;
    }
    $plain[$name] = Secrets::isSecret($name)
        ? Secrets::redact($v)
        : '<span class="mono">' . Render::esc($v) . '</span>';
}

/* Call out the entries most worth noticing. */
$notable = [];
foreach (['earlyshellcmd' => 'Runs at boot before the config is applied',
          'shellcmd'      => 'Runs at boot after the config is applied',
          'crypto_hardware'=> 'Hardware crypto accelerator',
          'thermal_hardware' => 'Thermal sensor driver',
          'statepolicy'   => 'State policy',
          'optimization'  => 'Firewall optimization profile',
          'php_memory_limit' => 'PHP memory limit (MB)'] as $k => $why) {
    if (isset($plain[$k])) {
        $notable[$why] = $plain[$k];
        unset($plain[$k]);
    }
}

if ($notable) {
    echo '<h3>Notable settings</h3>';
    echo Render::kv($notable);
}

echo '<h3>Enabled toggles</h3>';
echo $flags
    ? '<div class="chips">' . implode('', array_map(fn($f) => '<code>' . Render::esc($f) . '</code>', $flags)) . '</div>'
      . '<p class="note">In pfSense an empty element means the toggle exists; whether it reads as on or off depends '
      . 'on the setting. These are the switches this config touches.</p>'
    : '<p class="empty">None.</p>';

echo '<h3>All other system settings</h3>';
echo Render::kv($plain);

/* Nested blocks inside <system> */
foreach ($sys->children() as $name => $node) {
    $name = (string) $name;
    if (in_array($name, $handled, true) || count($node->children()) === 0) {
        continue;
    }
    echo '<h3>system / ' . Render::esc($name) . '</h3>';
    echo Render::dump($node, 0, $name);
}
