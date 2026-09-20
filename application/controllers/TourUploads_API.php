<?php
defined('BASEPATH') or exit('No direct script access allowed');

// Handles uploads for genuinely public Virtual Tour content — panoramas,
// section covers, marker photos. Uploads themselves still require admin
// auth (extends MY_Controller, calls requireAdmin()) even though the
// resulting files are freely, directly viewable afterward — anyone can
// look at a tour photo, but only an admin can add one.
//
// Files are saved by the Photo store (see application/libraries/
// Photo_store.php) under the public root, which Apache serves as plain
// static files — no PHP involvement needed just to view them, matching
// the original Firebase behavior where these specific paths were the
// ones marked publicly readable in storage.rules. Same three path
// prefixes as before (tourpanorama/, tourcover/, tourmarker/) preserved
// deliberately — useSecurePhotoUrl.js's own rewrite recognizes these
// exact prefixes to decide "resolve this as a direct public URL" versus
// "fall back to the old Firebase logic for anything not migrated yet."
class TourUploads_API extends MY_Controller
{
    // POST /TourUploads_API/panorama — admin only.
    // multipart/form-data: file (required), filename (optional override)
    // Replies { success: true, path: "tourpanorama/filename.jpg" } — the
    // same shape as the original Firebase-based upload functions.
    public function panorama()
    {
        $this->requireAdmin();
        return $this->savePhoto('tourpanorama');
    }

    // POST /TourUploads_API/cover — admin only.
    public function cover()
    {
        $this->requireAdmin();
        return $this->savePhoto('tourcover');
    }

    // POST /TourUploads_API/marker — admin only.
    public function marker()
    {
        $this->requireAdmin();
        return $this->savePhoto('tourmarker');
    }
}
