<?php

use FriendsOfRedaxo\MediaNegotiator\Helper;

class rex_effect_negotiator extends rex_effect_abstract
{

    public function getName(): string
    {
        return rex_i18n::msg('media_negotiator_effect_name');
    }

    public function execute(): void
    {
        // Skip non-raster formats that cannot be converted (SVG, GIF, ICO)
        $skipExtensions = ['svg', 'gif', 'ico'];
        $mediaPath = $this->media->getMediaPath() ?? '';
        if (in_array(strtolower(rex_file::extension($mediaPath)), $skipExtensions, true)) {
            return;
        }

        // Get image mime types accepted by the requesting browser
        $possible_types = rex_server('HTTP_ACCEPT', 'string', '');
        $types = explode(',', $possible_types);

        $possibleFormat = Helper::getOutputFormat($types);

        // User-Agent fallback: when Accept header doesn't carry explicit image format info.
        // Typical case: Safari >= 16.4 supports AVIF but sends only */* in Accept.
        if ($possibleFormat === 'default' && Helper::uaFallbackEnabled()) {
            $userAgent = rex_server('HTTP_USER_AGENT', 'string', '');
            $possibleFormat = Helper::getOutputFormatFromUserAgent($userAgent);
        }

        if ($possibleFormat === 'avif') {
            $quality = Helper::getAvifQuality();
            // Use Imagick when: force_imagick=true, imageavif() unavailable, or non-default quality with Imagick present
            $useImagick = (bool) rex_config::get('media_negotiator', 'force_imagick', false)
                || !function_exists('imageavif')
                || ($quality !== 60 && class_exists(\Imagick::class));

            if ($useImagick) {
                try {
                    $img = $this->media->getSource();
                    $converted = Helper::imagickConvert($img, 'avif', $quality);
                    if ($converted === false) {
                        return;
                    }
                    $this->media->setImage($converted);
                    $this->media->setFormat('avif');
                    $this->media->setHeader('Content-Type', 'avif');
                    $this->media->refreshImageDimensions();
                } catch (\Exception $e) {
                    // Conversion failed (e.g. timeout, memory limit) – deliver original
                    return;
                }
            } else {
                $re = new rex_effect_image_format();
                $re->media = $this->media;
                $re->params['convert_to'] = 'avif';
                $re->execute();
            }
        } elseif ($possibleFormat === 'webp') {
            $quality = Helper::getWebpQuality();
            $useImagick = (bool) rex_config::get('media_negotiator', 'force_imagick', false)
                || !function_exists('imagewebp')
                || ($quality !== 80 && class_exists(\Imagick::class));

            if ($useImagick) {
                try {
                    $img = $this->media->getSource();
                    $converted = Helper::imagickConvert($img, 'webp', $quality);
                    if ($converted === false) {
                        return;
                    }
                    $this->media->setImage($converted);
                    $this->media->setFormat('webp');
                    $this->media->setHeader('Content-Type', 'webp');
                    $this->media->refreshImageDimensions();
                } catch (\Exception $e) {
                    // Conversion failed – deliver original
                    return;
                }
            } else {
                $re = new rex_effect_image_format();
                $re->media = $this->media;
                $re->params['convert_to'] = 'webp';
                $re->execute();
            }
        }
        // else: deliver original file unchanged
    }
}
