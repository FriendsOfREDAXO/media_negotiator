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
        // e.g. Safari < 16.4 sends */* for all requests and does NOT declare AVIF.
        // Safari >= 16.4 fixed the Accept header (see Safari 16.4 release notes): image
        // requests now include image/avif,image/webp; document requests still only carry text/html.
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
     *
     * Notable case: Safari 16.0–16.3 supports AVIF (on macOS Ventura) but did NOT send
     * image/avif in its Accept header. This was fixed in Safari 16.4 (see release notes:
     * "Fixed the accept header to correctly indicate AVIF support"). From 16.4+, image
     * (subresource) requests include image/avif – but document/HTML requests still do not.
     * The UA fallback is therefore still needed whenever the server only sees the page request.
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

    public static function avifDisabled(): bool
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
     * Convert a raw image blob via libvips to target format blob (not GdImage).
     * libvips is faster and uses far less memory than Imagick.
     * Returns false when vips is unavailable or conversion fails.
     */
    public static function vipsConvert(string $blob, string $targetFormat, int $quality = -1): string|false
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

        return is_string($out['buffer']) ? $out['buffer'] : false;
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

        // Apply EXIF orientation (equivalent to Imagick::autoOrient)
        $rotated = vips_call('autorot', $image);
        if (is_array($rotated) && !isset($rotated['error']) && isset($rotated['out'])) {
            $image = $rotated['out'];
        }

        // Check for embedded ICC profile
        $iccCheck = vips_call('get', $image, 'icc-profile-data');
        $hasIcc = is_array($iccCheck) && !isset($iccCheck['error']) && isset($iccCheck['out']);

        $hasAlphaResult = vips_call('hasalpha', $image);
        $hasAlpha = is_array($hasAlphaResult) && !isset($hasAlphaResult['error']) && !empty($hasAlphaResult['out']);

        if (!$hasIcc) {
            // No profile to convert – but we still return the autorotated image.
            // Prefer JPEG to mirror the Imagick path and avoid PNG gamma shifts.
            $out = vips_image_write_to_buffer(
                $image,
                $hasAlpha ? '.png' : '.jpg',
                $hasAlpha ? ['strip' => true] : ['Q' => 100, 'strip' => true],
            );
            if (!is_array($out) || isset($out['error']) || !isset($out['buffer'])) {
                return false;
            }
            return imagecreatefromstring($out['buffer']);
        }

        // Transform to sRGB using the embedded source profile.
        // intent: 0 = perceptual (matches Imagick default)
        $transformed = vips_call('icc_transform', $image, $srgbProfilePath, [
            'embedded' => true,
            'intent'   => 0,
        ]);
        if (!is_array($transformed) || isset($transformed['error']) || !isset($transformed['out'])) {
            return false;
        }

        // Strip all metadata before handing pixels to GD. For opaque images prefer
        // JPEG here to mirror the Imagick path more closely and avoid PNG gamma shifts.
        $out = vips_image_write_to_buffer(
            $transformed['out'],
            $hasAlpha ? '.png' : '.jpg',
            $hasAlpha ? ['strip' => true] : ['Q' => 100, 'strip' => true],
        );
        if (!is_array($out) || isset($out['error']) || !isset($out['buffer'])) {
            return false;
        }

        return imagecreatefromstring($out['buffer']);
    }

    /**
     * When $quality >= 0 the Imagick compression quality is applied before re-encoding.
     */
    /**
     * Convert image blob via Imagick to target format blob (not GdImage).
     * Returns the converted blob directly without trying to decode with GD.
     * Returns false when Imagick is unavailable or conversion fails.
     */
    public static function imagickConvert(string $blob, string $targetFormat, int $quality = -1): string|false
    {
        if (!class_exists(\Imagick::class)) {
            return false;
        }

        $imagick = new \Imagick();
        try {
            $imagick->readImageBlob($blob);
            $imagick->setImageFormat($targetFormat);
            if ($quality >= 0) {
                $imagick->setImageCompressionQuality($quality);
            }
            $result = $imagick->getImageBlob();
            return is_string($result) ? $result : false;
        } finally {
            $imagick->clear();
            $imagick->destroy();
        }
    }

    /**
     * Convert an image blob to AVIF/WebP using GD.
     *
     * GD is a valuable third converter next to vips and Imagick:
     *
     *  - On servers that only ship GD (no vips, no Imagick) it is currently the
     *    only way to produce AVIF/WebP at all – previously nothing converted.
     *  - For AVIF it is often the more reliable choice: several ImageMagick
     *    builds backed by certain libheif/libaom versions IGNORE the compression
     *    quality entirely (every quality level yields the same, heavily
     *    over-compressed file), whereas GD's imageavif() honours the quality
     *    parameter across the full range.
     *
     * @param string $blob         Raw image data (already resized by the effect chain)
     * @param string $targetFormat 'avif' or 'webp'
     * @param int    $quality      0–100, or -1 for the encoder default
     */
    public static function gdConvert(string $blob, string $targetFormat, int $quality = -1): string|false
    {
        if ('avif' === $targetFormat && (!function_exists('imageavif') || !self::gdSupportsAvif())) {
            return false;
        }
        if ('webp' === $targetFormat && !function_exists('imagewebp')) {
            return false;
        }
        if (!in_array($targetFormat, ['avif', 'webp'], true)) {
            return false;
        }

        $img = @imagecreatefromstring($blob);
        if (false === $img) {
            return false;
        }

        try {
            imagepalettetotruecolor($img);
            imagealphablending($img, false);
            imagesavealpha($img, true);

            ob_start();
            if ('avif' === $targetFormat) {
                $ok = imageavif($img, null, $quality >= 0 ? $quality : -1);
            } else {
                $ok = imagewebp($img, null, $quality >= 0 ? $quality : -1);
            }
            $out = ob_get_clean();

            return ($ok && is_string($out) && '' !== $out) ? $out : false;
        } finally {
            imagedestroy($img);
        }
    }
}
