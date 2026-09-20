<?php
// Pins the email each caller queues: recipient, subject and body, word
// for word (including how the recipient's name is escaped), and when none
// is queued at all. Written against the current code, where each body is
// built inline in the controller.
class EmailContentTest extends ActionTestCase
{
    // Every email an action queued, as array(to, subject, body).
    private function queued()
    {
        $emails = array();
        foreach ($this->controller->Auth_Model->calls as $call) {
            if ($call[0] === 'queueEmail') {
                $emails[] = $call[1];
            }
        }
        return $emails;
    }

    // ---- welcome email (registration) -----------------------------

    public function testRegistrationQueuesTheWelcomeEmail()
    {
        $name = "O'Neil & <Co>";
        $this->call('AuthApiHarness', 'register', array(), array('email' => ' Ana@SDCA.edu.ph ', 'password' => 'longenough', 'name' => " {$name} "), array(
            'Auth_Model' => array('emailExists' => false, 'register' => array('id' => 5, 'email' => 'x', 'name' => 'x', 'role' => 'pending'), 'createToken' => 't'),
        ));

        $this->assertSame(array(array(
            'ana@sdca.edu.ph',
            'Welcome to ARISE Campus Navigator',
            '<p>Hi ' . htmlspecialchars($name) . ',</p><p>Thanks for registering. Your account is currently pending approval — you\'ll receive another email once an administrator approves it.</p>',
        )), $this->queued());
    }

    public function testARefusedRegistrationQueuesNothing()
    {
        $this->call('AuthApiHarness', 'register', array(), array('email' => 'a@gmail.com', 'password' => 'longenough', 'name' => 'A'));

        $this->assertSame(array(), $this->queued());
    }

    // ---- password reset email -------------------------------------

    public function testPasswordResetQueuesTheResetLinkEmail()
    {
        $name = 'Ana <b>&';
        $this->call('AuthApiHarness', 'forgotPassword', array(), array('email' => ' Ana@SDCA.edu.ph '), array(
            'Auth_Model' => array('findByEmail' => array('id' => 5, 'name' => $name), 'createPasswordResetToken' => 'tok en+1'),
        ));

        // Today's link is hardcoded to the local dev server.
        $link = 'http://localhost:5173/reset-password?token=' . urlencode('tok en+1');
        $this->assertSame(array(array(
            'ana@sdca.edu.ph',
            'Reset your ARISE Campus Navigator password',
            '<p>Hi ' . htmlspecialchars($name) . ',</p>'
            . '<p>Click the link below to reset your password. This link expires in 1 hour and can only be used once.</p>'
            . '<p><a href="' . htmlspecialchars($link) . '">' . htmlspecialchars($link) . '</a></p>'
            . '<p>If you didn\'t request this, you can safely ignore this email.</p>',
        )), $this->queued());
    }

    public function testPasswordResetForAnUnknownAddressQueuesNothingButStillSucceeds()
    {
        $r = $this->call('AuthApiHarness', 'forgotPassword', array(), array('email' => 'nobody@sdca.edu.ph'), array('Auth_Model' => array('findByEmail' => null)));

        $this->assertReply($r, 200);
        $this->assertSame(array(), $this->queued());
    }

    // ---- account approved email -----------------------------------

    private function updateRole($existingRole, $newRole)
    {
        return $this->call('UsersApiHarness', 'updateRole', array('2'), array('role' => $newRole), array(
            'Users_Model' => array(
                'isValidRole' => true,
                'find' => array('role' => $existingRole),
                'updateRole' => array('email' => 'ana@sdca.edu.ph', 'name' => 'Ana <b>&'),
            ),
        ));
    }

    public function testApprovingAPendingAccountQueuesTheApprovalEmail()
    {
        $this->updateRole('pending', 'user');

        $this->assertSame(array(array(
            'ana@sdca.edu.ph',
            'Your ARISE Campus Navigator account has been approved',
            '<p>Hi ' . htmlspecialchars('Ana <b>&') . ',</p><p>Your account has been approved. You can now log in and start using ARISE Campus Navigator.</p>',
        )), $this->queued());
    }

    public function testChangingAnApprovedAccountsRoleQueuesNothing()
    {
        $this->updateRole('user', 'admin');

        $this->assertSame(array(), $this->queued());
    }

    public function testLeavingAnAccountPendingQueuesNothing()
    {
        $this->updateRole('pending', 'pending');

        $this->assertSame(array(), $this->queued());
    }
}
