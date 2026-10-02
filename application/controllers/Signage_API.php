<?php
defined('BASEPATH') or exit('No direct script access allowed');

require_once APPPATH . 'libraries/Signage_rules.php';

// Signage: the images, GIFs and looping videos the kiosk shows in its
// bottom band (see Signage_rules for what a slide is, and why nothing here
// is named "ad"). getPublic is open, since the kiosk shows it to anyone;
// everything else is admin-only.
//
// Media files are uploaded first (upload), then a slide is saved pointing
// at the returned path, the same two-step flow as every other photo. Unlike
// other photos, a slide's file is deleted along with the slide (or when
// the slide's media is replaced) as long as nothing else points at it:
// ads are short-lived campaign files, and leaving each one behind as an
// orphan in the photo gallery would be the normal case, not the exception.
class Signage_API extends MY_Controller
{
    public function __construct()
    {
        parent::__construct();
        $this->load->model('Signage_Model');
    }

    // GET /Signage_API/getPublic: public. The slides on the kiosk right
    // now, in rotation order, plus the rotation settings. The kiosk polls
    // this, so a slide's run window takes effect without a redeploy.
    public function getPublic()
    {
        return Api_response::ok(array(
            'slides' => $this->Signage_Model->getLive(date('Y-m-d H:i:s')),
            'settings' => $this->Signage_Model->getSettings(),
            'server_time' => date('Y-m-d H:i:s'),
        ));
    }

    // GET /Signage_API/getAll: admin only. Every slide, live or not.
    // server_time lets the admin page judge "live / scheduled / ended"
    // against the same clock getPublic uses, not the admin's own.
    public function getAll()
    {
        $this->requireAdmin();

        return Api_response::ok(array(
            'slides' => $this->Signage_Model->getAll(),
            'settings' => $this->Signage_Model->getSettings(),
            'server_time' => date('Y-m-d H:i:s'),
        ));
    }

    // POST /Signage_API/upload: admin only.
    // multipart/form-data: file (image or MP4/WebM video), filename.
    // Replies { success: true, path: "signage/filename.ext" }.
    public function upload()
    {
        $this->requireAdmin();
        return $this->savePhoto('signage');
    }

    // POST /Signage_API/create: admin only.
    // Body: title, media_path (required); crop_x/y/w/h, duration_seconds,
    // is_active, starts_at, ends_at (optional). A slide with no duration
    // takes the settings' default.
    public function create()
    {
        $this->requireAdmin();

        $fields = Signage_rules::slideFields($this->getInput(), true);
        Signage_rules::requireWindowOrder(
            isset($fields['starts_at']) ? $fields['starts_at'] : null,
            isset($fields['ends_at']) ? $fields['ends_at'] : null
        );
        if (!isset($fields['duration_seconds'])) {
            $settings = $this->Signage_Model->getSettings();
            $fields['duration_seconds'] = (float) $settings['default_duration_seconds'];
        }

        $slide = $this->Signage_Model->create($fields);
        return Api_response::ok(array('slide' => $slide));
    }

    // PATCH /Signage_API/update/{id}: admin only. Any subset of create's
    // fields.
    public function update($id = null)
    {
        $this->requireAdmin();
        Api_input::requireId($id, 'slide');

        $existing = $this->Signage_Model->find($id);
        if ($existing === null) {
            return Api_response::fail(404, 'Slide not found.');
        }

        $fields = Signage_rules::slideFields($this->getInput(), false);
        if (empty($fields)) {
            return Api_response::fail(400, 'No valid fields to update.');
        }
        $merged = array_merge($existing, $fields);
        Signage_rules::requireWindowOrder($merged['starts_at'], $merged['ends_at']);

        $slide = $this->Signage_Model->update($id, $fields);
        if (isset($fields['media_path']) && $fields['media_path'] !== $existing['media_path']) {
            $this->discardMediaIfUnused($existing['media_path']);
        }
        return Api_response::ok(array('slide' => $slide));
    }

    // DELETE /Signage_API/delete/{id}: admin only.
    public function delete($id = null)
    {
        $this->requireAdmin();
        Api_input::requireId($id, 'slide');

        $existing = $this->Signage_Model->find($id);
        if ($existing === null) {
            return Api_response::fail(404, 'Slide not found.');
        }

        $this->Signage_Model->delete($id);
        $this->discardMediaIfUnused($existing['media_path']);
        return Api_response::ok();
    }

    // POST /Signage_API/reorder: admin only.
    // Body: ids, every slide id in the new rotation order.
    public function reorder()
    {
        $this->requireAdmin();

        $data = $this->getInput();
        $ids = Signage_rules::reorderIds(
            isset($data['ids']) ? $data['ids'] : null,
            $this->Signage_Model->allIds()
        );
        if (!$this->Signage_Model->reorder($ids)) {
            return Api_response::fail(500, 'Could not save the new order.');
        }
        return Api_response::ok(array('slides' => $this->Signage_Model->getAll()));
    }

    // PATCH /Signage_API/settings: admin only.
    // Body: any of rotation_order, transition, default_duration_seconds.
    public function settings()
    {
        $this->requireAdmin();

        $fields = Signage_rules::settingsFields($this->getInput());
        return Api_response::ok(array('settings' => $this->Signage_Model->updateSettings($fields)));
    }

    // Removes a signage file nothing references any more. Re-reads the
    // references (another slide may share the file, if an admin re-picked
    // an earlier upload's name). A failure only leaves an orphan behind,
    // which the photo gallery can still clean up, so it never fails the
    // request.
    protected function discardMediaIfUnused($path)
    {
        if (!is_string($path) || $path === '' || isset($this->referencedPhotoPaths()[$path])) {
            return;
        }
        $result = $this->photoStore()->remove($path);
        if (!$result['ok'] && $result['reason'] !== 'not_found') {
            log_message('error', "Signage: could not delete unused media {$path}: {$result['error']}");
        }
    }
}
