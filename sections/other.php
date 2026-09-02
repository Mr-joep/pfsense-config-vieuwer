<?php
/** @var SimpleXMLElement $cfg @var array $rrd */

/* Sections that already have a purpose-built view above. */
$handled = [
    'version', 'revision', 'system', 'interfaces', 'vlans',
    'laggs', 'bridges', 'ppps', 'ifgroups', 'filter', 'nat', 'aliases', 'dhcpd',
    'dhcpdv6', 'dhcpbackend', 'unbound', 'dnsmasq', 'gateways', 'staticroutes',
    'openvpn', 'ovpnserver', 'ipsec', 'cert', 'ca', 'crl', 'cron',
    'installedpackages', 'rrddata',
];

$empty = [];
$filled = [];

foreach ($cfg->children() as $name => $node) {
    $name = (string) $name;
    if (in_array($name, $handled, true)) {
        continue;
    }
    if (Render::isEmptyNode($node)) {
        $empty[$name] = true;
    } else {
        $filled[$name][] = $node;
    }
}

if ($rrd['present']) {
    echo '<h3>rrddata</h3>';
    echo '<p class="note">' . (int) $rrd['datasets'] . ' RRD datasets, '
        . Render::esc(Render::bytes($rrd['bytes']))
        . ' of base64-encoded traffic-graph history. Not displayed &mdash; it is binary graph data, and it is '
        . 'responsible for nearly the entire size of this backup file.</p>';
}

foreach ($filled as $name => $nodes) {
    echo '<h3>' . Render::esc($name) . '</h3>';
    if (count($nodes) === 1) {
        echo Render::dump($nodes[0], 0, $name);
    } else {
        foreach ($nodes as $i => $n) {
            echo '<h4>#' . ($i + 1) . '</h4>';
            echo Render::dump($n, 0, $name);
        }
    }
}

if ($empty) {
    echo '<h3>Present but empty</h3>';
    echo '<p class="note">These sections exist in the file with no content &mdash; the feature is simply not configured.</p>';
    echo '<div class="chips">';
    foreach (array_keys($empty) as $name) {
        echo '<code>' . Render::esc($name) . '</code>';
    }
    echo '</div>';
}

if (!$filled && !$empty && !$rrd['present']) {
    echo '<p class="empty">Every section of this config is covered by a view above.</p>';
}
