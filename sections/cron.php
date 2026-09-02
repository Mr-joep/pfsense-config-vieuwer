<?php
/** @var SimpleXMLElement $cfg */
$T = fn($n) => Resolve::text($n);

$rows = [];
foreach ($cfg->cron->item ?? [] as $item) {
    $min   = $T($item->minute);
    $hour  = $T($item->hour);
    $mday  = $T($item->mday);
    $month = $T($item->month);
    $wday  = $T($item->wday);

    $rows[] = [
        '<strong>' . Render::esc(Render::cronHuman($min, $hour, $mday, $month, $wday)) . '</strong>',
        '<code class="cronspec">' . Render::esc("$min $hour $mday $month $wday") . '</code>',
        '<code>' . Render::val($T($item->who), 'root') . '</code>',
        '<code>' . Render::esc($T($item->command)) . '</code>',
    ];
}

echo Render::table(['Schedule', 'Spec', 'User', 'Command'], $rows);
echo '<p class="note">These are the entries pfSense keeps in its own config. Package cron jobs may also exist in '
    . 'the system crontab and would not appear in a backup.</p>';
