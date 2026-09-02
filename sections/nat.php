<?php
/** @var SimpleXMLElement $cfg @var Resolve $R */
$T = fn($n) => Resolve::text($n);

$mode = $T($cfg->nat->outbound->mode) ?: 'automatic';
echo Render::kv([
    'Outbound NAT mode' => Render::badge($mode, $mode === 'automatic' ? 'ok' : 'info'),
    'NAT reflection'    => Render::yesNo(isset($cfg->system->enablenatreflectionpurenat), 'pure NAT', 'off')
                           . ' ' . (isset($cfg->system->enablenatreflectionhelper) ? Render::badge('helper on', 'info') : ''),
]);

/* Separators become group headings: <sep0><row>fr0</row> sits above rule index 0. */
$seps = [];
foreach ($cfg->nat->separator ?? [] as $sepRoot) {
    foreach ($sepRoot->children() as $sep) {
        if (preg_match('~fr(\d+)~', $T($sep->row), $m)) {
            $seps[(int) $m[1]][] = [
                'text'  => $T($sep->text),
                'color' => $T($sep->color),
            ];
        }
    }
}

$rows = [];
$i = 0;
foreach ($cfg->nat->rule ?? [] as $rule) {
    foreach ($seps[$i] ?? [] as $sep) {
        $rows[] = [
            '_class' => 'sep ' . Render::esc(str_replace('bg-', 'sep-', $sep['color'])),
            '_span'  => Render::esc($sep['text']),
        ];
    }

    $isOff = isset($rule->disabled);
    $proto = $T($rule->protocol);
    $target = $T($rule->target);
    $lport  = $T($rule->{'local-port'});

    $to = $target !== '' ? $R->addressToken($target) : '<span class="empty">—</span>';
    if ($lport !== '') {
        $to .= ' <span class="port">:' . Render::esc(str_replace(':', '–', $lport)) . '</span>';
    }

    $flags = [];
    if (isset($rule->nordr))      $flags[] = 'no redirect';
    if (isset($rule->nosync))     $flags[] = 'no XMLRPC sync';
    if ($T($rule->natreflection) !== '') $flags[] = 'reflection: ' . $T($rule->natreflection);
    if ($T($rule->{'associated-rule-id'}) !== '') $flags[] = 'has linked firewall rule';

    $descr = $T($rule->descr);

    $rows[] = [
        '_class' => $isOff ? 'off' : '',
        '<span class="idx">' . ($i + 1) . '</span>',
        $R->ifLabel($T($rule->interface), false) . ($isOff ? ' ' . Render::badge('disabled', 'off') : ''),
        $proto !== '' ? Render::esc(strtoupper($proto)) : '<em>any</em>',
        $R->endpoint($rule->source),
        $R->endpoint($rule->destination),
        '<span class="arrow">&rarr;</span> ' . $to,
        ($descr !== '' ? Render::esc($descr) : '<span class="empty">no description</span>')
            . ($flags ? '<div class="rule-meta">' . Render::esc(implode(' · ', $flags)) . '</div>' : ''),
    ];
    $i++;
}

/* A separator can also sit after the last rule (pfSense allows a trailing one). */
foreach ($seps as $at => $list) {
    if ($at < $i) {
        continue;
    }
    foreach ($list as $sep) {
        $rows[] = [
            '_class' => 'sep ' . Render::esc(str_replace('bg-', 'sep-', $sep['color'])),
            '_span'  => Render::esc($sep['text']),
        ];
    }
}

echo '<h3>Port forwards</h3>';
echo Render::table(
    ['#', 'Interface', 'Protocol', 'Source', 'Destination', 'Forwarded to', 'Description'],
    $rows,
    'rules'
);

/* Manual outbound rules, if the mode is not purely automatic. */
$out = [];
foreach ($cfg->nat->outbound->rule ?? [] as $rule) {
    $out[] = [
        $R->ifLabel($T($rule->interface), false),
        $T($rule->protocol) !== '' ? Render::esc(strtoupper($T($rule->protocol))) : '<em>any</em>',
        $R->endpoint($rule->source),
        $R->endpoint($rule->destination),
        $T($rule->target) !== '' ? $R->addressToken($T($rule->target)) : '<em>interface address</em>',
        Render::val($T($rule->descr), 'no description'),
    ];
}
if ($out) {
    echo '<h3>Outbound NAT rules</h3>';
    echo Render::table(['Interface', 'Protocol', 'Source', 'Destination', 'Translate to', 'Description'], $out, 'rules');
}

/* 1:1 NAT */
$onetoone = [];
foreach ($cfg->nat->onetoone ?? [] as $rule) {
    $onetoone[] = [
        $R->ifLabel($T($rule->interface), false),
        $R->endpoint($rule->source),
        $R->endpoint($rule->destination),
        Render::val($T($rule->external)),
        Render::val($T($rule->descr), 'no description'),
    ];
}
if ($onetoone) {
    echo '<h3>1:1 NAT</h3>';
    echo Render::table(['Interface', 'Internal', 'Destination', 'External', 'Description'], $onetoone, 'rules');
}
