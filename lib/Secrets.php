<?php
/**
 * Detects sensitive fields, redacts them behind a click-to-reveal span,
 * and decodes X.509 certificates for display.
 */
class Secrets
{
    /** Element names whose value must never render in plaintext. */
    private const SECRET_FIELDS = [
        'prv', 'tls', 'preauthkey', 'password', 'bcrypt-hash', 'md5-hash',
        'nt-hash', 'ipsecpsk', 'authorizedkeys', 'rocommunity', 'rwcommunity',
        'shared_key', 'presharedkey', 'pre-shared-key', 'privatekey',
        'publickey', 'passwordagain', 'ddnsdomainkey', 'apikey', 'auth_token',
        'tls_key', 'secret',
    ];

    public static function isSecret(string $field): bool
    {
        return in_array(strtolower($field), self::SECRET_FIELDS, true);
    }

    /**
     * Render a value as a redacted, click-to-reveal span. The real value is
     * base64'd into a data attribute — it still reaches the browser, which is
     * the accepted trade-off for reveal-on-demand.
     */
    public static function redact(string $value): string
    {
        if ($value === '') {
            return '<span class="empty">not set</span>';
        }
        $len = strlen($value);
        return '<span class="secret" role="button" tabindex="0"'
            . ' data-secret="' . htmlspecialchars(base64_encode($value), ENT_QUOTES) . '"'
            . ' title="Click to reveal (' . $len . ' bytes)">'
            . 'redacted &middot; click to reveal</span>';
    }

    /**
     * Parse a base64-wrapped PEM certificate into display fields.
     * Returns null when it cannot be parsed.
     */
    public static function describeCert(string $b64): ?array
    {
        $pem = base64_decode(trim($b64), true);
        if ($pem === false || strpos($pem, 'BEGIN CERTIFICATE') === false) {
            return null;
        }
        if (!function_exists('openssl_x509_parse')) {
            return ['unparsed' => true, 'bytes' => strlen($pem)];
        }
        $info = @openssl_x509_parse($pem);
        if (!$info) {
            return ['unparsed' => true, 'bytes' => strlen($pem)];
        }

        $from = $info['validFrom_time_t'] ?? null;
        $to   = $info['validTo_time_t'] ?? null;
        $days = $to ? (int) floor(($to - time()) / 86400) : null;

        return [
            'subject'    => self::dn($info['subject'] ?? []),
            'issuer'     => self::dn($info['issuer'] ?? []),
            'cn'         => $info['subject']['CN'] ?? '',
            'issuer_cn'  => $info['issuer']['CN'] ?? '',
            'serial'     => $info['serialNumberHex'] ?? ($info['serialNumber'] ?? ''),
            'from'       => $from,
            'to'         => $to,
            'days_left'  => $days,
            'self_signed' => ($info['subject'] ?? null) == ($info['issuer'] ?? null),
            'sans'       => self::sans($info),
            'purposes'   => self::purposes($info),
            'bytes'      => strlen($pem),
        ];
    }

    /** Expiry state for badge colouring: expired | soon | ok */
    public static function expiryState(?int $daysLeft): string
    {
        if ($daysLeft === null) {
            return 'unknown';
        }
        if ($daysLeft < 0)  return 'expired';
        if ($daysLeft <= 30) return 'soon';
        return 'ok';
    }

    private static function dn(array $parts): string
    {
        $out = [];
        foreach ($parts as $k => $v) {
            $out[] = $k . '=' . (is_array($v) ? implode('/', $v) : $v);
        }
        return implode(', ', $out);
    }

    private static function sans(array $info): array
    {
        $raw = $info['extensions']['subjectAltName'] ?? '';
        if ($raw === '') {
            return [];
        }
        return array_map('trim', explode(',', $raw));
    }

    private static function purposes(array $info): array
    {
        $raw = $info['extensions']['extendedKeyUsage'] ?? '';
        if ($raw === '') {
            return [];
        }
        return array_map('trim', explode(',', $raw));
    }
}
