<?php

use FriendsOfRedaxo\MediaNegotiator\Warmup;

/**
 * Bild-Cache vorwärmen aus dem Backend (Seite „Vorwärmen“): start, step (ein Häppchen von ~20 s), status, cancel.
 * Nur im Backend, mit CSRF-Token und dem Recht media_negotiator[].
 */
class rex_api_media_negotiator_warmup extends rex_api_function
{
    protected $published = false;

    protected function requiresCsrfProtection(): bool
    {
        return true;
    }

    public function execute(): rex_api_result
    {
        $user = rex::getUser();
        if (!$user || !$user->hasPerm('media_negotiator[]')) {
            throw new rex_api_exception('Not authorized');
        }
        // die Session nicht blockieren: Vorschau und andere Backend-Aufrufe laufen parallel weiter
        if (PHP_SESSION_ACTIVE === session_status()) {
            session_write_close();
        }
        @set_time_limit(120);

        $status = match (rex_request('action', 'string', 'status')) {
            'start' => Warmup::start(rex_request('concurrency', 'int', 4)),
            'step' => Warmup::step(20.0),
            'resume' => Warmup::resume(rex_request('concurrency', 'int', 4)),
            'cancel' => Warmup::cancel(),
            default => Warmup::status(),
        };

        rex_response::cleanOutputBuffers();
        rex_response::setHeader('Cache-Control', 'no-store');
        rex_response::sendJson($status);
        exit;
    }
}
