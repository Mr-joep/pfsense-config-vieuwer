<?php
/**
 * Turns pfSense's internal keys into something a human can read:
 * opt3 -> "PC", wanip -> "WAN address", lagg0 -> "LACP: ix2 + ix3".
 */
class Resolve
{
    /** @var array<string,array> interface key => details */
    private array $ifaces = [];
    /** @var array<string,SimpleXMLElement> alias name => alias node */
    private array $aliases = [];
    /** @var array<string,string> refid => description */
    private array $certs = [];
    private array $cas = [];
    /** @var array<string,array> physical if name => description parts */
    private array $vlans = [];
    private array $laggs = [];
    private array $ppps = [];
    private array $ifgroups = [];

    /** Interface keys referenced anywhere but absent from <interfaces>. */
    private array $undefined = [];

    public function __construct(private SimpleXMLElement $cfg)
    {
        foreach ($cfg->interfaces->children() ?? [] as $key => $node) {
            $this->ifaces[$key] = [
                'key'     => $key,
                'descr'   => self::text($node->descr) !== '' ? self::text($node->descr) : strtoupper($key),
                'if'      => self::text($node->if),
                'ipaddr'  => self::text($node->ipaddr),
                'subnet'  => self::text($node->subnet),
                'enabled' => isset($node->enable),
                'node'    => $node,
            ];
        }
        foreach ($cfg->aliases->alias ?? [] as $a) {
            $this->aliases[self::text($a->name)] = $a;
        }
        foreach ($cfg->cert ?? [] as $c) {
            $this->certs[self::text($c->refid)] = self::text($c->descr);
        }
        foreach ($cfg->ca ?? [] as $c) {
            $this->cas[self::text($c->refid)] = self::text($c->descr);
        }
        foreach ($cfg->vlans->vlan ?? [] as $v) {
            $this->vlans[self::text($v->vlanif)] = [
                'parent' => self::text($v->if),
                'tag'    => self::text($v->tag),
                'descr'  => self::text($v->descr),
            ];
        }
        foreach ($cfg->laggs->lagg ?? [] as $l) {
            $this->laggs[self::text($l->laggif)] = [
                'members' => self::text($l->members),
                'proto'   => self::text($l->proto),
                'descr'   => self::text($l->descr),
            ];
        }
        foreach ($cfg->ppps->ppp ?? [] as $p) {
            $this->ppps[self::text($p->if)] = [
                'type'  => self::text($p->type),
                'ports' => self::text($p->ports),
            ];
        }
        foreach ($cfg->ifgroups->ifgroupentry ?? [] as $g) {
            $this->ifgroups[self::text($g->ifname)] = self::text($g->descr);
        }
    }

    public static function text($node): string
    {
        if ($node === null) {
            return '';
        }
        return trim((string) $node);
    }

    public function interfaces(): array
    {
        return $this->ifaces;
    }

    public function aliases(): array
    {
        return $this->aliases;
    }

    public function undefinedInterfaces(): array
    {
        ksort($this->undefined);
        return array_keys($this->undefined);
    }

    /** Short friendly name only: "PC", "WAN", "OpenVPN". */
    public function ifName(string $key): string
    {
        if (isset($this->ifaces[$key])) {
            return $this->ifaces[$key]['descr'];
        }
        return $this->specialIfName($key) ?? $key;
    }

    /** Named pseudo-interfaces that never appear in <interfaces>. */
    private function specialIfName(string $key): ?string
    {
        $known = [
            'openvpn'  => 'OpenVPN',
            'enc0'     => 'IPsec (enc0)',
            'l2tp'     => 'L2TP',
            'floating' => 'Floating',
            'lo0'      => 'Loopback',
        ];
        $lower = strtolower($key);
        if (isset($known[$lower])) {
            return $known[$lower];
        }
        if (isset($this->ifgroups[$key])) {
            return $key . ' (interface group)';
        }
        return null;
    }

