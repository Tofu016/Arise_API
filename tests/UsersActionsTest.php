<?php
// Pins Users_API's guards.
class UsersActionsTest extends ActionTestCase
{
    private function roles($valid)
    {
        return array('Users_Model' => array(
            'isValidRole' => $valid,
            'getAllowedRoles' => array('pending', 'user', 'admin'),
            'find' => array('role' => 'pending'),
            'updateRole' => array('email' => 'a@sdca.edu.ph', 'name' => 'Ana'),
        ));
    }

    /** @dataProvider replies */
    public function testReplies($action, array $params, array $body, array $returns, $status, $error)
    {
        $this->assertReply($this->call('UsersApiHarness', $action, $params, $body, $returns), $status, $error);
    }

    public function replies()
    {
        return array(
            'updateRole: no id' => array('updateRole', array(), array('role' => 'user'), $this->roles(true), 400, 'Missing user id.'),
            'updateRole: id "0" counts as missing' => array('updateRole', array('0'), array('role' => 'user'), $this->roles(true), 400, 'Missing user id.'),
            'updateRole: invalid role' => array('updateRole', array('2'), array('role' => 'root'), $this->roles(false), 400,
                'Invalid role. Must be one of: pending, user, admin'),
            'updateRole: unknown user' => array('updateRole', array('2'), array('role' => 'user'),
                array('Users_Model' => array('isValidRole' => true, 'find' => false)), 404, 'User not found.'),
            'updateRole: valid' => array('updateRole', array('2'), array('role' => 'user'), $this->roles(true), 200, null),

            'delete: no id' => array('delete', array(), array(), array(), 400, 'Missing user id.'),
            'delete: your own account' => array('delete', array('1'), array(), array(), 400, 'You cannot delete your own account.'),
            'delete: someone else' => array('delete', array('2'), array(), array(), 200, null),
        );
    }

    public function testApprovingAPendingAccountQueuesTheApprovalEmail()
    {
        $this->call('UsersApiHarness', 'updateRole', array('2'), array('role' => 'user'), $this->roles(true));

        $this->assertSame('enqueue', $this->controller->Email_Model->calls[0][0]);
        $this->assertSame('a@sdca.edu.ph', $this->controller->Email_Model->calls[0][1][0]);
    }
}
