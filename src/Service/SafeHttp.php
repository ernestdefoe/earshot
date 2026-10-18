<?php

namespace ErnestDefoe\Earshot\Service;

/**
 * The only way Earshot reaches another server (feeds, chapters, artwork),
 * built for addresses people type in: any of them may be aimed at this
 * server's own network. Taken from Waypoint, which was audited for it.
 *
 * - http and https only, on ports 80 and 443, with no user:pass@ part;
 * - the host is resolved here, every address it resolves to must be public,
 *   and curl is pinned to that checked address (CURLOPT_RESOLVE), so a
 *   zero-TTL record cannot swap in 127.0.0.1 between the check and the
 *   connection (DNS rebinding);
 * - numeric host shorthands (0177.0.0.1, 2130706433, 127.1) are refused:
 *   PHP does not read them as IPs, curl does;
 * - redirects are followed here, not by curl, so each hop is checked again;
 * - a byte cap and a time cap on every request, and no proxy from the
 *   environment, which would make the pinning meaningless.
 */
class SafeHttp
{
    public const MAX_REDIRECTS = 4;

    private const BLOCKED = [
        '0.0.0.0/8', '100.64.0.0/10', '192.0.0.0/24', '198.18.0.0/15', '224.0.0.0/4', '240.0.0.0/4',
        '::ffff:0:0/96', '64:ff9b::/96', '64:ff9b:1::/48', '2002::/16', '2001::/32', '2001:db8::/32', '100::/64', 'fc00::/7', 'fe80::/10', 'ff00::/8',
    ];

    public function __construct(private string $userAgent = 'Mozilla/5.0 (compatible; Earshot/1.0; podcast feed reader)')
    {
    }

    /**
     * GET a URL, following safe redirects.
     *
     * A body bigger than $maxBytes is an error ('too_big'), never a truncated
     * document: half a feed parses as a feed with episodes missing.
     *
     * @param list<string> $headers extra request headers (If-None-Match and the like)
     * @return array{status: int, type: string, body: string, url: string, etag: string, modified: string}|array{error: string}
     */
    public function get(string $url, int $maxBytes, int $timeout = 6, array $headers = []): array
    {
        $deadline = microtime(true) + $timeout;

        for ($hop = 0; $hop <= self::MAX_REDIRECTS; $hop++) {
            $target = $this->check($url);
            if (is_string($target)) {
                return ['error' => $target];
            }

            $left = $deadline - microtime(true);
            if ($left <= 0.5) {
                return ['error' => 'timeout'];
            }

            $response = $this->request($url, $target, $maxBytes, $left, $headers);
            if (isset($response['error'])) {
                return $response;
            }

            if ($response['status'] >= 300 && $response['status'] < 400 && $response['location'] !== '') {
                $next = self::resolveUrl($url, $response['location']);
                if ($next === null) {
                    return ['error' => 'bad_redirect'];
                }
                $url = $next;

                continue;
            }

            return ['status' => $response['status'], 'type' => $response['type'], 'body' => $response['body'], 'url' => $url, 'etag' => $response['etag'], 'modified' => $response['modified']];
        }

        return ['error' => 'too_many_redirects'];
    }

    /**
     * Whether a URL may be fetched at all, and where to: the host, port and
     * the checked IP to pin, or the reason it may not.
     *
     * @return array{host: string, port: int, ip: string}|string
     */
    public function check(string $url): array|string
    {
        $p = parse_url($url);
        if (! is_array($p) || ! in_array(strtolower($p['scheme'] ?? ''), ['http', 'https'], true) || empty($p['host'])) {
            return 'bad_url';
        }
        if (isset($p['user']) || isset($p['pass'])) {
            return 'bad_url';
        }

        $scheme = strtolower($p['scheme']);
        $port = (int) ($p['port'] ?? ($scheme === 'https' ? 443 : 80));
        if (! in_array($port, [80, 443], true)) {
            return 'bad_port';
        }

        $host = strtolower(trim($p['host'], '[]'));
        $ips = $this->resolve($host);
        if ($ips === []) {
            return 'unresolvable';
        }
        foreach ($ips as $ip) {
            if (! self::isPublic($ip)) {
                return 'blocked';
            }
        }

        return ['host' => $host, 'port' => $port, 'ip' => $ips[0]];
    }

