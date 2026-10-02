<?php

// Up to 6.2.2 the negotiator stored a temp file path (cache/addons/media_negotiator/blob_*.avif)
// as media_path in the media manager's header cache, and never deleted those temp files.
// Clear both so no stale header points to a non-existent file.
if (rex_addon::get('media_manager')->isAvailable()) {
    rex_media_manager::deleteCache();
}

foreach (glob(rex_path::addonCache('media_negotiator', 'blob_*')) ?: [] as $file) {
    rex_file::delete($file);
}
