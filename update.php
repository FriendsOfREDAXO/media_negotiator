<?php

/** @var rex_addon $this */

// Up to 6.2.2 the negotiator stored a temp file path (cache/addons/media_negotiator/blob_*.avif)
// as media_path in the media manager's header cache, and never deleted those temp files.
// Clear both so no stale header points to a non-existent file.
// Only when updating from before 6.3.0: clearing the media manager cache makes every image
// be converted again on its next request, which puts a heavy load on the server.
if (rex_version::compare($this->getVersion(), '6.3.0', '<') && rex_addon::get('media_manager')->isAvailable()) {
    rex_media_manager::deleteCache();
}

foreach (glob(rex_path::addonCache('media_negotiator', 'blob_*')) ?: [] as $file) {
    rex_file::delete($file);
}
