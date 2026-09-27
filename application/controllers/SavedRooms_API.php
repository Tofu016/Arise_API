<?php
defined('BASEPATH') or exit('No direct script access allowed');

// The signed-in user's saved rooms — the bookmark on the mobile app's room
// card. Every action is for approved accounts only (requireApprovedUser)
// and works on the caller's own list, so no one can see or change anyone
// else's. Saving a room already saved, or removing one that isn't, is
// harmless: the app retries freely without having to know the state.
class SavedRooms_API extends MY_Controller
{
    // How many rooms one account may save.
    const LIMIT = 20;

    public function __construct()
    {
        parent::__construct();
        $this->load->model('SavedRooms_Model');
        $this->load->model('PlacardDialogs_Model');
    }

    // GET /SavedRooms_API/getMine — newest first.
    public function getMine()
    {
        $user = $this->requireApprovedUser();

        $saved = $this->SavedRooms_Model->getForUser($user['id']);
        return Api_response::ok(array('saved' => $saved, 'limit' => self::LIMIT));
    }

    // POST /SavedRooms_API/save — body: placard_dialog_id.
    public function save()
    {
        $user = $this->requireApprovedUser();

        $data = $this->getInput();
        $id = isset($data['placard_dialog_id']) ? trim((string) $data['placard_dialog_id']) : '';
        if (!ctype_digit($id) || (int) $id === 0) {
            return Api_response::fail(400, 'placard_dialog_id is required.');
        }
        $id = (int) $id;

        if (!$this->PlacardDialogs_Model->find($id)) {
            return Api_response::fail(404, 'Room not found.');
        }
        if ($this->SavedRooms_Model->isSaved($user['id'], $id)) {
            return Api_response::ok(array('placard_dialog_id' => $id));
        }
        if ($this->SavedRooms_Model->countForUser($user['id']) >= self::LIMIT) {
            return Api_response::fail(409, 'You can save up to ' . self::LIMIT . ' rooms. Remove one to save another.');
        }

        $this->SavedRooms_Model->save($user['id'], $id);
        return Api_response::ok(array('placard_dialog_id' => $id));
    }

    // DELETE /SavedRooms_API/remove/{placardDialogId}
    public function remove($id = null)
    {
        $user = $this->requireApprovedUser();

        Api_input::requireId($id, 'room');

        $this->SavedRooms_Model->remove($user['id'], (int) $id);
        return Api_response::ok();
    }
}
