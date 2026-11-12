<?php

namespace FriendsOfRedaxo\MediaNegotiator;

use rex;
use rex_addon;
use rex_file;
use rex_yrewrite;

/**
 * Bild-Cache vorwärmen – nach den Seiten der Website.
 *
 * Statt jedes Bild in jedem Medientyp zu erzeugen (das meiste davon zeigt nie eine Seite an), werden die Sitemaps
 * gelesen, alle Seiten abgerufen und genau die dort verwendeten Bildadressen gesammelt (src, srcset, data-src,
 * data-srcset, poster, og:image – also auch die Breiten von media_srcset). Jede Adresse wird dann einmal je Format
 * angefragt, das der Negotiator tatsächlich ausliefert (AVIF/WebP/Original, je nach Server und Einstellungen).
 *
 * Läuft in Häppchen (step()), damit weder Backend-Anfragen noch Cronjobs ins Zeitlimit laufen; der Stand liegt in
 * data/warmup.json. Mehrere Bilder gleichzeitig über curl_multi (ohne curl: nacheinander).
 */
final class Warmup
{
    public const USER_AGENT = 'REDAXO MediaNegotiator CacheWarmer/2.0';

    /** Accept-Header je Format, wie sie Browser senden */
    private const ACCEPT = [
        'avif' => 'image/avif,image/webp,image/apng,*/*;q=0.8',
        'webp' => 'image/webp,image/apng,*/*;q=0.8',
        'default' => 'image/png,image/jpeg,*/*;q=0.8',
    ];

    /** höchstens so viele Seiten aus den Sitemaps */
    private const MAX_PAGES = 5000;

    /**
     * Formate, die der Negotiator auf diesem Server ausliefert (Reihenfolge: bevorzugtes zuerst). Doppelte entfallen,
     * z. B. „avif“, wenn AVIF abgeschaltet ist oder WebP bevorzugt wird – dann bekäme ein AVIF-Browser ohnehin WebP.
     *
     * @return array<string, string> Format => Accept-Header
     */
    public static function formats(): array
    {
        $formats = [];
        foreach (self::ACCEPT as $label => $accept) {
            $effective = Helper::getOutputFormat(explode(',', $accept));
            if (!isset($formats[$effective])) {
                $formats[$effective] = $accept;
            }
        }

        return $formats;
    }

    /** Sitemaps der Website: je YRewrite-Domain, sonst die Server-Adresse */
    public static function sitemaps(): array
    {
        $urls = [];
        if (rex_addon::get('yrewrite')->isAvailable() && class_exists(rex_yrewrite::class)) {
            foreach (rex_yrewrite::getDomains() as $name => $domain) {
                if ('default' === $name || !method_exists($domain, 'getUrl')) {
                    continue;
                }
                $urls[] = rtrim((string) $domain->getUrl(), '/') . '/sitemap.xml';
            }
        }
        if ([] === $urls && '' !== rex::getServer()) {
            $urls[] = rtrim(rex::getServer(), '/') . '/sitemap.xml';
        }

        return array_values(array_unique($urls));
    }

    /**
     * Neuen Durchgang beginnen: Seiten aus den Sitemaps sammeln (nur ein paar Anfragen, Seiten folgen in step())
     *
     * @param list<string> $sitemaps andere Sitemaps als die der Website (z. B. wenn der Server die eigene Domain
     *     nicht erreicht oder für eine Staging-Umgebung)
     */
    public static function start(?int $concurrency = null, array $sitemaps = []): array
    {
        $pages = [];
        $queue = [] !== $sitemaps ? array_values($sitemaps) : self::sitemaps();
        $seen = [];
        while ([] !== $queue && count($pages) < self::MAX_PAGES) {
            $sitemap = array_shift($queue);
            if (isset($seen[$sitemap])) {
                continue;
            }
            $seen[$sitemap] = true;
            $xml = self::fetchMany([['url' => $sitemap, 'accept' => 'application/xml,text/xml,*/*', 'body' => true]])[0]['body'] ?? '';
            preg_match_all('~<loc>\s*([^<\s]+)\s*</loc>~i', $xml, $m);
            foreach ($m[1] as $loc) {
                $loc = html_entity_decode($loc, ENT_QUOTES | ENT_XML1, 'UTF-8');
                // Sitemap-Index: weitere Sitemaps
                if (str_contains($xml, '<sitemapindex') || preg_match('~\.xml(\?|$)~i', $loc)) {
                    $queue[] = $loc;
                } else {
                    $pages[$loc] = true;
                }
            }
            // keine Sitemap: wenigstens die Startseite
            if ([] === $m[1]) {
                $pages[preg_replace('~sitemap\.xml$~', '', $sitemap)] = true;
            }
        }

        $state = [
            'status' => 'pages',
            'started' => time(),
            'updated' => time(),
            'formats' => self::formats(),
            'concurrency' => self::concurrency($concurrency),
            'pages' => array_slice(array_keys($pages), 0, self::MAX_PAGES),
            'page' => 0,
            'images' => [],
            'job' => 0,
            'errors' => 0,
            'lastErrors' => [],
        ];
        self::save($state);

        return self::status();
    }