    /**
     * Full HTML label: "PC <small>opt3 · ix1 · 192.168.10.1/24</small>",
     * or a warning badge when the key is not defined in this config.
     */
    public function ifLabel(string $key, bool $withDetail = true): string
    {
        if ($key === '') {
            return '<span class="empty">—</span>';
        }

        // Floating rules carry a comma-separated interface list.
        if (strpos($key, ',') !== false) {
            $parts = array_map(fn($k) => $this->ifLabel(trim($k), false), explode(',', $key));
            return implode(', ', $parts);
        }

        if (isset($this->ifaces[$key])) {
            $i = $this->ifaces[$key];
            $out = '<strong>' . self::esc($i['descr']) . '</strong>';
            if ($withDetail) {
                $bits = [$key];
                if ($i['if'] !== '')     $bits[] = $i['if'];
                if ($i['ipaddr'] !== '') $bits[] = $this->addrSummary($i);
                $out .= ' <small class="muted">' . self::esc(implode(' · ', $bits)) . '</small>';
            }
            return $out;
        }

        $special = $this->specialIfName($key);
        if ($special !== null) {
            return '<strong>' . self::esc($special) . '</strong>';
        }

        $this->undefined[$key] = true;
        return '<strong>' . self::esc($key) . '</strong> '
            . '<span class="badge badge-warn" title="This interface is referenced here but has no entry in the &lt;interfaces&gt; section of this backup.">not defined in this config</span>';
    }

    private function addrSummary(array $i): string
    {
        $ip = $i['ipaddr'];
        if ($ip === 'dhcp')  return 'DHCP';
        if ($ip === 'pppoe') return 'PPPoE';
        if ($ip === 'ppp')   return 'PPP';
        if ($ip === '')      return 'no address';
        return $i['subnet'] !== '' ? "$ip/{$i['subnet']}" : $ip;
    }

    /** Human description of a physical interface: pppoe0, lagg0, ix0.6, re4. */
    public function physical(string $if): string
    {
        if ($if === '') {
            return '<span class="empty">—</span>';
        }
        $out = '<code>' . self::esc($if) . '</code>';

        if (isset($this->ppps[$if])) {
            $p = $this->ppps[$if];
            $over = $p['ports'];
            $detail = strtoupper($p['type']) . ' over ' . $over;
            if (isset($this->vlans[$over])) {
                $v = $this->vlans[$over];
                $detail .= ' (VLAN ' . $v['tag'] . ' on ' . $v['parent'] . ')';
            }
            return $out . ' <small class="muted">' . self::esc($detail) . '</small>';
        }
        if (isset($this->laggs[$if])) {
            $l = $this->laggs[$if];
            $members = implode(' + ', array_map('trim', explode(',', $l['members'])));
            return $out . ' <small class="muted">' . self::esc(strtoupper($l['proto']) . ': ' . $members) . '</small>';
        }
        if (isset($this->vlans[$if])) {
            $v = $this->vlans[$if];
            return $out . ' <small class="muted">' . self::esc('VLAN ' . $v['tag'] . ' on ' . $v['parent']) . '</small>';
        }
        return $out;
    }

    /**
     * Render a rule's <source> or <destination> block.
     */
    public function endpoint(?SimpleXMLElement $node): string
    {
        if ($node === null) {
            return '<span class="empty">—</span>';
        }
        $neg = isset($node->not) ? '<span class="neg">NOT</span> ' : '';

        if (isset($node->any)) {
            $what = '<em>any</em>';
        } elseif (isset($node->address) && self::text($node->address) !== '') {
            $what = $this->addressToken(self::text($node->address));
        } elseif (isset($node->network) && self::text($node->network) !== '') {
            $what = $this->addressToken(self::text($node->network));
        } else {
            $what = '<em>any</em>';
        }

        $port = self::text($node->port ?? null);
        if ($port !== '') {
            $what .= ' <span class="port">:' . self::esc(str_replace(':', '–', $port)) . '</span>';
        }
        return $neg . $what;
    }

