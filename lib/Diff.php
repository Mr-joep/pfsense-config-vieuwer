<?php
/**
 * Compares two flattened configs and classifies every path as added,
 * removed, changed or identical.
 */
class Diff
{
    public const ADDED   = 'added';
    public const REMOVED = 'removed';
    public const CHANGED = 'changed';

    /** Exact paths that differ between any two backups as pure bookkeeping. */
    private const NOISE_EXACT = [
        'revision/time',
        'revision/description',
        'revision/username',
        'lastchange',
    ];

    /** Path endings that record who touched a rule and when, not what it does. */
    private const NOISE_SUFFIX = [
        '/updated/time',
        '/updated/username',
        '/created/time',
        '/created/username',
    ];

    public static function isNoise(string $path): bool
    {
        if (in_array($path, self::NOISE_EXACT, true)) {
            return true;
        }
        foreach (self::NOISE_SUFFIX as $suffix) {
            if (substr($path, -strlen($suffix)) === $suffix) {
                return true;
            }
        }
        return false;
    }

    /**
     * @param array<string,string> $a
     * @param array<string,string> $b
     * @return array{entries:array<int,array>,stats:array<string,int>}
     */
    public static function compare(array $a, array $b, bool $hideNoise): array
    {
        $keys = array_keys($a + $b);
        sort($keys, SORT_NATURAL | SORT_FLAG_CASE);

        $entries = [];
        $stats = ['changed' => 0, 'added' => 0, 'removed' => 0, 'same' => 0, 'noise' => 0];

        foreach ($keys as $key) {
            $inA = array_key_exists($key, $a);
            $inB = array_key_exists($key, $b);

            if ($inA && $inB) {
                if ($a[$key] === $b[$key]) {
                    $stats['same']++;
                    continue;
                }
                $kind = self::CHANGED;
            } elseif ($inB) {
                $kind = self::ADDED;
            } else {
                $kind = self::REMOVED;
            }

            if (self::isNoise($key)) {
                $stats['noise']++;
                if ($hideNoise) {
                    continue;
                }
            }

            $stats[$kind]++;
            $entries[] = [
                'path'    => $key,
                'kind'    => $kind,
                'a'       => $inA ? $a[$key] : null,
                'b'       => $inB ? $b[$key] : null,
                'section' => self::section($key),
                'field'   => self::field($key),
            ];
        }

        return ['entries' => $entries, 'stats' => $stats];
    }

    /** Top-level config section a path belongs to. */
    public static function section(string $path): string
    {
        $first = explode('/', $path)[0];
        return preg_replace('~\[.*$~', '', $first) ?? $first;
    }

    /** The leaf element name, used to decide whether a value is a secret. */
    public static function field(string $path): string
    {
        $last = substr(strrchr('/' . $path, '/') ?: '', 1);
        return preg_replace('~\[.*$~', '', $last) ?? $last;
    }

    /** Group entries by section, preserving order. */
    public static function bySection(array $entries): array
    {
        $out = [];
        foreach ($entries as $e) {
            $out[$e['section']][] = $e;
        }
        return $out;
    }
}