    /**
     * Einen Teil abarbeiten: erst die Seiten (Bildadressen sammeln), dann die Bilder je Format.
     *
     * @param float $seconds Zeitbudget für diesen Aufruf
     */
    public static function step(float $seconds = 20.0): array
    {
        $state = self::load();
        if (null === $state || !in_array($state['status'], ['pages', 'images'], true)) {
            return self::status();
        }
        $until = microtime(true) + max(1.0, $seconds);
        $n = (int) $state['concurrency'];

        while (microtime(true) < $until) {
            if ('pages' === $state['status']) {
                $batch = array_slice($state['pages'], $state['page'], $n);
                if ([] === $batch) {
                    $state['status'] = 'images';
                    continue;
                }
                $requests = array_map(static fn (string $url) => ['url' => $url, 'accept' => 'text/html,*/*;q=0.8', 'body' => true], $batch);
                $images = array_fill_keys($state['images'], true);
                foreach (self::fetchMany($requests) as $i => $result) {
                    if ($result['ok']) {
                        foreach (self::mediaUrls($result['body'], $batch[$i]) as $url) {
                            $images[$url] = true;
                        }
                    } else {
                        self::error($state, $batch[$i], $result);
                    }
                }
                $state['images'] = array_keys($images);
                $state['page'] += count($batch);
            } else {
                $formats = array_values($state['formats']);
                $total = count($state['images']) * count($formats);
                if ($state['job'] >= $total) {
                    $state['status'] = 'done';
                    break;
                }
                $requests = [];
                for ($j = $state['job']; $j < min($total, $state['job'] + $n); ++$j) {
                    $requests[] = ['url' => $state['images'][intdiv($j, count($formats))], 'accept' => $formats[$j % count($formats)], 'body' => false];
                }
                foreach (self::fetchMany($requests) as $i => $result) {
                    if (!$result['ok']) {
                        self::error($state, $requests[$i]['url'], $result);
                    }
                }
                $state['job'] += count($requests);
            }
            $state['updated'] = time();
            self::save($state);
        }
        $state['updated'] = time();
        self::save($state);

        return self::status();
    }

    /** Angehaltenen Durchgang fortsetzen (dort, wo er stehen geblieben ist) */
    public static function resume(?int $concurrency = null): array
    {
        $state = self::load();
        if (null !== $state && 'cancelled' === $state['status']) {
            $state['status'] = $state['page'] < count($state['pages']) ? 'pages' : 'images';
            if (null !== $concurrency) {
                $state['concurrency'] = self::concurrency($concurrency);
            }
            $state['updated'] = time();
            self::save($state);
        }

        return self::status();
    }

    public static function cancel(): array
    {
        $state = self::load();
        if (null !== $state && in_array($state['status'], ['pages', 'images'], true)) {
            $state['status'] = 'cancelled';
            $state['updated'] = time();
            self::save($state);
        }

        return self::status();
    }

    /** Stand für Backend und Konsole */
    public static function status(): array
    {
        $state = self::load();
        if (null === $state) {
            return ['status' => 'idle', 'formats' => array_keys(self::formats())];
        }
        $formats = count($state['formats']);

        return [
            'status' => $state['status'],
            'started' => $state['started'],
            'updated' => $state['updated'],
            'formats' => array_keys($state['formats']),
            'concurrency' => $state['concurrency'],
            'pages' => count($state['pages']),
            'pagesDone' => min($state['page'], count($state['pages'])),
            'images' => count($state['images']),
            'jobs' => count($state['images']) * $formats,
            'jobsDone' => min($state['job'], count($state['images']) * $formats),
            'errors' => $state['errors'],
            'lastErrors' => $state['lastErrors'],
        ];
    }

