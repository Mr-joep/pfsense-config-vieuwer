<?php
/**
 * Small HTML building blocks plus a generic recursive dumper for any
 * config section that has no purpose-built view.
 */
class Render
{
    public static function esc(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /** A value that may legitimately be empty. */
    public static function val(string $s, string $emptyText = '—'): string
    {
        $s = trim($s);
        return $s === ''
            ? '<span class="empty">' . self::esc($emptyText) . '</span>'
            : self::esc($s);
    }

    public static function badge(string $text, string $kind = ''): string
    {
        $cls = 'badge' . ($kind !== '' ? ' badge-' . $kind : '');
        return '<span class="' . $cls . '">' . self::esc($text) . '</span>';
    }

    public static function yesNo(bool $on, string $yes = 'yes', string $no = 'no'): string
    {
        return $on
            ? self::badge($yes, 'ok')
            : '<span class="empty">' . self::esc($no) . '</span>';
    }

    /** Key/value grid. Values are pre-escaped HTML. */
    public static function kv(array $pairs): string
    {
        $rows = '';
        foreach ($pairs as $k => $v) {
            if ($v === null || $v === '') {
                continue;
            }
            $rows .= '<div class="kv-row"><div class="kv-k">' . self::esc((string) $k)
                . '</div><div class="kv-v">' . $v . '</div></div>';
        }
        return $rows === '' ? '<p class="empty">Nothing configured.</p>' : '<div class="kv">' . $rows . '</div>';
    }

    /**
     * Table. Headers are plain text; cells are pre-escaped HTML.
     * @param array<int,string> $headers
     * @param array<int,array<int,string>> $rows
     */
    public static function table(array $headers, array $rows, string $class = ''): string
    {
        if (!$rows) {
            return '<p class="empty">None.</p>';
        }
        $h = '';
        foreach ($headers as $x) {
            $h .= '<th>' . self::esc($x) . '</th>';
        }
        $b = '';
        $colspan = count($headers);
        foreach ($rows as $r) {
            $cls = '';
            if (isset($r['_class'])) {
                $cls = ' class="' . self::esc((string) $r['_class']) . '"';
                unset($r['_class']);
            }
            // A row carrying _span renders as one full-width cell (used for
            // pfSense's rule separators).
            if (isset($r['_span'])) {
                $b .= '<tr' . $cls . '><td colspan="' . $colspan . '">' . $r['_span'] . '</td></tr>';
                continue;
            }
            $b .= '<tr' . $cls . '>';
            foreach ($r as $c) {
                $b .= '<td>' . $c . '</td>';
            }
            $b .= '</tr>';
        }
        $c = 'data' . ($class !== '' ? ' ' . $class : '');
        return '<div class="table-wrap"><table class="' . $c . '"><thead><tr>' . $h . '</tr></thead><tbody>' . $b . '</tbody></table></div>';
    }

    public static function when(string $epoch): string
    {
        $e = (int) $epoch;
        if ($e <= 0) {
            return '<span class="empty">—</span>';
        }
        return '<time title="' . self::esc(date('Y-m-d H:i:s', $e)) . '">' . self::esc(date('Y-m-d', $e)) . '</time>';
    }

    public static function bytes(int $n): string
    {
        $u = ['B', 'KB', 'MB', 'GB'];
        $i = 0;
        $f = (float) $n;
        while ($f >= 1024 && $i < count($u) - 1) {
            $f /= 1024;
            $i++;
        }
        return round($f, $f < 10 && $i > 0 ? 1 : 0) . ' ' . $u[$i];
    }

    /** Turn a 5-field cron spec into something readable. */
    public static function cronHuman(string $min, string $hour, string $mday, string $month, string $wday): string
    {
        $days = [0 => 'Sunday', 1 => 'Monday', 2 => 'Tuesday', 3 => 'Wednesday', 4 => 'Thursday', 5 => 'Friday', 6 => 'Saturday', 7 => 'Sunday'];

        if (preg_match('~^\*/(\d+)$~', $min, $m) && $hour === '*' && $mday === '*' && $month === '*' && $wday === '*') {
            $n = (int) $m[1];
            if ($n === 1)  return 'every minute';
            if ($n >= 60)  return 'every ' . round($n / 60, 1) . ' hour(s)';
            return "every $n minutes";
        }
        if ($min === '*' && $hour === '*') {
            return 'every minute';
        }

        $time = (ctype_digit($min) && ctype_digit($hour))
            ? sprintf('%02d:%02d', (int) $hour, (int) $min)
            : "min $min, hour $hour";

        if ($mday === '*' && $month === '*' && $wday === '*') {
            return "$time daily";
        }
        if ($wday !== '*' && $mday === '*') {
            $name = $days[(int) $wday] ?? "weekday $wday";
            return "$time on $name";
        }
        if ($mday !== '*' && $month === '*') {
            return "$time on day $mday of each month";
        }
        return "$time (m=$mday mo=$month wd=$wday)";
    }

    /** True when a node has no children and no text. */
    public static function isEmptyNode(?SimpleXMLElement $n): bool
    {
        if ($n === null) {
            return true;
        }
        return count($n->children()) === 0 && trim((string) $n) === '';
    }

    /**
     * Generic recursive renderer for sections with no bespoke view.
     * Secrets are routed through Secrets::redact().
     */
    public static function dump(SimpleXMLElement $node, int $depth = 0, string $name = ''): string
    {
        if ($depth > 8) {
            return '<span class="empty">… nesting too deep to display</span>';
        }

        $children = $node->children();
        if (count($children) === 0) {
            $text = trim((string) $node);
            if (Secrets::isSecret($name)) {
                return Secrets::redact($text);
            }
            if ($text === '') {
                return '<span class="empty">set, no value</span>';
            }
            if (strlen($text) > 400) {
                return '<details><summary>' . self::esc(strlen($text)) . ' characters</summary><pre>' . self::esc($text) . '</pre></details>';
            }
            return '<span class="mono">' . self::esc($text) . '</span>';
        }

        // Group by child name so repeated elements collapse into one block.
        $groups = [];
        foreach ($children as $childName => $child) {
            $groups[$childName][] = $child;
        }

        $rows = '';
        foreach ($groups as $childName => $items) {
            $label = self::esc((string) $childName);
            if (count($items) > 1) {
                $label .= ' <span class="count">' . count($items) . '</span>';
                $inner = '';
                foreach ($items as $i => $item) {
                    $inner .= '<div class="dump-item"><div class="dump-idx">#' . ($i + 1) . '</div>'
                        . self::dump($item, $depth + 1, (string) $childName) . '</div>';
                }
                $value = $inner;
            } else {
                $value = self::dump($items[0], $depth + 1, (string) $childName);
            }
            $rows .= '<div class="kv-row"><div class="kv-k">' . $label . '</div><div class="kv-v">' . $value . '</div></div>';
        }
        return '<div class="kv kv-nested">' . $rows . '</div>';
    }
}
