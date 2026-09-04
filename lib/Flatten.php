<?php
/**
 * Flattens a pfSense config into path => value pairs so two backups can be
 * compared.
 *
 * Repeated elements are keyed by a stable identity where one exists — a
 * firewall rule's <tracker>, an alias's <name>, a certificate's <refid> —
 * rather than by position. Without that, inserting a single rule at the top
 * would report every rule below it as changed.
 */
class Flatten
{
    /** Path shape => fields identifying one entry; the first non-empty wins. */
    private const IDENTITY = [
        'filter/rule'                => ['tracker', 'descr'],
        'nat/rule'                   => ['associated-rule-id', 'descr'],
        'nat/outbound/rule'          => ['descr'],
        'nat/onetoone'               => ['descr'],
        'aliases/alias'              => ['name'],
        'cert'                       => ['refid'],
        'ca'                         => ['refid'],
        'crl'                        => ['refid'],
        'system/user'                => ['name'],
        'system/group'               => ['name'],
        'gateways/gateway_item'      => ['name'],
        'gateways/gateway_group'     => ['name'],
        'staticroutes/route'         => ['network'],
        'cron/item'                  => ['command'],
        'installedpackages/package'  => ['internal_name', 'name'],
        'installedpackages/service'  => ['name'],
        'installedpackages/menu'     => ['name'],
        'vlans/vlan'                 => ['vlanif'],
        'laggs/lagg'                 => ['laggif'],
        'ppps/ppp'                   => ['if'],
        'ifgroups/ifgroupentry'      => ['ifname'],
        'bridges/bridged'            => ['bridgeif'],
        'openvpn/openvpn-server'     => ['vpnid', 'description'],
        'openvpn/openvpn-client'     => ['vpnid', 'description'],
        'unbound/hosts'              => ['host'],
        'unbound/domainoverrides'    => ['domain'],
        'dhcpd/*/staticmap'          => ['mac', 'ipaddr', 'hostname'],
        'dhcpdv6/*/staticmap'        => ['duid', 'ipaddrv6'],
    ];

    /** @return array<string,string> path => value */
    public static function run(SimpleXMLElement $cfg): array
    {
        $out = [];
        self::walk($cfg, '', $out, 0);
        return $out;
    }

    private static function walk(SimpleXMLElement $node, string $path, array &$out, int $depth): void
    {
        if ($depth > 12) {
            return;
        }

        $children = $node->children();
        if (count($children) === 0) {
            $out[$path] = trim((string) $node);
            return;
        }

        $groups = [];
        foreach ($children as $name => $child) {
            $groups[(string) $name][] = $child;
        }

        foreach ($groups as $name => $items) {
            $childPath = ($path === '' ? '' : $path . '/') . $name;
            $fields = self::identityFor($childPath);

            // A lone element with no identity rule keeps its plain path.
            // Elements that DO have one are always keyed, even when there is
            // only one of them — otherwise a list shrinking from two entries
            // to one would re-path and read as "everything replaced".
            if (count($items) === 1 && !$fields) {
                self::walk($items[0], $childPath, $out, $depth + 1);
                continue;
            }

            $seen = [];
            foreach ($items as $i => $item) {
                $key = '';
                if ($fields) {
                    $key = self::identity($item, $fields);
                } elseif (count($item->children()) === 0) {
                    // Repeated scalar list: key by the value itself.
                    $key = trim((string) $item);
                }
                if ($key === '') {
                    $key = '#' . $i;
                }
                $base = $key;
                $n = 1;
                while (isset($seen[$key])) {
                    $key = $base . '~' . (++$n);
                }
                $seen[$key] = true;
                self::walk($item, $childPath . '[' . $key . ']', $out, $depth + 1);
            }
        }
    }

    private static function identity(SimpleXMLElement $item, array $fields): string
    {
        foreach ($fields as $f) {
            $v = trim((string) ($item->{$f} ?? ''));
            if ($v !== '') {
                return strlen($v) > 60 ? substr($v, 0, 60) . '...' : $v;
            }
        }
        return '';
    }

    /** Identity fields for a path, ignoring any bracketed keys already in it. */
    private static function identityFor(string $path): array
    {
        $shape = preg_replace('~\[[^\]]*\]~', '', $path) ?? $path;

        if (array_key_exists($shape, self::IDENTITY)) {
            return self::IDENTITY[$shape];
        }
        foreach (self::IDENTITY as $pattern => $fields) {
            if (strpos($pattern, '*') !== false && self::matches($pattern, $shape)) {
                return $fields;
            }
        }
        return [];
    }

    private static function matches(string $pattern, string $path): bool
    {
        $p = explode('/', $pattern);
        $q = explode('/', $path);
        if (count($p) !== count($q)) {
            return false;
        }
        foreach ($p as $i => $seg) {
            if ($seg !== '*' && $seg !== $q[$i]) {
                return false;
            }
        }
        return true;
    }
}
