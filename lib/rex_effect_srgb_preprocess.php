<?php

/**
 * Converts the current image to the sRGB colour space before later effects run.
 *
 * This effect should be placed as early as possible in the media manager stack
 * so wide-gamut uploads (for example Adobe RGB JPEGs) are normalised before GD
 * or later conversion effects generate web derivatives.
 */
class rex_effect_srgb_preprocess extends rex_effect_abstract
{
    public function execute(): void
    {
        if (!class_exists(Imagick::class)) {
            return;
        }

        $source = $this->media->getSource();
        if ('' === $source) {
            return;
        }

        try {
            $imagick = new Imagick();
            $imagick->readImageBlob($source);

            // Match the core GD path: honour EXIF orientation before metadata is stripped.
            $imagick->autoOrient();

            $imagick->transformImageColorspace(Imagick::COLORSPACE_SRGB);
            $imagick->stripImage();

            $converted = imagecreatefromstring($imagick->getImageBlob());
            if (false === $converted) {
                return;
            }

            $this->media->setImage($converted);
            $this->media->refreshImageDimensions();
        } catch (Throwable) {
            // Best-effort preprocessor: on failure keep the original image unchanged.
        }
    }

    public function getName(): string
    {
        return rex_i18n::msg('media_negotiator_effect_srgb_preprocess_name');
    }

    /** @return list<array<string, mixed>> */
    public function getParams(): array
    {
        return [
            [
                'label' => rex_i18n::msg('media_negotiator_effect_srgb_preprocess_hint_label'),
                'name' => 'hint',
                'type' => 'string',
                'default' => '',
                'notice' => rex_i18n::msg('media_negotiator_effect_srgb_preprocess_hint_notice'),
                'attributes' => ['readonly' => 'readonly'],
            ],
        ];
    }
}