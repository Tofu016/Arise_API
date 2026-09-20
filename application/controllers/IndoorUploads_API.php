<?php
defined('BASEPATH') or exit('No direct script access allowed');

// Handles indoor content — room photos, room 360s, and node panoramas.
// Files are stored by the Photo store under its protected root, OUTSIDE
// htdocs entirely (see MY_Controller::photoStore()), not just blocked
// via .htaccess — a genuinely unreachable location is a stronger
// guarantee than a rule that has to stay correctly configured.
//
// Viewing used to require requireApproved() on serve() below, matching
// MainPage.jsx being login-gated. Now that MainPage is genuinely public
// (no RequireAuth wrapper — see App.jsx), that check has been removed
// entirely here too. Uploading stays admin-only regardless — visitors
// can view, only admins can add or change what's shown.
class IndoorUploads_API extends MY_Controller
{
    // POST /IndoorUploads_API/roomPhoto — admin only.
    // multipart/form-data: file (required), building (required),
    // filename (optional override)
    public function roomPhoto()
    {
        $this->handleUpload('roomphoto');
    }

    // POST /IndoorUploads_API/room360Photo — admin only.
    public function room360Photo()
    {
        $this->handleUpload('room360');
    }

    // POST /IndoorUploads_API/panoramaReview — admin only.
    // Temporary holding area for a brand-new upload not yet reviewed.
    public function panoramaReview()
    {
        $this->handleUpload('panoramas-review');
    }

    // POST /IndoorUploads_API/panoramaPublish — admin only.
    // The real, permanent location — matches copyPanoramaFile's own
    // panoramas/{building}/{filename} shape exactly.
    public function panoramaPublish()
    {
        $this->handleUpload('panoramas');
    }

    private function handleUpload($category)
    {
        $this->requireAdmin();
        $building = isset($_POST['building']) ? $_POST['building'] : null;
        $this->savePhoto($category, $building);
    }

    // POST /IndoorUploads_API/deleteReviewFile — admin only.
    // Deliberately restricted to ONLY the panoramas-review/ category —
    // this endpoint has no business deleting anything from panoramas/,
    // roomphoto/, or room360/ at all.
    public function deleteReviewFile()
    {
        $this->requireAdmin();

        $data = $this->getInput();
        $path = isset($data['path']) ? $data['path'] : '';

        $segments = explode('/', $path);
        if (empty($segments) || $segments[0] !== 'panoramas-review') {
            http_response_code(400);
            echo json_encode(array('success' => false, 'error' => 'Invalid path.'));
            return;
        }

        $result = $this->photoStore()->remove($path);
        if (!$result['ok'] && $result['reason'] === 'invalid_path') {
            $this->respondWithPhotoFailure($result);
            return;
        }

        // Already gone is as good as deleted.
        echo json_encode(array('success' => true));
    }

    // GET /IndoorUploads_API/serve?path=roomphoto/gd1/somefile.jpg
    // No auth check anymore — indoor content is genuinely public now,
    // matching MainPage.jsx no longer requiring an account to view.
    // Uploading (above) stays admin-only regardless; this only governs
    // viewing. Query param, not a URL segment, since a real path
    // contains slashes CI3's own segment routing would otherwise split
    // apart. Only protected photos are streamed here — public ones are
    // served straight from disk by Apache.
    public function serve()
    {
        $path = $this->input->get('path');
        if (empty($path)) {
            http_response_code(400);
            echo 'Missing path.';
            return;
        }

        $photo = $this->photoStore()->resolve($path);
        if (!$photo['ok']) {
            http_response_code($photo['reason'] === 'invalid_path' ? 400 : 404);
            echo $photo['reason'] === 'invalid_path' ? 'Invalid path.' : 'Not found.';
            return;
        }
        if ($photo['visibility'] !== 'protected') {
            http_response_code(404);
            echo 'Not found.';
            return;
        }

        header('Content-Type: ' . $photo['content_type']);
        header('Content-Length: ' . filesize($photo['full_path']));
        readfile($photo['full_path']);
    }
}
