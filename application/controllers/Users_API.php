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
        echo json_encode(array('success' => true, 'users' => $users));
    }

    // PATCH /Users_API/updateRole/{id} — admin only.
    // Body: role (pending|user|admin)
    public function updateRole($id = null)
    {
        $this->requireAdmin();

        if (empty($id)) {
            http_response_code(400);
            echo json_encode(array('success' => false, 'error' => 'Missing user id.'));
            return;
        }

        $data = $this->getInput();
        $newRole = isset($data['role']) ? $data['role'] : null;

        if (!$this->Users_Model->isValidRole($newRole)) {
            $allowed = implode(', ', $this->Users_Model->getAllowedRoles());
            http_response_code(400);
            echo json_encode(array('success' => false, 'error' => "Invalid role. Must be one of: {$allowed}"));
            return;
        }

        $existing = $this->Users_Model->find($id);
        if (!$existing) {
            http_response_code(404);
            echo json_encode(array('success' => false, 'error' => 'User not found.'));
            return;
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

        echo json_encode(array('success' => true, 'user' => $user));
    }

    // DELETE /Users_API/delete/{id} — admin only.
    public function delete($id = null)
    {
        $this->requireAdmin();

        if (empty($id)) {
            http_response_code(400);
            echo json_encode(array('success' => false, 'error' => 'Missing user id.'));
            return;
        }

        // Same safeguard as the original deleteUserAccount Cloud
        // Function — an admin should never be able to lock themselves
        // out by deleting their own currently-active account.
        $currentUser = $this->getCurrentUser();
        if ($currentUser !== null && (string) $currentUser['id'] === (string) $id) {
            http_response_code(400);
            echo json_encode(array('success' => false, 'error' => 'You cannot delete your own account.'));
            return;
        }

        $this->Users_Model->delete($id);
        echo json_encode(array('success' => true));
    }
}
