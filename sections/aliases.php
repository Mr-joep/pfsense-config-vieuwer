<?php
/** @var SimpleXMLElement $cfg @var Resolve $R */
$T = fn($n) => Resolve::text($n);

/* Which firewall / NAT rules mention each alias. */
$used = [];
$scan = function ($rule, string $where) use (&$used, $T) {
    foreach (['source', 'destination'] as $side) {
        foreach (['address', 'network', 'port'] as $field) {
            $v = $T($rule->{$side}->{$field} ?? null);
            if ($v !== '' && !is_numeric($v)) {
                $used[$v][] = $where;
            }
        }
    }
    $t = $T($rule->target ?? null);
    if ($t !== '' && !filter_var($t, FILTER_VALIDATE_IP)) {
        $used[$t][] = $where;
    }
};
foreach ($cfg->filter->rule ?? [] as $rule) {
    $scan($rule, 'firewall: ' . ($T($rule->descr) ?: $T($rule->interface)));
}
foreach ($cfg->nat->rule ?? [] as $rule) {
    $scan($rule, 'NAT: ' . ($T($rule->descr) ?: $T($rule->interface)));
}

$rows = [];
foreach ($cfg->aliases->alias ?? [] as $a) {
    $name = $T($a->name);
    $type = $T($a->type);
    $addr = $T($a->address);
    $items = $addr === '' ? [] : preg_split('~\s+~', $addr);
    $items = array_values(array_filter($items));

    $list = '<span class="empty">empty</span>';
    if ($items) {
        $shown = array_slice($items, 0, 8);
        $list = '<div class="chips">';
        foreach ($shown as $x) {
            $list .= '<code>' . Render::esc($x) . '</code>';
        }
        $list .= '</div>';
        if (count($items) > count($shown)) {
            $list .= '<details><summary>' . (count($items) - count($shown)) . ' more</summary><div class="chips">';
            foreach (array_slice($items, 8) as $x) {
                $list .= '<code>' . Render::esc($x) . '</code>';
            }
            $list .= '</div></details>';
        }
    }

    $src = $T($a->aliasurl);
    $where = array_unique($used[$name] ?? []);

    $rows[] = [
        '<strong id="alias-' . Render::esc($name) . '">' . Render::esc($name) . '</strong>',
        Render::badge($type ?: 'network', 'info'),
        '<span class="count">' . count($items) . '</span>',
        $list,
        $src !== '' ? '<a href="' . Render::esc($src) . '" rel="noreferrer noopener" target="_blank">' . Render::esc($src) . '</a>' : '<span class="empty">manual</span>',
        Render::val($T($a->descr), 'no description'),
        $where ? Render::esc(implode('; ', array_slice($where, 0, 4))) . (count($where) > 4 ? ' …' : '') : '<span class="empty">not referenced</span>',
    ];
}

echo Render::table(
    ['Name', 'Type', 'Entries', 'Contents', 'Source URL', 'Description', 'Used by'],
    $rows
);
echo '<p class="note">URL-type aliases hold the contents pfSense last downloaded. The refresh runs from the '
    . '<code>/etc/rc.update_urltables</code> cron job.</p>';