    /**
     * A single address token: interface magic word, alias name, or literal.
     */
    public function addressToken(string $t): string
    {
        if ($t === '' || $t === 'any') {
            return '<em>any</em>';
        }
        if ($t === '(self)') {
            return '<em>This firewall</em>';
        }

        // "<key>ip" means the interface's own address.
        if (substr($t, -2) === 'ip') {
            $key = substr($t, 0, -2);
            if (isset($this->ifaces[$key]) || $this->specialIfName($key) !== null) {
                return self::esc($this->ifName($key)) . ' <small class="muted">address</small>';
            }
        }
        // A bare interface key means that interface's subnet.
        if (isset($this->ifaces[$t]) || $this->specialIfName($t) !== null) {
            return self::esc($this->ifName($t)) . ' <small class="muted">net</small>';
        }
        // An alias.
        if (isset($this->aliases[$t])) {
            $a = $this->aliases[$t];
            $contents = self::text($a->address);
            $tip = self::text($a->descr);
            if ($contents !== '') {
                $items = preg_split('~\s+~', $contents) ?: [];
                $tip .= ($tip !== '' ? ' — ' : '') . count($items) . ' entries: '
                    . implode(', ', array_slice($items, 0, 12))
                    . (count($items) > 12 ? ', …' : '');
            }
            return '<a class="alias" href="#aliases" title="' . self::esc($tip) . '">'
                . self::esc($t) . '</a>';
        }
        return '<code>' . self::esc($t) . '</code>';
    }

    public function certName(string $refid): string
    {
        if ($refid === '') {
            return '<span class="empty">—</span>';
        }
        if (isset($this->certs[$refid])) {
            return self::esc($this->certs[$refid]) . ' <small class="muted">' . self::esc($refid) . '</small>';
        }
        return '<code>' . self::esc($refid) . '</code> <span class="badge badge-warn">unknown cert</span>';
    }

    public function caName(string $refid): string
    {
        if ($refid === '') {
            return '<span class="empty">—</span>';
        }
        if (isset($this->cas[$refid])) {
            return self::esc($this->cas[$refid]) . ' <small class="muted">' . self::esc($refid) . '</small>';
        }
        return '<code>' . self::esc($refid) . '</code> <span class="badge badge-warn">unknown CA</span>';
    }

    /** "lan,opt1,opt3" -> "LAN, fast20GB, PC" */
    public function ifList(string $csv): string
    {
        if (trim($csv) === '') {
            return '<span class="empty">—</span>';
        }
        $out = [];
        foreach (explode(',', $csv) as $k) {
            $k = trim($k);
            if ($k === '') continue;
            $out[] = self::esc($this->ifName($k)) . ' <small class="muted">' . self::esc($k) . '</small>';
        }
        return implode(', ', $out);
    }

    /**
     * Walk every place an interface key can appear and record the ones that
     * have no entry in <interfaces>. Called once before rendering so the
     * overview can report stale references that later sections would find.
     */
    public function prescan(): void
    {
        $note = function (string $csv): void {
            foreach (explode(',', $csv) as $key) {
                $key = trim($key);
                if ($key === '' || isset($this->ifaces[$key]) || $this->specialIfName($key) !== null) {
                    continue;
                }
                $this->undefined[$key] = true;
            }
        };

        foreach ($this->cfg->filter->rule ?? [] as $r) {
            $note(self::text($r->interface));
        }
        foreach ($this->cfg->nat->rule ?? [] as $r) {
            $note(self::text($r->interface));
        }
        foreach ($this->cfg->nat->outbound->rule ?? [] as $r) {
            $note(self::text($r->interface));
        }
        foreach ($this->cfg->gateways->gateway_item ?? [] as $g) {
            $note(self::text($g->interface));
        }
        foreach ($this->cfg->openvpn->{'openvpn-server'} ?? [] as $s) {
            $note(self::text($s->interface));
        }
        foreach (['dhcpd', 'dhcpdv6'] as $sec) {
            foreach ($this->cfg->{$sec}->children() ?? [] as $key => $scope) {
                if (isset($scope->range) || isset($scope->staticmap)) {
                    $note((string) $key);
                }
            }
        }
        $note(self::text($this->cfg->unbound->active_interface));
        $note(self::text($this->cfg->unbound->outgoing_interface));
        $note(self::text($this->cfg->installedpackages->arpwatch->config->active_interfaces ?? null));
    }

    public static function esc(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