    /**
     * @param array{host: string, port: int, ip: string} $target
     * @param list<string> $headers
     * @return array{status: int, type: string, body: string, location: string, etag: string, modified: string}|array{error: string}
     */
    protected function request(string $url, array $target, int $maxBytes, float $timeout, array $headers = []): array
    {
        $body = '';
        $type = '';
        $location = '';
        $etag = '';
        $modified = '';
        $tooBig = false;

        $ch = curl_init();
        $ip = str_contains($target['ip'], ':') ? '['.$target['ip'].']' : $target['ip'];

        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RESOLVE => [$target['host'].':'.$target['port'].':'.$ip],
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROXY => '',
            CURLOPT_NOPROXY => '*',
            CURLOPT_CONNECTTIMEOUT_MS => (int) min(4000, $timeout * 1000),
            CURLOPT_TIMEOUT_MS => (int) ($timeout * 1000),
            CURLOPT_USERAGENT => $this->userAgent,
            CURLOPT_HTTPHEADER => array_merge(['Accept: application/rss+xml,application/xml,text/xml,application/json,image/*;q=0.9,*/*;q=0.5'], $headers),
            CURLOPT_ENCODING => '',
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_HEADERFUNCTION => function ($ch, string $line) use (&$type, &$location, &$etag, &$modified) {
                if (stripos($line, 'content-type:') === 0) {
                    $type = strtolower(trim(substr($line, 13)));
                } elseif (stripos($line, 'location:') === 0) {
                    $location = trim(substr($line, 9));
                } elseif (stripos($line, 'etag:') === 0) {
                    $etag = mb_substr(trim(substr($line, 5)), 0, 200);
                } elseif (stripos($line, 'last-modified:') === 0) {
                    $modified = mb_substr(trim(substr($line, 14)), 0, 100);
                }

                return strlen($line);
            },
            CURLOPT_WRITEFUNCTION => function ($ch, string $chunk) use (&$body, &$tooBig, $maxBytes) {
                $body .= $chunk;
                if (strlen($body) > $maxBytes) {
                    $body = '';
                    $tooBig = true;

                    return 0;
                }

                return strlen($chunk);
            },
        ]);

        curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $errno = curl_errno($ch);

        if ($tooBig) {
            return ['error' => 'too_big'];
        }
        if ($errno) {
            return ['error' => $errno === CURLE_OPERATION_TIMEDOUT ? 'timeout' : 'unreachable'];
        }

        return ['status' => $status, 'type' => $type, 'body' => $body, 'location' => $location, 'etag' => $etag, 'modified' => $modified];
    }

    /**
     * Resolved addresses for a host: the literal itself if it is a canonical
     * IP, or its A and AAAA records. Nothing else, and no gethostbyname()
     * fallback, which reads numeric shorthands as loopback.
     *
     * @return string[]
     */
    protected function resolve(string $host): array
    {
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return [$host];
        }

        $name = rtrim($host, '.');
        if ($name === '' || ! preg_match('/^[a-z0-9_-]+(\.[a-z0-9_-]+)+$/', $name)) {
            return [];
        }
        // A last label of digits or 0x-hex makes URL parsers read the whole
        // host as an IPv4 shorthand, so it is never a real name.
        $labels = explode('.', $name);
        if (preg_match('/^(0x[0-9a-f]*|[0-9]+)$/', (string) end($labels))) {
            return [];
        }

        $ips = [];
        foreach ((array) @dns_get_record($name, DNS_A | DNS_AAAA) as $r) {
            if (! empty($r['ip'])) {
                $ips[] = $r['ip'];
            }
            if (! empty($r['ipv6'])) {
                $ips[] = $r['ipv6'];
            }
        }

        return $ips;
    }

    /**
     * Not private (RFC 1918, fc00::/7), not reserved (loopback, link-local,
     * 169.254.169.254 among them), and none of the ranges PHP's filter lets
     * through that can still reach an internal host: carrier-grade NAT,
     * benchmark blocks, multicast, and IPv6 forms that embed an IPv4 address.
     */
    public static function isPublic(string $ip): bool
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            return false;
        }
        foreach (self::BLOCKED as $cidr) {
            if (self::inCidr($ip, $cidr)) {
                return false;
            }
        }

        return true;
    }

    private static function inCidr(string $ip, string $cidr): bool
    {
        [$net, $bits] = explode('/', $cidr);
        $a = @inet_pton($ip);
        $b = @inet_pton($net);
        if ($a === false || $b === false || strlen($a) !== strlen($b)) {
            return false;
        }
        $bits = (int) $bits;
        $bytes = intdiv($bits, 8);
        if (substr($a, 0, $bytes) !== substr($b, 0, $bytes)) {
            return false;
        }
        $rem = $bits % 8;
        if ($rem === 0) {
            return true;
        }
        $mask = (0xFF << (8 - $rem)) & 0xFF;

        return (ord($a[$bytes]) & $mask) === (ord($b[$bytes]) & $mask);
    }

    /** A link found on a page, made absolute against the page's address; http(s) only. */
    public static function resolveUrl(string $base, string $ref): ?string
    {
        $ref = trim(html_entity_decode($ref, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        if ($ref === '' || str_starts_with($ref, 'data:') || str_starts_with($ref, 'javascript:')) {
            return null;
        }

        $b = parse_url($base);
        if (! is_array($b) || empty($b['host'])) {
            return null;
        }
        $scheme = $b['scheme'] ?? 'https';
        $origin = $scheme.'://'.(str_contains($b['host'], ':') ? '['.$b['host'].']' : $b['host']).(isset($b['port']) ? ':'.$b['port'] : '');

        if (preg_match('~^https?://~i', $ref)) {
            $url = $ref;
        } elseif (str_starts_with($ref, '//')) {
            $url = $scheme.':'.$ref;
        } elseif (str_starts_with($ref, '/')) {
            $url = $origin.$ref;
        } elseif (preg_match('~^[a-z][a-z0-9+.-]*:~i', $ref)) {
            return null;
        } else {
            $dir = preg_replace('~/[^/]*$~', '/', $b['path'] ?? '/') ?: '/';
            $url = $origin.$dir.$ref;
        }

        return filter_var($url, FILTER_VALIDATE_URL) !== false && mb_strlen($url) <= 2000 ? $url : null;
    }
}
