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
    if ($page === 'media_manager/media_negotiator/warmup') {
        rex_view::addJsFile(rex_url::addonAssets('media_negotiator', 'warmup.js'));
    }
}

rex_extension::register('MEDIA_MANAGER_INIT', function (rex_extension_point $ep) {
    $mediaManager = $ep->getSubject();
    $type = $ep->getParam('type');
    $effects = $mediaManager->effectsFromType($type);

    foreach ($effects as $effect) {
        if ($effect['effect'] === 'negotiator') {
            // change cache path for negotiator
            $possible_types = rex_server('HTTP_ACCEPT', 'string', '');
            $types = explode(',', $possible_types);
            $possibleFormat = FriendsOfRedaxo\MediaNegotiator\Helper::getOutputFormat($types);

            // UA fallback for cache path when Accept header carries no explicit image format
            if ($possibleFormat === 'default' && FriendsOfRedaxo\MediaNegotiator\Helper::uaFallbackEnabled()) {
                $userAgent = rex_server('HTTP_USER_AGENT', 'string', '');
                $possibleFormat = FriendsOfRedaxo\MediaNegotiator\Helper::getOutputFormatFromUserAgent($userAgent);
            }

            // Include effective conversion config in cache key so changed quality/settings
            // produce fresh derivatives instead of serving stale cached files.
            $cacheKey = $possibleFormat;
            if ($possibleFormat === 'webp') {
                $cacheKey .= '-q' . FriendsOfRedaxo\MediaNegotiator\Helper::getWebpQuality();
            } elseif ($possibleFormat === 'avif') {
                $cacheKey .= '-q' . FriendsOfRedaxo\MediaNegotiator\Helper::getAvifQuality();
            }

            $cacheKey .= '-im' . ((bool) rex_config::get('media_negotiator', 'force_imagick', false) ? '1' : '0');
            $cacheKey .= '-davif' . ((bool) rex_config::get('media_negotiator', 'disable_avif', false) ? '1' : '0');
            $cacheKey .= '-pref' . FriendsOfRedaxo\MediaNegotiator\Helper::getPreferredFormat();

            $mediaManager->setCachePath($mediaManager->getCachePath() . $cacheKey . '-');

            return;
        }
    }
});



