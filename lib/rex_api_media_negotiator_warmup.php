<?php

use FriendsOfRedaxo\MediaNegotiator\MediaTypeManager;

/**
 * API-Endpoint für den AJAX Cache-Warmup.
 *
 * Aktionen (GET-Parameter: action):
 *   types  → gibt alle Medientypen mit ihren Effekten zurück + Gesamtanzahl Mediendateien
 *   media  → gibt eine paginierte Liste konvertierbarer Mediendateien zurück
 *
 * URL-Muster:
 *   ?rex-api-call=media_negotiator_warmup&action=types
 *   ?rex-api-call=media_negotiator_warmup&action=media&offset=0&limit=100
 */
class rex_api_media_negotiator_warmup extends rex_api_function
{
    /** Nur im Backend aufrufbar */
    protected $published = false;

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
        header('Content-Type: application/json; charset=utf-8');

        $action = rex_request('action', 'string', 'types');

        try {
            $data = match ($action) {
                'types' => $this->handleTypes(),
                'media' => $this->handleMedia(),
                default => throw new rex_api_exception('Unknown action: ' . $action),
            };
        } catch (Exception $e) {
            rex_response::sendJson(['error' => $e->getMessage()]);
            exit;
        }

        rex_response::sendJson($data);
        exit;
    }

    /**
     * Gibt alle Medientypen mit Negotiator- oder sRGB-Effekt zurück,
     * inklusive Gesamtanzahl konvertierbarer Mediendateien.
     *
     * @return array{types: list<array{name: string, hasNegotiator: bool, hasSrgb: bool, jobsPerFile: int}>, totalMedia: int}
     */
    private function handleTypes(): array
    {
        $sql = rex_sql::factory();

        // Alle Typen die negotiator ODER srgb_preprocess haben
        /** @var list<array{name: string, effects: string}> $rows */
        $rows = $sql->getArray(
            'SELECT t.name, GROUP_CONCAT(e.effect) AS effects
             FROM ' . rex::getTable('media_manager_type') . ' t
             JOIN ' . rex::getTable('media_manager_type_effect') . ' e ON e.type_id = t.id
             WHERE e.effect IN (:neg, :srgb)
             GROUP BY t.id, t.name
             ORDER BY t.name',
            ['neg' => 'negotiator', 'srgb' => 'srgb_preprocess']
        );

        $types = [];
        foreach ($rows as $row) {
            $effects        = explode(',', (string) $row['effects']);
            $hasNegotiator  = in_array('negotiator', $effects, true);
            $hasSrgb        = in_array('srgb_preprocess', $effects, true);
            // Typen mit Negotiator brauchen 3 Requests pro Bild (avif, webp, default)
            // Typen ohne Negotiator (nur sRGB) brauchen 1 Request pro Bild
            $jobsPerFile = $hasNegotiator ? 3 : 1;

            $types[] = [
                'name'         => (string) $row['name'],
                'hasNegotiator' => $hasNegotiator,
                'hasSrgb'      => $hasSrgb,
                'jobsPerFile'  => $jobsPerFile,
            ];
        }

        $totalMedia = $this->countMedia();

        return [
            'types'      => $types,
            'totalMedia' => $totalMedia,
        ];
    }

    /**
     * Gibt eine paginierte Liste konvertierbarer Mediendateien zurück.
     *
     * @return array{files: list<string>, total: int, offset: int, limit: int}
     */
    private function handleMedia(): array
    {
        $offset = max(0, rex_request('offset', 'int', 0));
        $limit  = min(200, max(1, rex_request('limit', 'int', 100)));

        $sql = rex_sql::factory();

        // SVG/GIF/ICO überspringen – der Effekt überspringt sie ebenfalls
        /** @var list<array{filename: string}> $rows */
        $rows = $sql->getArray(
            'SELECT filename FROM ' . rex::getTable('media')
            . " WHERE filetype LIKE 'image/%'"
            . " AND filetype NOT IN ('image/svg+xml', 'image/gif', 'image/x-icon', 'image/vnd.microsoft.icon')"
            . ' ORDER BY filename'
            . ' LIMIT ' . $limit . ' OFFSET ' . $offset
        );

        $files = array_map(static fn (array $r): string => (string) $r['filename'], $rows);
        $total = $this->countMedia();

        return [
            'files'  => $files,
            'total'  => $total,
            'offset' => $offset,
            'limit'  => $limit,
        ];
    }

    private function countMedia(): int
    {
        $sql = rex_sql::factory();
        /** @var list<array{cnt: string|int}> $rows */
        $rows = $sql->getArray(
            'SELECT COUNT(*) AS cnt FROM ' . rex::getTable('media')
            . " WHERE filetype LIKE 'image/%'"
            . " AND filetype NOT IN ('image/svg+xml', 'image/gif', 'image/x-icon', 'image/vnd.microsoft.icon')"
        );

        return count($rows) > 0 ? (int) $rows[0]['cnt'] : 0;
    }
}
