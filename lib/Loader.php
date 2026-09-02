<?php
/**
 * Locates, validates and parses pfSense config backups.
 */
class Loader
{
    /** Directory uploads land in. */
    public static function uploadDir(): string
    {
        return __DIR__ . '/../uploads';
    }

    /** Extra directory scanned for pre-existing configs (the htdocs root). */
    public static function scanDir(): string
    {
        return realpath(__DIR__ . '/../..') ?: (__DIR__ . '/../..');
    }

    /**
     * Every config file we can offer, newest first.
     * @return array<int,array{path:string,name:string,hostname:string,revision:?int,size:int,source:string}>
     */
    public static function listConfigs(): array
    {
        $found = [];

        foreach (glob(self::uploadDir() . '/*.xml') ?: [] as $p) {
            $found[] = ['path' => $p, 'source' => 'upload'];
        }
        foreach (glob(self::scanDir() . '/config*.xml') ?: [] as $p) {
            $found[] = ['path' => $p, 'source' => 'htdocs'];
        }

        $out = [];
        foreach ($found as $f) {
            $meta = self::peek($f['path']);
            if ($meta === null) {
                continue; // not a pfSense config
            }
            $out[] = [
                'path'     => $f['path'],
                'name'     => basename($f['path']),
                'source'   => $f['source'],
                'hostname' => $meta['hostname'],
                'version'  => $meta['version'],
                'revision' => $meta['revision'],
                'size'     => filesize($f['path']) ?: 0,
            ];
        }

        usort($out, fn($a, $b) => ($b['revision'] ?? 0) <=> ($a['revision'] ?? 0));
        return $out;
    }

    /**
     * Cheap header read: pull hostname/version/revision without parsing 7 MB.
     * Returns null if the file is not a pfSense config.
     */
    public static function peek(string $path): ?array
    {
        $fh = @fopen($path, 'rb');
        if (!$fh) {
            return null;
        }
        $head = fread($fh, 8192) ?: '';
        fclose($fh);

        if (strpos($head, '<pfsense>') === false) {
            return null;
        }

        $grab = static function (string $tag) use ($head): string {
            if (preg_match('~<' . $tag . '>(?:<!\[CDATA\[)?(.*?)(?:\]\]>)?</' . $tag . '>~s', $head, $m)) {
                return trim($m[1]);
            }
            return '';
        };

        // Revision lives at the end of the file, not the head.
        $revision = null;
        $tail = self::tail($path, 4096);
        if (preg_match('~<revision>.*?<time>(\d+)</time>~s', $tail, $m)) {
            $revision = (int) $m[1];
        }

        $host   = $grab('hostname');
        $domain = $grab('domain');

        return [
            'hostname' => $host !== '' ? ($domain !== '' ? "$host.$domain" : $host) : basename($path),
            'version'  => $grab('version'),
            'revision' => $revision,
        ];
    }

    private static function tail(string $path, int $bytes): string
    {
        $size = filesize($path) ?: 0;
        $fh = @fopen($path, 'rb');
        if (!$fh) {
            return '';
        }
        fseek($fh, max(0, $size - $bytes));
        $data = stream_get_contents($fh) ?: '';
        fclose($fh);
        return $data;
    }

