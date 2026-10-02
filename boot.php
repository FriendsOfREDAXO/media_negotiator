<?php

if (rex_addon::get('media_manager')->isAvailable()) {
    rex_media_manager::addEffect(rex_effect_negotiator::class);
    rex_media_manager::addEffect(rex_effect_srgb_preprocess::class);
}

if (rex::isBackend()) {
    $page = rex_request('page', 'string', '');
    if ($page === 'media_manager/media_negotiator/setup') {
        rex_view::addJsFile(rex_url::addonAssets('media_negotiator', 'setup_compare.js'));
    }
}

rex_extension::register('MEDIA_MANAGER_INIT', function (rex_extension_point $ep) {
    $mediaManager = $ep->getSubject();
    $type = $ep->getParam('type');
    $effects = $mediaManager->effectsFromType($type);

    foreach ($effects as $effect) {
        if ($effect['effect'] === 'negotiator') {
            // change cache path for negotiator
            $possibleFormat = FriendsOfRedaxo\MediaNegotiator\Helper::getRequestOutputFormat();

            // Include effective conversion config in cache key so changed quality/settings
            // produce fresh derivatives instead of serving stale cached files.
            $cacheKey = $possibleFormat;
            if ($possibleFormat === 'webp') {
                $cacheKey .= '-q' . FriendsOfRedaxo\MediaNegotiator\Helper::getWebpQuality();
            } elseif ($possibleFormat === 'avif') {
                $cacheKey .= '-q' . FriendsOfRedaxo\MediaNegotiator\Helper::getAvifQuality();
                $cacheKey .= '-acp' . FriendsOfRedaxo\MediaNegotiator\Helper::getAvifConverterPreference();
                $cacheKey .= '-ace' . FriendsOfRedaxo\MediaNegotiator\Helper::getEffectiveAvifConverter();
            }

            $cacheKey .= '-im' . ((bool) rex_config::get('media_negotiator', 'force_imagick', false) ? '1' : '0');
            $cacheKey .= '-davif' . ((bool) rex_config::get('media_negotiator', 'disable_avif', false) ? '1' : '0');
            $cacheKey .= '-pref' . FriendsOfRedaxo\MediaNegotiator\Helper::getPreferredFormat();

            $mediaManager->setCachePath($mediaManager->getCachePath() . $cacheKey . '-');

            return;
        }
    }
});



