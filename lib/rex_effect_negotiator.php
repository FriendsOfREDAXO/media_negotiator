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
        try {
            // Skip non-raster formats that cannot be converted (SVG, GIF, ICO)
            $skipExtensions = ['svg', 'gif', 'ico'];
            $mediaPath = $this->media->getMediaPath() ?? '';
            if (in_array(strtolower(rex_file::extension($mediaPath)), $skipExtensions, true)) {
                return;
            }

            $possibleFormat = Helper::getRequestOutputFormat();

            // Downgrade AVIF to WebP only if neither GD nor other converters support it
            if ($possibleFormat === 'avif' && !Helper::avifPossible()) {
                $possibleFormat = Helper::webpPossible() ? 'webp' : 'default';
            }

            // Downgrade WebP to default only if no converter supports it at all
            if ($possibleFormat === 'webp' && !Helper::webpPossible()) {
                $possibleFormat = 'default';
            }

            if ($possibleFormat === 'avif') {
                $this->convertWithFallback('avif', Helper::getAvifQuality(), 60);
            } elseif ($possibleFormat === 'webp') {
                $this->convertWithFallback('webp', Helper::getWebpQuality(), 80);
            }
            // else: deliver original file unchanged
        } catch (\Throwable) {
            // Never fail the media request because of negotiator issues.
        }
    }

    private function convertWithFallback(string $targetFormat, int $quality, int $defaultQuality): void
    {
        // If AVIF cannot be decoded by GD, try WebP instead.
        if ($targetFormat === 'avif' && !function_exists('imageavif') && !Helper::avifPossible()) {
            $this->convertWithFallback('webp', Helper::getWebpQuality(), 80);
            return;
        }

        // Use current effect-chain output as source (not original file path),
        // so resize/crop/content_builder transformations are preserved.
        try {
            $sourceBlob = $this->media->getSource();
        } catch (\Throwable) {
            return;
        }

        if ($sourceBlob === '') {
            return;
        }

        // Converter order.
        // AVIF order is configurable in addon settings. In auto mode we keep
        // vips > gd > imagick, so vips is preferred whenever available.
        // WebP remains fixed at vips > imagick > gd.
        $converters = ($targetFormat === 'avif')
            ? Helper::getAvifConverterOrder()
            : ['vips', 'imagick', 'gd'];

        foreach ($converters as $converter) {
            $result = false;

            if ($converter === 'vips' && Helper::vipsPossible()) {
                try {
                    $result = Helper::vipsConvert($sourceBlob, $targetFormat, $quality);
                    if (is_string($result)) {
                        // Blob output - write to temp and set as source path
                        $this->setSourceFromBlob($result, $targetFormat);
                        return;
                    }
                } catch (\Throwable) {
                    // Continue to next converter
                }
            }

            if ($converter === 'gd') {
                try {
                    $result = Helper::gdConvert($sourceBlob, $targetFormat, $quality);
                    if (is_string($result)) {
                        // Blob output - write to temp and set as source path
                        $this->setSourceFromBlob($result, $targetFormat);
                        return;
                    }
                } catch (\Throwable) {
                    // Continue to next converter
                }
            }

            if ($converter === 'imagick' && class_exists('Imagick')) {
                try {
                    $result = Helper::imagickConvert($sourceBlob, $targetFormat, $quality);
                    if (is_string($result)) {
                        // Blob output - write to temp and set as source path
                        $this->setSourceFromBlob($result, $targetFormat);
                        return;
                    }
                } catch (\Throwable) {
                    // Continue to next converter
                }
            }
        }

        // All converters failed, keep original image
    }

    private function setSourceFromBlob(string $blob, string $format): void
    {
        // Write blob to temp file with correct format extension
        $tempFilename = uniqid('blob_', true) . '.' . $format;
        $tempPath = rex_path::addonCache('media_negotiator', $tempFilename);
        rex_file::put($tempPath, $blob);
        
        // Set media path (this will extract filename from path) and then set format
        $this->media->setMediaPath($tempPath);
        $this->media->setFormat($format);
        
        // Set both Content-Type AND Content-Disposition headers
        $this->media->setHeader('Content-Type', 'image/' . $format);
        $this->media->setHeader('Content-Disposition', 'inline; filename="' . $tempFilename . '";');
    }
}