    /**
     * Parse a config into SimpleXML, with <rrddata> stripped out first —
     * it is ~7 MB of base64 graph data and is never rendered.
     *
     * @return array{xml:SimpleXMLElement,rrd:array{present:bool,datasets:int,bytes:int}}
     * @throws RuntimeException
     */
    public static function load(string $path): array
    {
        $real = realpath($path);
        if ($real === false || !is_file($real) || !self::inAllowedDir($real)) {
            throw new RuntimeException('Config file not found or not allowed.');
        }

        $raw = file_get_contents($real);
        if ($raw === false) {
            throw new RuntimeException('Could not read the config file.');
        }

        $rrd = ['present' => false, 'datasets' => 0, 'bytes' => 0];
        $start = strpos($raw, '<rrddata>');
        $end   = strpos($raw, '</rrddata>');
        if ($start !== false && $end !== false && $end > $start) {
            $len = $end + strlen('</rrddata>') - $start;
            $chunk = substr($raw, $start, $len);
            $rrd = [
                'present'  => true,
                'datasets' => substr_count($chunk, '<rrddatafile>'),
                'bytes'    => $len,
            ];
            $raw = substr_replace($raw, '', $start, $len);
        }

        $prev = libxml_use_internal_errors(true);
        libxml_clear_errors();
        $xml = simplexml_load_string($raw, 'SimpleXMLElement', LIBXML_NOCDATA | LIBXML_NOBLANKS);
        $errors = libxml_get_errors();
        libxml_clear_errors();
        libxml_use_internal_errors($prev);

        if ($xml === false) {
            $first = $errors[0]->message ?? 'unknown parse error';
            throw new RuntimeException('Not valid XML: ' . trim($first));
        }
        if ($xml->getName() !== 'pfsense') {
            throw new RuntimeException('Root element is <' . $xml->getName() . '>, not <pfsense>. This is not a pfSense backup.');
        }

        return ['xml' => $xml, 'rrd' => $rrd];
    }

    /** Only files inside the upload dir or the scan dir may be opened. */
    public static function inAllowedDir(string $realPath): bool
    {
        foreach ([self::uploadDir(), self::scanDir()] as $dir) {
            $d = realpath($dir);
            if ($d !== false && strncmp($realPath, $d . DIRECTORY_SEPARATOR, strlen($d) + 1) === 0) {
                return true;
            }
        }
        return false;
    }

    public const MAX_UPLOAD = 20 * 1024 * 1024;

    /**
     * Validate and store an uploaded config.
     * @return array{ok:bool,message:string,path:?string}
     */
    public static function handleUpload(array $file): array
    {
        $fail = fn(string $m) => ['ok' => false, 'message' => $m, 'path' => null];

        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            return $fail(self::uploadErrorText((int) ($file['error'] ?? UPLOAD_ERR_NO_FILE)));
        }
        if (($file['size'] ?? 0) > self::MAX_UPLOAD) {
            return $fail('File is larger than ' . round(self::MAX_UPLOAD / 1048576) . ' MB.');
        }
        if (strtolower(pathinfo($file['name'] ?? '', PATHINFO_EXTENSION)) !== 'xml') {
            return $fail('Only .xml files are accepted.');
        }
        if (!is_uploaded_file($file['tmp_name'])) {
            return $fail('Upload failed validation.');
        }

        // Confirm it really is a pfSense config before keeping it.
        if (self::peek($file['tmp_name']) === null) {
            return $fail('That file does not look like a pfSense backup (no <pfsense> root element).');
        }

        $dir = self::uploadDir();
        if (!is_dir($dir) && !@mkdir($dir, 0777, true) && !is_dir($dir)) {
            return $fail('Could not create the uploads directory.');
        }

        $base = preg_replace('~[^A-Za-z0-9._-]+~', '_', pathinfo($file['name'], PATHINFO_FILENAME));
        $base = trim(substr($base, 0, 60), '._-');
        $dest = $dir . '/' . bin2hex(random_bytes(4)) . '-' . ($base !== '' ? $base : 'config') . '.xml';

        if (!move_uploaded_file($file['tmp_name'], $dest)) {
            return $fail('Could not save the uploaded file.');
        }

        return ['ok' => true, 'message' => 'Uploaded ' . htmlspecialchars($file['name']) . '.', 'path' => $dest];
    }

    private static function uploadErrorText(int $code): string
    {
        switch ($code) {
            case UPLOAD_ERR_INI_SIZE:
            case UPLOAD_ERR_FORM_SIZE:
                return 'The file is bigger than the server upload limit (upload_max_filesize).';
            case UPLOAD_ERR_PARTIAL:
                return 'The upload was interrupted.';
            case UPLOAD_ERR_NO_FILE:
                return 'No file was selected.';
            case UPLOAD_ERR_NO_TMP_DIR:
                return 'PHP has no temp directory to write to.';
            case UPLOAD_ERR_CANT_WRITE:
                return 'PHP could not write the file to disk.';
            default:
                return 'Upload failed (code ' . $code . ').';
        }
    }
}
