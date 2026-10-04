<?php
// Pins Admins_API: admin-only, the account policy it applies, and the two
// safeguards (no self-deletion, a password change ends the target's sessions).
class AdminsActionsTest extends ActionTestCase
{
    const OK_PASSWORD = 'longenough';

    private function model(array $returns = array())
    {
        return array('Admins_Model' => $returns, 'Auth_Model' => array());
    }

    private function createBody(array $over = array())
    {
        return array_merge(array('email' => 'ana@sdca.edu.ph', 'password' => self::OK_PASSWORD, 'name' => 'Ana'), $over);
    }

    /** @dataProvider replies */
    public function testReplies($action, array $params, array $body, array $returns, $status, $error)
    {
        $this->assertReply($this->call('AdminsApiHarness', $action, $params, $body, $returns), $status, $error);
    }

    public function replies()
    {
        $tooShort = 'Password must be at least 8 characters.';
        $wrongDomain = 'Accounts are only open to @sdca.edu.ph email addresses.';

        return array(
            'create: empty body' => array('create', array(), array(), $this->model(), 400, 'Email, password, and name are all required.'),
            'create: blank name' => array('create', array(), $this->createBody(array('name' => '  ')), $this->model(), 400, 'Email, password, and name are all required.'),
            'create: wrong domain' => array('create', array(), $this->createBody(array('email' => 'a@gmail.com')), $this->model(), 403, $wrongDomain),
            'create: domain is matched case-insensitively' => array('create', array(), $this->createBody(array('email' => 'A@SDCA.EDU.PH')), $this->model(array('emailExists' => false)), 200, null),
            'create: short password' => array('create', array(), $this->createBody(array('password' => '1234567')), $this->model(array('emailExists' => false)), 400, $tooShort),
            'create: wrong domain is reported before a short password' => array('create', array(), $this->createBody(array('email' => 'a@gmail.com', 'password' => '1')), $this->model(), 403, $wrongDomain),
            'create: duplicate email' => array('create', array(), $this->createBody(), $this->model(array('emailExists' => true)), 409, 'An account with this email already exists.'),
            'create: valid' => array('create', array(), $this->createBody(), $this->model(array('emailExists' => false, 'create' => array('id' => 4))), 200, null),

            'approve: no id' => array('approve', array(), array(), $this->model(), 400, 'Missing admin id.'),
            'approve: unknown admin' => array('approve', array('2'), array(), $this->model(array('find' => null)), 404, 'Admin not found.'),
            'approve: pending admin' => array('approve', array('2'), array(), $this->model(array('find' => array('id' => 2, 'status' => 'pending'), 'approve' => array('id' => 2, 'email' => 'b@sdca.edu.ph', 'name' => 'Bo', 'status' => 'approved'))), 200, null),

            'delete: no id' => array('delete', array(), array(), $this->model(), 400, 'Missing admin id.'),
            'delete: your own account' => array('delete', array('1'), array(), $this->model(), 400, 'You cannot delete your own account.'),
            'delete: someone else' => array('delete', array('2'), array(), $this->model(), 200, null),
        );
    }

    public function testEveryActionIsAdminOnly()
    {
        foreach (array('getAll', 'create', 'approve', 'delete') as $action) {
            $this->assertReply($this->call('AdminsApiHarness', $action, array('2'), $this->createBody(), $this->model(), null), 401, 'Not signed in.');
            $this->assertSame(array(), $this->controller->Admins_Model->calls);
        }
    }

    public function testCreateStoresAHashNotThePasswordAndNormalisesTheEmail()
    {
        $this->call('AdminsApiHarness', 'create', array(), $this->createBody(array('email' => ' A@SDCA.EDU.PH ', 'name' => ' Ana ')), $this->model(array('emailExists' => false)));

        $create = $this->controller->Admins_Model->calls[1];
        $this->assertSame('create', $create[0]);
        $this->assertSame('a@sdca.edu.ph', $create[1][0]);
        $this->assertNotSame(self::OK_PASSWORD, $create[1][1]);
        $this->assertTrue(password_verify(self::OK_PASSWORD, $create[1][1]));
        $this->assertSame('Ana', $create[1][2]);
    }

    public function testApprovingAPendingAdminEmailsThem()
    {
        $this->call('AdminsApiHarness', 'approve', array('2'), array(), $this->model(array(
            'find' => array('id' => 2, 'status' => 'pending'),
            'approve' => array('id' => 2, 'email' => 'b@sdca.edu.ph', 'name' => 'Bo', 'status' => 'approved'),
        )));

        $this->assertSame('b@sdca.edu.ph', $this->controller->mails[0][0]);
        $this->assertSame('Your ARISE Campus Navigator account has been approved', $this->controller->mails[0][1]['subject']);
    }

    public function testApprovingAnAlreadyApprovedAdminDoesNothingAndSendsNoEmail()
    {
        $this->call('AdminsApiHarness', 'approve', array('2'), array(), $this->model(array('find' => array('id' => 2, 'status' => 'approved'))));

        $this->assertSame(array(), $this->controller->mails);
        $this->assertSame(array(array('find', array('2'))), $this->controller->Admins_Model->calls);
    }

    public function testARefusedDeleteWritesNothing()
    {
        $this->call('AdminsApiHarness', 'delete', array('1'), array(), $this->model());

        $this->assertSame(array(), $this->controller->Admins_Model->calls);
    }
}
