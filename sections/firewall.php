<?php
/** @var SimpleXMLElement $cfg @var Resolve $R */
$T = fn($n) => Resolve::text($n);

/* Group rules by interface, preserving config order (= evaluation order). */
$byIface = [];
$total = 0;
$disabled = 0;
foreach ($cfg->filter->rule ?? [] as $rule) {
    $iface = $T($rule->interface);
    if (isset($rule->floating)) {
        $iface = '__floating__';
    }
    $byIface[$iface][] = $rule;
    $total++;
    if (isset($rule->disabled)) {
        $disabled++;
    }
}

/* Firewall separators, if this backup has any: <separator><wan><sep0><row>fr3</row> */
$seps = [];
foreach ($cfg->filter->separator ?? [] as $sepRoot) {
    foreach ($sepRoot->children() as $ifKey => $group) {
        foreach ($group->children() as $sep) {
            if (preg_match('~fr(\d+)~', $T($sep->row), $m)) {
                $seps[(string) $ifKey][(int) $m[1]][] = [
                    'text'  => $T($sep->text),
                    'color' => $T($sep->color),
                ];
            }
        }
    }
}

echo '<p class="lead">' . $total . ' rules across ' . count($byIface) . ' interfaces'
    . ($disabled ? ', ' . $disabled . ' of them disabled' : '')
    . '. Rules are listed in evaluation order &mdash; within an interface, the first match wins.</p>';

/* Put the well-known interfaces first, then the rest. */
uksort($byIface, function ($a, $b) {
    $order = ['__floating__' => 0, 'wan' => 1, 'lan' => 2];
    $ra = $order[$a] ?? 50;
    $rb = $order[$b] ?? 50;
    return $ra === $rb ? strcmp($a, $b) : $ra <=> $rb;
});

foreach ($byIface as $iface => $rules) {
    $title = $iface === '__floating__'
        ? '<strong>Floating rules</strong>'
        : $R->ifLabel($iface);

    echo '<h3 class="iface-head">' . $title
        . ' <span class="count">' . count($rules) . ' rule' . (count($rules) === 1 ? '' : 's') . '</span></h3>';

    $rows = [];
    foreach ($rules as $i => $rule) {
        foreach ($seps[$iface][$i] ?? [] as $sep) {
            $rows[] = [
                '_class' => 'sep ' . Render::esc(str_replace('bg-', 'sep-', $sep['color'])),
                '_span'  => Render::esc($sep['text']),
            ];
        }

        $type = strtolower($T($rule->type));
        $kind = ['pass' => 'ok', 'block' => 'bad', 'reject' => 'bad', 'match' => 'info'][$type] ?? 'info';
        $isOff = isset($rule->disabled);

        $proto = $T($rule->protocol);
        if ($proto === '') {
            $proto = '<em>any</em>';
        } else {
            $proto = Render::esc(strtoupper($proto));
            if ($T($rule->icmptype) !== '') {
                $proto .= ' <small class="muted">' . Render::esc($T($rule->icmptype)) . '</small>';
            }
        }

        $flags = [];
        if ($T($rule->ipprotocol) !== '' && $T($rule->ipprotocol) !== 'inet') {
            $flags[] = strtoupper(str_replace('inet', 'IPv', $T($rule->ipprotocol)));
        }
        if (isset($rule->log))     $flags[] = 'log';
        if (isset($rule->quick))   $flags[] = 'quick';
        if ($T($rule->direction) !== '') $flags[] = 'dir ' . $T($rule->direction);
        if ($T($rule->gateway) !== '')   $flags[] = 'gw ' . $T($rule->gateway);
        if ($T($rule->sched) !== '')     $flags[] = 'schedule ' . $T($rule->sched);
        if ($T($rule->tag) !== '')       $flags[] = 'tag ' . $T($rule->tag);
        if ($T($rule->tagged) !== '')    $flags[] = 'tagged ' . $T($rule->tagged);
        if ($T($rule->{'associated-rule-id'}) !== '') $flags[] = 'linked to a NAT rule';

        $descr = $T($rule->descr);
        $who = $T($rule->updated->username) ?: $T($rule->created->username);
        $whenEpoch = $T($rule->updated->time) ?: $T($rule->created->time);
        $meta = '';
        if ($who !== '') {
            $meta = '<div class="rule-meta">' . Render::esc(preg_replace('~\s*\(.*\)$~', '', $who))
                . ' &middot; ' . Render::when($whenEpoch) . '</div>';
        }

        $rows[] = [
            '_class' => $isOff ? 'off' : '',
            '<span class="idx">' . ($i + 1) . '</span>',
            Render::badge(strtoupper($type ?: '?'), $kind) . ($isOff ? ' ' . Render::badge('disabled', 'off') : ''),
            $proto,
            $R->endpoint($rule->source),
            $R->endpoint($rule->destination),
            ($descr !== '' ? Render::esc($descr) : '<span class="empty">no description</span>') . $meta,
            $flags ? '<small class="muted">' . Render::esc(implode(' · ', $flags)) . '</small>' : '',
        ];
    }

    foreach ($seps[$iface] ?? [] as $at => $list) {
        if ($at < count($rules)) {
            continue;
        }
        foreach ($list as $sep) {
            $rows[] = [
                '_class' => 'sep ' . Render::esc(str_replace('bg-', 'sep-', $sep['color'])),
                '_span'  => Render::esc($sep['text']),
            ];
        }
    }

    echo Render::table(['#', 'Action', 'Protocol', 'Source', 'Destination', 'Description', 'Options'], $rows, 'rules');
}
