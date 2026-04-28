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

    public static function uaFallbackEnabled(): bool
    {
        return (bool) rex_config::get('media_negotiator', 'ua_fallback', false);
    }

    /**
     * Returns the configured preferred output format: 'avif' (default) or 'webp'.
     * 'avif' means AVIF › WebP › original; 'webp' means WebP › original (AVIF skipped).
     */
    public static function getPreferredFormat(): string
    {
        $val = (string) rex_config::get('media_negotiator', 'preferred_format', 'avif');
        return in_array($val, ['avif', 'webp'], true) ? $val : 'avif';
    }

    public static function getWebpQuality(): int
    {
        return (int) rex_config::get('media_negotiator', 'webp_quality', 80);
    }

    public static function getAvifQuality(): int
    {
        return (int) rex_config::get('media_negotiator', 'avif_quality', 60);
    }

    private static function avifDisabled(): bool
    {
        return (bool) rex_config::get('media_negotiator', 'disable_avif', false);
    }

    /**
     * @return list<string>
     */
    private static function getImagickFormats(): array
    {
        if (!class_exists(\Imagick::class)) {
            return [];
        }
        $imagick = new \Imagick();
        return $imagick->queryFormats();
    }

    public static function webpPossible(): bool
    {
        $imagickFormats = self::getImagickFormats();
        // WebP is possible via GD (imagewebp + GD flag) OR via Imagick (WEBP codec)
        $viaGd      = function_exists('imagewebp') && self::gdSupportsWebp();
        $viaImagick = in_array('WEBP', $imagickFormats, true);
        return $viaGd || $viaImagick;
    }

    public static function avifPossible(): bool
    {
        if (!\rex_version::compare(\rex::getVersion(), '5.15.0', '>=')) {
            return false;
        }
        $imagickFormats = self::getImagickFormats();
        // AVIF is possible via GD (imageavif + GD flag) OR via Imagick (AVIF codec)
        $viaGd      = function_exists('imageavif') && self::gdSupportsAvif();
        $viaImagick = in_array('AVIF', $imagickFormats, true);
        return $viaGd || $viaImagick;
    }

    public static function gdSupportsWebp(): bool
    {
        if (function_exists('gd_info')) {
            $gdInfo = gd_info();
            return isset($gdInfo['WebP Support']) && $gdInfo['WebP Support'];
        } else {
            return false;
        }
    }

    public static function gdSupportsAvif(): bool
    {
        if (function_exists('gd_info')) {
            $gdInfo = gd_info();
            return isset($gdInfo['AVIF Support']) && $gdInfo['AVIF Support'];
        } else {
            return false;
        }
    }

    /**
     * Convert a raw image blob via Imagick to a GdImage.
     * When $quality >= 0 the Imagick compression quality is applied before re-encoding.
     */
    public static function imagickConvert(string $gdImage, string $targetFormat, int $quality = -1): \GdImage|false
    {
        $imagick = new Imagick();
        $imagick->readImageBlob($gdImage);
        $imagick->setImageFormat($targetFormat);
        if ($quality >= 0) {
            $imagick->setImageCompressionQuality($quality);
        }
        return imagecreatefromstring($imagick->getImageBlob());
    }
}
