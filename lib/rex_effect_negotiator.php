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

        $possibleFormat = Helper::getRequestOutputFormat();

        if ($possibleFormat === 'avif') {
            $quality = Helper::getAvifQuality();
            $forceImagick = Helper::getForceImagick();

            // Converter priority: vips > imagick > GD
            $useVips = !$forceImagick && Helper::vipsPossible();
            $useImagick = !$useVips && (
                $forceImagick
                || !function_exists('imageavif')
                || ($quality !== 60 && class_exists(\Imagick::class))
            );

            if ($useVips) {
                try {
                    $converted = Helper::vipsConvert($this->media->getSource(), 'avif', $quality);
                    if ($converted === false) {
                        return;
                    }
                    $this->media->setImage($converted);
                    $this->media->setFormat('avif');
                    $this->media->setHeader('Content-Type', 'image/avif');
                    $this->media->refreshImageDimensions();
                } catch (\Exception $e) {
                    return;
                }
            } elseif ($useImagick) {
                try {
                    $converted = Helper::imagickConvert($this->media->getSource(), 'avif', $quality);
                    if ($converted === false) {
                        return;
                    }
                    $this->media->setImage($converted);
                    $this->media->setFormat('avif');
                    $this->media->setHeader('Content-Type', 'image/avif');
                    $this->media->refreshImageDimensions();
                } catch (\Exception $e) {
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
            $forceImagick = Helper::getForceImagick();

            $useVips = !$forceImagick && Helper::vipsPossible();
            $useImagick = !$useVips && (
                $forceImagick
                || !function_exists('imagewebp')
                || ($quality !== 80 && class_exists(\Imagick::class))
            );

            if ($useVips) {
                try {
                    $converted = Helper::vipsConvert($this->media->getSource(), 'webp', $quality);
                    if ($converted === false) {
                        return;
                    }
                    $this->media->setImage($converted);
                    $this->media->setFormat('webp');
                    $this->media->setHeader('Content-Type', 'image/webp');
                    $this->media->refreshImageDimensions();
                } catch (\Exception $e) {
                    return;
                }
            } elseif ($useImagick) {
                try {
                    $converted = Helper::imagickConvert($this->media->getSource(), 'webp', $quality);
                    if ($converted === false) {
                        return;
                    }
                    $this->media->setImage($converted);
                    $this->media->setFormat('webp');
                    $this->media->setHeader('Content-Type', 'image/webp');
                    $this->media->refreshImageDimensions();
                } catch (\Exception $e) {
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
