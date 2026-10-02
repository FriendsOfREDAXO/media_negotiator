<?php

use FriendsOfRedaxo\MediaNegotiator\Helper;

/**
 * API endpoint for capturing the Accept header of a real browser image request.
 *
 * - action=image  => stores request headers in backend session and returns a 1x1 GIF
 * - action=status => returns the last stored probe result as JSON
 */
class rex_api_media_negotiator_probe extends rex_api_function
{
    protected $published = false;

    private const SESSION_KEY = 'media_negotiator_probe';

    protected function requiresCsrfProtection(): bool
    {
        return false;
    }

    public function execute(): rex_api_result
    {
        if (!rex::getUser()) {
            throw new rex_api_exception('Not authorized');
        }

        rex_response::cleanOutputBuffers();
        rex_login::startSession();

        $action = rex_request('action', 'string', 'status');
        $token = rex_request('token', 'string', '');

        if (!preg_match('/^[a-f0-9]{16,64}$/', $token)) {
            http_response_code(400);
            exit;
        }

        $probes = self::getProbes();
        $probes = self::cleanupProbes($probes);

        if ($action === 'image') {
            $probes[$token] = self::buildProbeData();
            self::saveProbes($probes);
            self::sendPixel();
        }

        if ($action === 'status') {
            self::saveProbes($probes);
            $probe = $probes[$token] ?? null;
            if (!is_array($probe)) {
                rex_response::sendJson(['found' => false]);
                exit;
            }

            rex_response::sendJson([
                'found' => true,
                'accept' => (string) ($probe['accept'] ?? ''),
                'userAgent' => (string) ($probe['userAgent'] ?? ''),
                'declaredAvif' => (bool) ($probe['declaredAvif'] ?? false),
                'declaredWebp' => (bool) ($probe['declaredWebp'] ?? false),
                'formatFromAccept' => (string) ($probe['formatFromAccept'] ?? 'default'),
            ]);
            exit;
        }

        http_response_code(400);
        exit;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private static function getProbes(): array
    {
        $probes = rex_session(self::SESSION_KEY, 'array', []);
        return $probes;
    }

    /**
     * @param array<string, array<string, mixed>> $probes
     */
    private static function saveProbes(array $probes): void
    {
        rex_set_session(self::SESSION_KEY, $probes);
    }

    /**
     * @param array<string, array<string, mixed>> $probes
     * @return array<string, array<string, mixed>>
     */
    private static function cleanupProbes(array $probes): array
    {
        $minTimestamp = time() - 600;

        foreach ($probes as $token => $probe) {
            $timestamp = (int) ($probe['timestamp'] ?? 0);
            if ($timestamp < $minTimestamp) {
                unset($probes[$token]);
            }
        }

        return $probes;
    }

    /**
     * @return array{timestamp:int,accept:string,userAgent:string,declaredAvif:bool,declaredWebp:bool,formatFromAccept:string}
     */
    private static function buildProbeData(): array
    {
        $acceptHeader = rex_server('HTTP_ACCEPT', 'string', '');
        $userAgent = rex_server('HTTP_USER_AGENT', 'string', '');
        /** @var list<string> $acceptTypes */
        $acceptTypes = array_values(array_filter(array_map('trim', explode(',', $acceptHeader))));
        $normalizedTypes = array_map(static fn (string $type): string => strtolower(trim(explode(';', $type)[0])), $acceptTypes);

        return [
            'timestamp' => time(),
            'accept' => $acceptHeader,
            'userAgent' => $userAgent,
            'declaredAvif' => in_array('image/avif', $normalizedTypes, true),
            'declaredWebp' => in_array('image/webp', $normalizedTypes, true),
            'formatFromAccept' => Helper::getOutputFormat($acceptTypes),
        ];
    }

    private static function sendPixel(): void
    {
        $gif = (string) base64_decode('R0lGODlhAQABAIABAP///wAAACwAAAAAAQABAAACAkQBADs=', true);

        header('Content-Type: image/gif');
        header('Content-Length: ' . strlen($gif));
        header('Cache-Control: private, no-store, no-cache, must-revalidate, max-age=0');
        header('Pragma: no-cache');

        echo $gif;
        exit;
    }
}