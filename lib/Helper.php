<?php

namespace FriendsOfRedaxo\MediaNegotiator;

use rex;
use rex_version;
use Imagick;
use rex_config;

class Helper
{

    /**
     * @param list<string> $requestedTypes Raw types from Accept header, may include quality values like "image/webp;q=0.9"
     */
    public static function getOutputFormat(array $requestedTypes): string
    {
        // Strip quality values (e.g. "image/webp;q=0.9" → "image/webp") and normalize
        $types = array_map(static function (string $type): string {
            return strtolower(trim(explode(';', $type)[0]));
        }, $requestedTypes);

        // Only act on explicit image format declarations.
        // Wildcards like */* or image/* must NOT be treated as format support –
        // e.g. Safari sends */* but does NOT support AVIF via Content-Negotiation.
        // Firefox 132+ sends */* for page requests but image/avif,image/webp for <img> tags.
        $requestsAvif = in_array('image/avif', $types, true);
        $requestsWebp = in_array('image/webp', $types, true);

        $preferred = self::getPreferredFormat();

        if ($preferred !== 'webp' && $requestsAvif && self::avifPossible() && !self::avifDisabled()) {
            return 'avif';
        }
        // A browser declaring image/avif is modern enough to also handle WebP.
        // When preferred=webp: serve WebP even if browser requested AVIF.
        if (($requestsWebp || $requestsAvif) && self::webpPossible()) {
            return 'webp';
        }
        return 'default';
    }

    /**
     * Detect browser image format support purely from the User-Agent string,
     * without considering server-side capabilities.
     * Returns ['avif' => bool, 'webp' => bool].
     *
     * @return array{avif: bool, webp: bool}
     */
    public static function getBrowserFormatSupport(string $userAgent): array
    {
        $avif = false;
        $webp = false;

        if ($userAgent === '') {
            return ['avif' => $avif, 'webp' => $webp];
        }

        // Must check Safari before Chrome/Chromium – Chrome UA also contains "Safari/"
        if (str_contains($userAgent, 'Safari/') && !str_contains($userAgent, 'Chrome')) {
            if (preg_match('/Version\/(\d+)\.(\d+)/', $userAgent, $m)) {
                $major = (int) $m[1];
                $minor = (int) $m[2];
                $avif  = $major > 16 || ($major === 16 && $minor >= 4);
                $webp  = $major >= 14;
            }
            return ['avif' => $avif, 'webp' => $webp];
        }

        if (preg_match('/Chrome\/(\d+)/', $userAgent, $m)) {
            $version = (int) $m[1];
            $avif    = $version >= 85;
            $webp    = $version >= 32;
            return ['avif' => $avif, 'webp' => $webp];
        }

        if (preg_match('/Firefox\/(\d+)/', $userAgent, $m)) {
            $version = (int) $m[1];
            $avif    = $version >= 93;
            $webp    = $version >= 65;
            return ['avif' => $avif, 'webp' => $webp];
        }

        return ['avif' => $avif, 'webp' => $webp];
    }

    /**
     * Detect the best supported image format from the User-Agent string.
     * Used as fallback when the Accept header does not carry explicit format declarations.
     * Notable case: Safari >= 16.4 supports AVIF but never sends image/avif in its Accept header.
     */
    public static function getOutputFormatFromUserAgent(string $userAgent): string
    {
        if ($userAgent === '') {
            return 'default';
        }

        // Must check Safari before Chrome/Chromium – Chrome UA also contains "Safari/"
        if (str_contains($userAgent, 'Safari/') && !str_contains($userAgent, 'Chrome')) {
            if (preg_match('/Version\/(\d+)\.(\d+)/', $userAgent, $m)) {
                $major = (int) $m[1];
                $minor = (int) $m[2];
                $preferred = self::getPreferredFormat();
                // AVIF support since Safari 16.4
                if ($preferred !== 'webp' && ($major > 16 || ($major === 16 && $minor >= 4)) && self::avifPossible() && !self::avifDisabled()) {
                    return 'avif';
                }
                // WebP support since Safari 14 (macOS Big Sur / iOS 14)
                if ($major >= 14 && self::webpPossible()) {
                    return 'webp';
                }
            }
            return 'default';
        }

        // Chrome, Edge, Opera and other Chromium-based browsers
        if (preg_match('/Chrome\/(\d+)/', $userAgent, $m)) {
            $version = (int) $m[1];
            $preferred = self::getPreferredFormat();
            if ($preferred !== 'webp' && $version >= 85 && self::avifPossible() && !self::avifDisabled()) {
                return 'avif';
            }
            if ($version >= 32 && self::webpPossible()) {
                return 'webp';
            }
            return 'default';
        }

        // Firefox
        if (preg_match('/Firefox\/(\d+)/', $userAgent, $m)) {
            $version = (int) $m[1];
            $preferred = self::getPreferredFormat();
            if ($preferred !== 'webp' && $version >= 93 && self::avifPossible() && !self::avifDisabled()) {
                return 'avif';
            }
            if ($version >= 65 && self::webpPossible()) {
                return 'webp';
            }
            return 'default';
        }

        return 'default';
    }

