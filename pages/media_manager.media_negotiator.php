<?php

$package = rex_addon::get('media_negotiator');
echo rex_view::title($package->i18n('media_negotiator_title'));
rex_be_controller::includeCurrentPageSubPath();