    /**
     * Bildadressen einer Seite, die über den Media Manager laufen (/media/<typ>/<datei> oder ?rex_media_type=…),
     * nur vom selben Host
     *
     * @return list<string> absolute URLs
     */
    public static function mediaUrls(string $html, string $pageUrl): array
    {
        $page = parse_url($pageUrl);
        $origin = ($page['scheme'] ?? 'https') . '://' . ($page['host'] ?? '') . (isset($page['port']) ? ':' . $page['port'] : '');
        $found = [];
        preg_match_all('~\s(?:src|srcset|data-src|data-srcset|poster|content|href)\s*=\s*(["\'])(.*?)\1~is', $html, $m);
        preg_match_all('~url\(\s*["\']?([^"\')]+)~i', $html, $css);
        $candidates = array_merge($m[2], $css[1]);
        foreach ($candidates as $value) {
            // srcset: „url 800w, url 1200w“
            foreach (preg_split('~\s*,\s*(?=\S)~', html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8')) ?: [] as $part) {
                $url = trim((string) preg_split('~\s+~', trim($part))[0]);
                if ('' === $url) {
                    continue;
                }
                if (str_starts_with($url, '//')) {
                    $url = ($page['scheme'] ?? 'https') . ':' . $url;
                } elseif (str_starts_with($url, '/')) {
                    $url = $origin . $url;
                }
                if (!str_starts_with($url, $origin . '/')) {
                    continue;
                }
                $path = (string) parse_url($url, PHP_URL_PATH);
                $query = (string) parse_url($url, PHP_URL_QUERY);
                if (1 === preg_match('~^/media/[^/]+/[^/]+\.(jpe?g|png|webp|avif|tiff?|bmp)$~i', $path)
                    || (str_contains($query, 'rex_media_type=') && str_contains($query, 'rex_media_file='))) {
                    $found[$url] = true;
                }
            }
        }

        return array_keys($found);
    }

    /**
     * Mehrere Anfragen gleichzeitig (curl_multi), sonst nacheinander
     *
     * @param list<array{url: string, accept: string, body: bool}> $requests
     * @return list<array{ok: bool, code: int, body: string, error: string}>
     */
    public static function fetchMany(array $requests): array
    {
        $results = [];
        if (!function_exists('curl_multi_init')) {
            foreach ($requests as $request) {
                $context = stream_context_create([
                    'http' => ['method' => 'GET', 'header' => 'Accept: ' . $request['accept'] . "\r\nUser-Agent: " . self::USER_AGENT . "\r\n", 'ignore_errors' => true, 'timeout' => 120],
                    'ssl' => self::insecure($request['url']) ? ['verify_peer' => false, 'verify_peer_name' => false] : [],
                ]);
                $body = @file_get_contents($request['url'], false, $context);
                $code = isset($http_response_header[0]) && preg_match('~\s(\d{3})\s~', $http_response_header[0], $c) ? (int) $c[1] : 0;
                $results[] = ['ok' => false !== $body && $code >= 200 && $code < 400, 'code' => $code, 'body' => $request['body'] ? (string) $body : '', 'error' => false === $body ? 'Verbindung fehlgeschlagen' : ''];
            }

            return $results;
        }

        $multi = curl_multi_init();
        $handles = [];
        foreach ($requests as $i => $request) {
            $ch = curl_init($request['url']);
            curl_setopt_array($ch, [
                CURLOPT_HTTPHEADER => ['Accept: ' . $request['accept']],
                CURLOPT_USERAGENT => self::USER_AGENT,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS => 3,
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_TIMEOUT => 120,
                CURLOPT_ENCODING => '',
                CURLOPT_SSL_VERIFYPEER => !self::insecure($request['url']),
                CURLOPT_SSL_VERIFYHOST => self::insecure($request['url']) ? 0 : 2,
            ]);
            if ($request['body']) {
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            } else {
                // Bild nur erzeugen lassen, nicht im Speicher halten
                curl_setopt($ch, CURLOPT_WRITEFUNCTION, static fn ($ch, string $data): int => strlen($data));
            }
            curl_multi_add_handle($multi, $ch);
            $handles[$i] = $ch;
        }
        do {
            $status = curl_multi_exec($multi, $running);
            if ($running) {
                curl_multi_select($multi, 1.0);
            }
        } while ($running && CURLM_OK === $status);

        foreach ($handles as $i => $ch) {
            $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            $error = curl_error($ch);
            $results[$i] = [
                'ok' => '' === $error && $code >= 200 && $code < 400,
                'code' => $code,
                'body' => $requests[$i]['body'] ? (string) curl_multi_getcontent($ch) : '',
                'error' => $error,
            ];
            curl_multi_remove_handle($multi, $ch);
            curl_close($ch);
        }
        curl_multi_close($multi);
        ksort($results);

        return array_values($results);
    }

    private static function concurrency(?int $value): int
    {
        return max(1, min(16, $value ?? 4));
    }

    /** lokale Entwicklungs-Domains mit selbst signiertem Zertifikat */
    private static function insecure(string $url): bool
    {
        $host = (string) parse_url($url, PHP_URL_HOST);

        return 'localhost' === $host || str_ends_with($host, '.localhost') || '127.0.0.1' === $host || str_ends_with($host, '.test');
    }

    private static function error(array &$state, string $url, array $result): void
    {
        ++$state['errors'];
        $state['lastErrors'] = array_slice(array_merge([($result['code'] ?: $result['error']) . ' ' . $url], $state['lastErrors']), 0, 5);
    }

    private static function path(): string
    {
        return rex_addon::get('media_negotiator')->getDataPath('warmup.json');
    }

    private static function load(): ?array
    {
        $file = self::path();
        $state = is_file($file) ? json_decode((string) rex_file::get($file), true) : null;

        return is_array($state) && isset($state['status']) ? $state : null;
    }

    private static function save(array $state): void
    {
        rex_file::put(self::path(), (string) json_encode($state));
    }
}