    private static ?bool $uaFallbackCache = null;
    private static ?string $preferredFormatCache = null;
    private static ?int $webpQualityCache = null;
    private static ?int $avifQualityCache = null;
    private static ?bool $avifDisabledCache = null;
    private static ?bool $webpPossibleCache = null;
    private static ?bool $avifPossibleCache = null;
    private static ?bool $vipsPossibleCache = null;
    private static ?array $gdInfoCache = null;
    /** Resolved output format for the current request (keyed by Accept header). */
    private static ?string $resolvedFormatCache = null;
    private static ?string $resolvedFormatCacheKey = null;

    public static function uaFallbackEnabled(): bool
    {
        if (null === self::$uaFallbackCache) {
            self::$uaFallbackCache = (bool) rex_config::get('media_negotiator', 'ua_fallback', false);
        }
        return self::$uaFallbackCache;
    }

    /**
     * Returns the configured preferred output format: 'avif' (default) or 'webp'.
     * 'avif' means AVIF › WebP › original; 'webp' means WebP › original (AVIF skipped).
     */
    public static function getPreferredFormat(): string
    {
        if (null === self::$preferredFormatCache) {
            $val = (string) rex_config::get('media_negotiator', 'preferred_format', 'avif');
            self::$preferredFormatCache = in_array($val, ['avif', 'webp'], true) ? $val : 'avif';
        }
        return self::$preferredFormatCache;
    }

    public static function getWebpQuality(): int
    {
        if (null === self::$webpQualityCache) {
            self::$webpQualityCache = (int) rex_config::get('media_negotiator', 'webp_quality', 80);
        }
        return self::$webpQualityCache;
    }

    public static function getAvifQuality(): int
    {
        if (null === self::$avifQualityCache) {
            self::$avifQualityCache = (int) rex_config::get('media_negotiator', 'avif_quality', 60);
        }
        return self::$avifQualityCache;
    }

    private static function avifDisabled(): bool
    {
        if (null === self::$avifDisabledCache) {
            self::$avifDisabledCache = (bool) rex_config::get('media_negotiator', 'disable_avif', false);
        }
        return self::$avifDisabledCache;
    }

    /** @var list<string>|null */
    private static ?array $imagickFormatsCache = null;

    /**
     * @return list<string>
     */
    private static function getImagickFormats(): array
    {
        if (!class_exists(\Imagick::class)) {
            return [];
        }
        if (null !== self::$imagickFormatsCache) {
            return self::$imagickFormatsCache;
        }
        $imagick = new \Imagick();
        self::$imagickFormatsCache = $imagick->queryFormats();
        $imagick->destroy();
        return self::$imagickFormatsCache;
    }

    public static function webpPossible(): bool
    {
        if (null !== self::$webpPossibleCache) {
            return self::$webpPossibleCache;
        }
        $imagickFormats = self::getImagickFormats();
        $viaGd      = function_exists('imagewebp') && self::gdSupportsWebp();
        $viaImagick = in_array('WEBP', $imagickFormats, true);
        // libvips always supports WebP when the extension is available
        $viaVips    = self::vipsPossible();
        self::$webpPossibleCache = $viaGd || $viaImagick || $viaVips;
        return self::$webpPossibleCache;
    }

    public static function avifPossible(): bool
    {
        if (null !== self::$avifPossibleCache) {
            return self::$avifPossibleCache;
        }
        if (!\rex_version::compare(\rex::getVersion(), '5.15.0', '>=')) {
            self::$avifPossibleCache = false;
            return false;
        }
        $imagickFormats = self::getImagickFormats();
        $viaGd      = function_exists('imageavif') && self::gdSupportsAvif();
        $viaImagick = in_array('AVIF', $imagickFormats, true);
        // libvips >= 8.9 supports AVIF via libheif
        $viaVips    = self::vipsPossible();
        self::$avifPossibleCache = $viaGd || $viaImagick || $viaVips;
        return self::$avifPossibleCache;
    }

    /**
     * Returns true when the PHP vips extension is available.
     * libvips is significantly faster and uses much less memory than Imagick.
     */
    public static function vipsPossible(): bool
    {
        if (null !== self::$vipsPossibleCache) {
            return self::$vipsPossibleCache;
        }
        self::$vipsPossibleCache = function_exists('vips_image_new_from_buffer');
        return self::$vipsPossibleCache;
    }

