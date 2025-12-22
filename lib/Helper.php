<?php

namespace media_negotiator;

use rex;
use rex_version;
use Imagick;
use rex_config;

class Helper
{


    public static function getOutputFormat(array $requestedTypes): string
    {
        if (in_array('image/avif', $requestedTypes) && self::avifPossible() && !self::avifDisabled()) {
            return 'avif';
        }
        if (in_array('image/webp', $requestedTypes) && self::webpPossible()) {
            return 'webp';
        }
        return 'default';
    }

    private static function avifDisabled(): bool
    {
        return rex_config::get('media_negotiator', 'disable_avif', false);
    }

    private static function getImagickFormats(): array
    {
        if (!class_exists(\Imagick::class) || \Imagick::getVersion() === false) {
            return [];
        }
        $imagick = new \Imagick();
        return $imagick->queryFormats();
    }

    public static function webpPossible(): bool
    {
        $imagickFormats = self::getImagickFormats();
        return (function_exists('imagewebp') || in_array('WEBP', $imagickFormats))
            && self::gdSupportsWebp();
    }

    public static function avifPossible(): bool
    {
        $imagickFormats = self::getImagickFormats();
        return \rex_version::compare(\rex::getVersion(), '5.15.0', '>=')
            && (function_exists('imageavif') || in_array('AVIF', $imagickFormats))
            && self::gdSupportsAvif();
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

    public static function imagickConvert($gdImage, $targetFormat)
    {
        $imagick = new Imagick();

        $imagick->readImageBlob($gdImage);
        $imagick->setImageFormat($targetFormat);

        $gd = imagecreatefromstring($imagick->getImageBlob());

        return $gd;
    }
}
