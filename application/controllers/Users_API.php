<?php
defined('BASEPATH') or exit('No direct script access allowed');

require_once APPPATH . 'libraries/Account_mail.php';
require_once APPPATH . 'libraries/Account_policy.php';

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
        $this->load->model('Email_Model');
    }

    // GET /Users_API/getAll — admin only.
    public function getAll()
    {
        $this->requireAdmin();
        $users = $this->Users_Model->getAll();
        return Api_response::ok(array('users' => $users));
    }

    // POST /Users_API/create — admin only.
    // Body: email, password, name, role (pending|user|admin)
    //
    // Distinct from Auth_API::register: that's self-service and always
    // lands on 'pending'; this is an admin vouching for the account up
    // front, so it can be created straight into 'user' or 'admin'.
    public function create()
    {
        $this->requireAdmin();

        $data = $this->getInput();
        $email = isset($data['email']) ? trim(strtolower($data['email'])) : '';
        $password = isset($data['password']) ? $data['password'] : '';
        $name = isset($data['name']) ? trim($data['name']) : '';
        $role = isset($data['role']) ? $data['role'] : '';

        if ($email === '' || $password === '' || $name === '') {
            return Api_response::fail(400, 'Email, password, and name are all required.');
        }

        if (!$this->Users_Model->isValidRole($role)) {
            $allowed = implode(', ', $this->Users_Model->getAllowedRoles());
            return Api_response::fail(400, "Invalid role. Must be one of: {$allowed}");
        }

        Account_policy::requireEmailDomain($email);
        Account_policy::requirePassword($password);

        if ($this->Auth_Model->emailExists($email)) {
            return Api_response::fail(409, 'An account with this email already exists.');
        }

        $passwordHash = password_hash($password, PASSWORD_DEFAULT);
        $user = $this->Users_Model->create($email, $passwordHash, $name, $role);

        if ($role !== 'pending') {
            $created = Account_mail::accountCreatedByAdmin($name);
            $this->Email_Model->enqueue($email, $created['subject'], $created['html']);
        }

        return Api_response::ok(array('user' => $user));
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

        // Two ways an admin could lock everyone out of admin access: by
        // demoting themselves, or by demoting the only admin left.
        if ($existing['role'] === 'admin' && $newRole !== 'admin') {
            $currentUser = $this->getCurrentUser();
            if ($currentUser !== null && (string) $currentUser['id'] === (string) $id) {
                return Api_response::fail(400, 'You cannot remove your own admin access.');
            }
            if ($this->Users_Model->countAdmins() <= 1) {
                return Api_response::fail(400, 'You cannot remove the last admin.');
            }
        }

        $wasApproved = $existing['role'] === 'pending' && $newRole !== 'pending';

        $user = $this->Users_Model->updateRole($id, $newRole);

        if ($wasApproved) {
            $approved = Account_mail::accountApproved($user['name']);
            $this->Email_Model->enqueue($user['email'], $approved['subject'], $approved['html']);
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