    /**
     * Returns true when Imagick is forced via config (regardless of vips availability).
     */
    public static function getForceImagick(): bool
    {
        return (bool) rex_config::get('media_negotiator', 'force_imagick', false);
    }

    public static function gdSupportsWebp(): bool
    {
        $gdInfo = self::getGdInfo();
        return isset($gdInfo['WebP Support']) && $gdInfo['WebP Support'];
    }

    public static function gdSupportsAvif(): bool
    {
        $gdInfo = self::getGdInfo();
        return isset($gdInfo['AVIF Support']) && $gdInfo['AVIF Support'];
    }

    /** @return array<string, mixed> */
    private static function getGdInfo(): array
    {
        if (null === self::$gdInfoCache) {
            self::$gdInfoCache = function_exists('gd_info') ? gd_info() : [];
        }
        return self::$gdInfoCache;
    }

    /**
     * Returns the resolved output format for the current HTTP request.
     * Result is cached for the lifetime of the request so boot.php and the
     * effect class share the same computation.
     */
    public static function getRequestOutputFormat(): string
    {
        $acceptHeader = rex_server('HTTP_ACCEPT', 'string', '');

        if (self::$resolvedFormatCacheKey === $acceptHeader && null !== self::$resolvedFormatCache) {
            return self::$resolvedFormatCache;
        }

        $types = explode(',', $acceptHeader);
        $format = self::getOutputFormat($types);

        if ($format === 'default' && self::uaFallbackEnabled()) {
            $userAgent = rex_server('HTTP_USER_AGENT', 'string', '');
            $format = self::getOutputFormatFromUserAgent($userAgent);
        }

        self::$resolvedFormatCacheKey = $acceptHeader;
        self::$resolvedFormatCache = $format;
        return $format;
    }

    /**
     * Convert a raw image blob via libvips to a GdImage.
     * libvips is faster and uses far less memory than Imagick.
     * Returns false when vips is unavailable or conversion fails.
     */
    public static function vipsConvert(string $blob, string $targetFormat, int $quality = -1): \GdImage|false
    {
        if (!self::vipsPossible()) {
            return false;
        }

        $result = vips_image_new_from_buffer($blob, '');
        if (!is_array($result) || isset($result['error']) || !isset($result['out'])) {
            return false;
        }

        $options = [];
        if ($quality >= 0) {
            $options['Q'] = $quality;
        }

        $out = vips_image_write_to_buffer($result['out'], '.' . $targetFormat, $options);
        if (!is_array($out) || isset($out['error']) || !isset($out['buffer'])) {
            return false;
        }

        return imagecreatefromstring($out['buffer']);
    }

    /**
     * Convert an image blob to sRGB colour space via libvips ICC transform.
     * Returns a GdImage on success, false when vips is unavailable, has no
     * embedded ICC profile, or the transform fails.
     *
     * @param string $srgbProfilePath Absolute path to the sRGB ICC profile file.
     */
    public static function vipsSrgbConvert(string $blob, string $srgbProfilePath): \GdImage|false
    {
        if (!self::vipsPossible()) {
            return false;
        }

        $result = vips_image_new_from_buffer($blob, '');
        if (!is_array($result) || isset($result['error']) || !isset($result['out'])) {
            return false;
        }

        $image = $result['out'];

        // Check for embedded ICC profile
        $iccCheck = vips_call('get', $image, 'icc-profile-data');
        if (!is_array($iccCheck) || isset($iccCheck['error'])) {
            return false; // No embedded profile – nothing to convert
        }

        // Transform to sRGB using the embedded source profile
        $transformed = vips_call('icc_transform', $image, $srgbProfilePath, [
            'embedded' => true,
            'intent'   => 'perceptual',
        ]);
        if (!is_array($transformed) || isset($transformed['error']) || !isset($transformed['out'])) {
            return false;
        }

        // Write as PNG for a lossless in-memory intermediate; GD handles final encoding
        $out = vips_image_write_to_buffer($transformed['out'], '.png');
        if (!is_array($out) || isset($out['error']) || !isset($out['buffer'])) {
            return false;
        }

        return imagecreatefromstring($out['buffer']);
    }

    /**
     * When $quality >= 0 the Imagick compression quality is applied before re-encoding.
     */
    public static function imagickConvert(string $gdImage, string $targetFormat, int $quality = -1): \GdImage|false
    {
        $imagick = new Imagick();
        try {
            $imagick->readImageBlob($gdImage);
            $imagick->setImageFormat($targetFormat);
            if ($quality >= 0) {
                $imagick->setImageCompressionQuality($quality);
            }
            $blob = $imagick->getImageBlob();
        } finally {
            $imagick->clear();
            $imagick->destroy();
        }
        return imagecreatefromstring($blob);
    }
}
