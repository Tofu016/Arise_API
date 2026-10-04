<?php
defined('BASEPATH') or exit('No direct script access allowed');

require_once APPPATH . 'libraries/Account_policy.php';
require_once APPPATH . 'libraries/Account_mail.php';

// Admin accounts, managed from the User Panel, including approving the
// ones that registered themselves. Admin-only throughout:
// nothing here is public. There are no other kinds of account.
class Admins_API extends MY_Controller
{
    public function __construct()
    {
        parent::__construct();
        $this->load->model('Admins_Model');
    }

    // GET /Admins_API/getAll
    public function getAll()
    {
        $this->requireAdmin();
        return Api_response::ok(array('admins' => $this->Admins_Model->getAll()));
    }

    // POST /Admins_API/create
    // Body: email, password, name
    public function create()
    {
        $this->requireAdmin();

        $data = $this->getInput();
        $email = isset($data['email']) ? trim(strtolower($data['email'])) : '';
        $password = isset($data['password']) ? $data['password'] : '';
        $name = isset($data['name']) ? trim($data['name']) : '';

        if ($email === '' || $password === '' || $name === '') {
            return Api_response::fail(400, 'Email, password, and name are all required.');
        }

        Account_policy::requireEmailDomain($email);
        Account_policy::requirePassword($password);

        if ($this->Admins_Model->emailExists($email)) {
            return Api_response::fail(409, 'An account with this email already exists.');
        }

        $admin = $this->Admins_Model->create($email, password_hash($password, PASSWORD_DEFAULT), $name);
        return Api_response::ok(array('admin' => $admin));
    }

    // PATCH /Admins_API/approve/{id}
    //
    // Lets a pending account sign in, and tells its owner by email.
    public function approve($id = null)
    {
        $this->requireAdmin();

        Api_input::requireId($id, 'admin');

        $existing = $this->Admins_Model->find($id);
        if (!$existing) {
            return Api_response::fail(404, 'Admin not found.');
        }
        if ($existing['status'] === 'approved') {
            return Api_response::ok(array('admin' => $existing));
        }

        $admin = $this->Admins_Model->approve($id);
        $this->sendMail($admin['email'], Account_mail::accountApproved($admin['name']));
        return Api_response::ok(array('admin' => $admin));
    }

    // DELETE /Admins_API/delete/{id}
    // Also how a pending registration is rejected.
    public function delete($id = null)
    {
        $this->requireAdmin();

        Api_input::requireId($id, 'admin');

        // An admin deleting their own account could leave none at all.
        $current = $this->getCurrentAdmin();
        if ($current !== null && (string) $current['id'] === (string) $id) {
            return Api_response::fail(400, 'You cannot delete your own account.');
        }

        $this->Admins_Model->delete($id);
        return Api_response::ok();
    }
}
