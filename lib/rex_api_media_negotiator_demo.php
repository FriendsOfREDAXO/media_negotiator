<?php

use FriendsOfRedaxo\MediaNegotiator\Helper;

/**
 * API-Endpoint: Liefert gecachte Demo-Bilder für die Setup-Seite aus.
 * Erster Aufruf erzeugt die konvertierte Datei (Disk-Cache),
 * alle folgenden Aufrufe sind sofort (readfile).
 *
 * URL-Muster: ?rex-api-call=media_negotiator_demo&format=webp
 */
class rex_api_media_negotiator_demo extends rex_api_function
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

        $format = rex_request('format', 'string', 'webp');
        if (!in_array($format, ['webp', 'avif', 'jpeg'], true)) {
            http_response_code(400);
            exit;
        }

        // JPEG-Original direkt ausliefern (keine Konvertierung nötig)
        if ($format === 'jpeg') {
            $filePath = rex_path::addon('media_negotiator', 'data/demo.jpg');
            if (!is_readable($filePath)) {
                http_response_code(404);
                exit;
            }
            header('Content-Type: image/jpeg');
            header('Content-Length: ' . filesize($filePath));
            header('Cache-Control: private, max-age=3600');
            readfile($filePath);
            exit;
        }

        $filePath = self::getOrGenerate($format);

        if ($filePath === null) {
            http_response_code(500);
            exit;
        }

        $mime = match ($format) {
            'avif' => 'image/avif',
            default => 'image/webp',
        };

        header('Content-Type: ' . $mime);
        header('Content-Length: ' . filesize($filePath));
        header('Cache-Control: private, max-age=3600');
        readfile($filePath);
        exit;
    }

    /**
     * Liefert den Cache-Schlüssel basierend auf den aktuellen Konvertierungseinstellungen.
     * Ändert sich eine Einstellung (Qualität, Engine), wird beim nächsten Aufruf neu generiert.
     */
    public static function getConfigHash(): string
    {
        return substr(md5(serialize([
            rex_config::get('media_negotiator', 'force_imagick', false),
            Helper::getWebpQuality(),
            Helper::getAvifQuality(),
        ])), 0, 8);
    }

    /**
     * Gibt die URL zum API-Endpoint für das angegebene Format zurück.
     */
    public static function getUrl(string $format): string
    {
        return rex_url::backendController([
            'rex-api-call' => 'media_negotiator_demo',
            'format'       => $format,
        ]);
    }

    /**
     * Gibt zurück ob das Format serverseitig erzeugbar ist.
     */
    public static function isFormatPossible(string $format): bool
    {
        return $format === 'webp' ? Helper::webpPossible() : Helper::avifPossible();
    }

    /**
     * Gibt den Pfad zur gecachten Datei zurück, wenn sie bereits existiert.
     * Gibt null zurück wenn noch kein Cache vorhanden (KEIN Generieren).
     */
    public static function getCachedFilePath(string $format): ?string
    {
        $hash = self::getConfigHash();
        $dir  = rex_path::addonData('media_negotiator', 'demo-cache');
        $file = $dir . DIRECTORY_SEPARATOR . $format . '-' . $hash . '.' . $format;

        return is_readable($file) ? $file : null;
    }

    /**
     * Gibt den Pfad zur gecachten Datei zurück (oder generiert sie).
     * Null = Format nicht unterstützt.
     */
    public static function getOrGenerate(string $format): ?string
    {
        $hash  = self::getConfigHash();
        $dir   = rex_path::addonData('media_negotiator', 'demo-cache');
        $file  = $dir . DIRECTORY_SEPARATOR . $format . '-' . $hash . '.' . $format;

        if (is_readable($file)) {
            return $file;
        }

        rex_dir::create($dir);

        $demoImg      = rex_path::addon('media_negotiator', 'data/demo.jpg');
        $forceImagick = (bool) rex_config::get('media_negotiator', 'force_imagick', false);
        $quality      = $format === 'webp' ? Helper::getWebpQuality() : Helper::getAvifQuality();

        $data = '';

        if (!$forceImagick) {
            $data = self::convertWithGd($demoImg, $format, $quality);
        }

        if ($data === '') {
            $data = self::convertWithImagick($demoImg, $format, $quality);
        }

        if ($data !== '') {
            rex_file::put($file, $data);
            return $file;
        }

        return null;
    }

    private static function convertWithGd(string $sourcePath, string $format, int $quality): string
    {
        $image = @imagecreatefromjpeg($sourcePath);
        if ($image === false) {
            return '';
        }

        ob_start();
        $ok = false;

        if ($format === 'webp' && function_exists('imagewebp')) {
            $ok = imagewebp($image, null, $quality);
        } elseif ($format === 'avif' && function_exists('imageavif')) {
            $ok = imageavif($image, null, $quality);
        }

        $imgData = ob_get_clean();
        imagedestroy($image);

        if (!$ok || $imgData === false || $imgData === '') {
            return '';
        }

        return $imgData;
    }

    private static function convertWithImagick(string $sourcePath, string $format, int $quality): string
    {
        if (!class_exists(Imagick::class)) {
            return '';
        }

        try {
            $im = new Imagick($sourcePath);
            $im->setImageFormat($format);
            $im->setImageCompressionQuality($quality);
            $data = $im->getImageBlob();
            $im->clear();
            $im->destroy();
            return $data;
        } catch (Exception) {
            return '';
        }
    }
}
