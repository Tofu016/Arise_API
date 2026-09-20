<?php
defined('BASEPATH') or exit('No direct script access allowed');

// Admin-only throughout — unlike every other resource built so far,
// nothing here is public; a user list is never navigation data.
// updateRole() replicates sendApprovalEmail's original behavior
// (queuing a notification specifically when an account moves away from
// "pending" for the first time), and delete() replicates
// deleteUserAccount's real safeguard against an admin deleting their
// own account.
class Users_API extends MY_Controller
{
    public function __construct()
    {
        parent::__construct();
        $this->load->model('Users_Model');
        // Auth_Model is already loaded by MY_Controller's own
        // constructor (needed for getCurrentUser()) — reused here for
        // queueEmail(), not loaded a second time.
    }

    // GET /Users_API/getAll — admin only.
    public function getAll()
    {
        $this->requireAdmin();
        $users = $this->Users_Model->getAll();
        return Api_response::ok(array('users' => $users));
    }

    // PATCH /Users_API/updateRole/{id} — admin only.
    // Body: role (pending|user|admin)
    public function updateRole($id = null)
    {
        $this->requireAdmin();

        Api_input::requireId($id, 'user');

        $data = $this->getInput();
        $newRole = isset($data['role']) ? $data['role'] : null;

        if (!$this->Users_Model->isValidRole($newRole)) {
            $allowed = implode(', ', $this->Users_Model->getAllowedRoles());
            return Api_response::fail(400, "Invalid role. Must be one of: {$allowed}");
        }

        $existing = $this->Users_Model->find($id);
        if (!$existing) {
            return Api_response::fail(404, 'User not found.');
        }

        $wasApproved = $existing['role'] === 'pending' && $newRole !== 'pending';

        $user = $this->Users_Model->updateRole($id, $newRole);

        if ($wasApproved) {
            $this->Auth_Model->queueEmail(
                $user['email'],
                'Your ARISE Campus Navigator account has been approved',
                '<p>Hi ' . htmlspecialchars($user['name']) . ',</p><p>Your account has been approved. You can now log in and start using ARISE Campus Navigator.</p>'
            );
        }

        return Api_response::ok(array('user' => $user));
    }

    // DELETE /Users_API/delete/{id} — admin only.
    public function delete($id = null)
    {
        $this->requireAdmin();

        Api_input::requireId($id, 'user');

        // Same safeguard as the original deleteUserAccount Cloud
        // Function — an admin should never be able to lock themselves
        // out by deleting their own currently-active account.
        $currentUser = $this->getCurrentUser();
        if ($currentUser !== null && (string) $currentUser['id'] === (string) $id) {
            return Api_response::fail(400, 'You cannot delete your own account.');
        }

        $this->Users_Model->delete($id);
        return Api_response::ok();
    }
}
