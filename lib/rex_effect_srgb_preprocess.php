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
    private const SRGB_PROFILE_PATH = 'data/icc/sRGB Profile.icc';

    private static ?string $srgbProfile = null;

    public function execute(): void
    {
        $source = $this->media->getSource();
        if ('' === $source) {
            return;
        }

        if (!class_exists(Imagick::class)) {
            return;
        }

        $srgbProfile = self::getSrgbProfile();
        if (null === $srgbProfile || '' === $srgbProfile) {
            return;
        }

        $imagick = new Imagick();
        try {
            $imagick->readImageBlob($source);

            // Match the core GD path: honour EXIF orientation before metadata is stripped.
            $imagick->autoOrient();

            $sourceProfiles = $imagick->getImageProfiles('icc', true);
            $hasSourceProfile = isset($sourceProfiles['icc']) && is_string($sourceProfiles['icc']) && '' !== $sourceProfiles['icc'];

            if (!$hasSourceProfile) {
                return;
            }

            $imagick->profileImage('icc', $srgbProfile);
            $imagick->stripImage();
            $imagick->profileImage('icc', $srgbProfile);

            $blob = $imagick->getImageBlob();
            $converted = imagecreatefromstring($blob);
            if (false === $converted) {
                return;
            }

            $this->media->setImage($converted);
            $this->media->refreshImageDimensions();
        } catch (Throwable) {
            // Best-effort preprocessor: on failure keep the original image unchanged.
        } finally {
            $imagick->clear();
            $imagick->destroy();
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

    private static function getSrgbProfile(): ?string
    {
        if (null !== self::$srgbProfile) {
            return self::$srgbProfile;
        }

        $profile = rex_file::get(rex_addon::get('media_negotiator')->getPath(self::SRGB_PROFILE_PATH));
        self::$srgbProfile = is_string($profile) && '' !== $profile ? $profile : '';

        return '' === self::$srgbProfile ? null : self::$srgbProfile;
    }
}