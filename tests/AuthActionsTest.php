<?php
// Pins Auth_API's guards: login's required fields, uniform refusal and the
// pending-account block, registration, the emailed password reset, /me for
// anonymous and signed-in callers, and logout's token handling.
class AuthActionsTest extends ActionTestCase
{
    private function auth(array $returns = array())
    {
        return array('Auth_Model' => $returns);
    }

    /** @dataProvider replies */
    public function testReplies($action, array $body, array $returns, $status, $error)
    {
        $this->assertReply($this->call('AuthApiHarness', $action, array(), $body, $returns), $status, $error);
    }

    public function replies()
    {
        return array(
            'login: empty body' => array('login', array(), $this->auth(), 400, 'Email and password are required.'),
            'login: no password' => array('login', array('email' => 'a@b.c'), $this->auth(), 400, 'Email and password are required.'),
            'login: wrong credentials' => array('login', array('email' => 'a@b.c', 'password' => 'x'), $this->auth(array('verifyCredentials' => false)), 401, 'Invalid email or password.'),
            'login: valid' => array('login', array('email' => 'a@b.c', 'password' => 'x'),
                $this->auth(array('verifyCredentials' => array('id' => 1, 'email' => 'a@b.c', 'name' => 'A', 'status' => 'approved'), 'createToken' => 't')), 200, null),
        );
    }

    public function testLoginLooksTheEmailUpTrimmedAndLowercased()
    {
        $this->call('AuthApiHarness', 'login', array(), array('email' => ' A@SDCA.EDU.PH ', 'password' => 'x'), $this->auth(array('verifyCredentials' => false)));

        $this->assertSame(array('verifyCredentials', array('a@sdca.edu.ph', 'x')), $this->controller->Auth_Model->calls[0]);
    }

    public function testLoginRefusesAPendingAccountEvenWithTheRightPassword()
    {
        $r = $this->call('AuthApiHarness', 'login', array(), array('email' => 'a@b.c', 'password' => 'x'),
            $this->auth(array('verifyCredentials' => array('id' => 1, 'email' => 'a@b.c', 'name' => 'A', 'status' => 'pending'))));

        $this->assertReply($r, 403, 'Your account is awaiting approval by an administrator.');
        $this->assertSame(array(array('verifyCredentials', array('a@b.c', 'x'))), $this->controller->Auth_Model->calls);
    }

    private function registerBody(array $over = array())
    {
        return array_merge(array('email' => ' Ana@SDCA.EDU.PH ', 'password' => 'longenough', 'name' => ' Ana '), $over);
    }

    private function admins(array $returns = array())
    {
        return array('Admins_Model' => $returns, 'Rate_limit_Model' => array('countSince' => 0));
    }

    /** @dataProvider registerReplies */
    public function testRegisterReplies(array $body, array $returns, $status, $error)
    {
        $this->assertReply($this->call('AuthApiHarness', 'register', array(), $body, $returns, null), $status, $error);
    }

    public function registerReplies()
    {
        return array(
            'empty body' => array(array(), $this->admins(), 400, 'Email, password, and name are all required.'),
            'wrong domain' => array($this->registerBody(array('email' => 'a@gmail.com')), $this->admins(), 403, 'Accounts are only open to @sdca.edu.ph email addresses.'),
            'short password' => array($this->registerBody(array('password' => '1234567')), $this->admins(), 400, 'Password must be at least 8 characters.'),
            'duplicate email' => array($this->registerBody(), $this->admins(array('emailExists' => true)), 409, 'An account with this email already exists.'),
            'valid' => array($this->registerBody(), $this->admins(array('emailExists' => false)), 200, null),
            'rate limited' => array($this->registerBody(), array('Admins_Model' => array(), 'Rate_limit_Model' => array('countSince' => Rate_limit::REGISTER_IP_MAX, 'oldestSince' => null)), 429, 'Too many registrations from this address. Try again in 3600 seconds.'),
        );
    }

    public function testRegisterCreatesAPendingAccountAndWelcomesThemWithoutSigningThemIn()
    {
        $r = $this->call('AuthApiHarness', 'register', array(), $this->registerBody(), $this->admins(array('emailExists' => false)), null);

        $create = $this->controller->Admins_Model->calls[1];
        $this->assertSame('create', $create[0]);
        $this->assertSame('ana@sdca.edu.ph', $create[1][0]);
        $this->assertTrue(password_verify('longenough', $create[1][1]));
        $this->assertSame('Ana', $create[1][2]);
        $this->assertSame('pending', $create[1][3]);
        $this->assertSame('ana@sdca.edu.ph', $this->controller->mails[0][0]);
        $this->assertArrayNotHasKey('token', $r->body());
        $this->assertSame(array(), $this->controller->Auth_Model->calls);
    }

    public function testForgotPasswordAnswersTheSameForAnUnknownEmailAndSendsNothing()
    {
        $r = $this->call('AuthApiHarness', 'forgotPassword', array(), array('email' => 'who@sdca.edu.ph'),
            array('Admins_Model' => array('findByEmail' => null), 'Rate_limit_Model' => array('countSince' => 0)), null);

        $this->assertReply($r, 200);
        $this->assertSame(array(), $this->controller->mails);
    }

    public function testForgotPasswordEmailsAnApprovedAccountALinkWithTheToken()
    {
        $this->call('AuthApiHarness', 'forgotPassword', array(), array('email' => 'Ana@sdca.edu.ph'), array(
            'Admins_Model' => array('findByEmail' => array('id' => 3, 'name' => 'Ana', 'status' => 'approved')),
            'Auth_Model' => array('createPasswordResetToken' => 'tok123'),
            'Rate_limit_Model' => array('countSince' => 0),
        ), null);

        $this->assertSame('ana@sdca.edu.ph', $this->controller->mails[0][0]);
        $this->assertStringContainsString('http://app.test/reset-password?token=tok123', $this->controller->mails[0][1]['html']);
    }

    public function testForgotPasswordSendsNothingToAPendingAccount()
    {
        $this->call('AuthApiHarness', 'forgotPassword', array(), array('email' => 'ana@sdca.edu.ph'), array(
            'Admins_Model' => array('findByEmail' => array('id' => 3, 'name' => 'Ana', 'status' => 'pending')),
            'Rate_limit_Model' => array('countSince' => 0),
        ), null);

        $this->assertSame(array(), $this->controller->mails);
    }

    public function testForgotPasswordIsRateLimitedPerEmail()
    {
        $r = $this->call('AuthApiHarness', 'forgotPassword', array(), array('email' => 'ana@sdca.edu.ph'),
            array('Admins_Model' => array(), 'Rate_limit_Model' => array('countSince' => 99, 'oldestSince' => null)), null);

        $this->assertReply($r, 429, 'Too many reset requests. Try again in 3600 seconds.');
        $this->assertSame(array(), $this->controller->mails);
    }

    /** @dataProvider resetReplies */
    public function testResetPasswordReplies(array $body, array $returns, $status, $error)
    {
        $this->assertReply($this->call('AuthApiHarness', 'resetPassword', array(), $body, $returns, null), $status, $error);
    }

    public function resetReplies()
    {
        return array(
            'no token' => array(array('password' => 'longenough'), array(), 400, 'Token and new password are required.'),
            'short password' => array(array('token' => 't', 'password' => '1234567'), array(), 400, 'Password must be at least 8 characters.'),
            'bad or expired token' => array(array('token' => 't', 'password' => 'longenough'), array('Auth_Model' => array('validatePasswordResetToken' => false)), 400, 'This reset link is invalid or has expired.'),
            'valid' => array(array('token' => 't', 'password' => 'longenough'), array('Auth_Model' => array('validatePasswordResetToken' => 7), 'Admins_Model' => array()), 200, null),
        );
    }

    public function testResetPasswordUsesTheTokenUpAndEndsEverySession()
    {
        $this->call('AuthApiHarness', 'resetPassword', array(), array('token' => 't', 'password' => 'longenough'),
            array('Auth_Model' => array('validatePasswordResetToken' => 7), 'Admins_Model' => array()), null);

        $this->assertSame(array('deletePasswordResetToken', array('t')), $this->controller->Auth_Model->calls[1]);
        $this->assertSame(array('deleteAllTokensForAdmin', array(7)), $this->controller->Auth_Model->calls[2]);
        $this->assertSame('updatePassword', $this->controller->Admins_Model->calls[0][0]);
    }

    public function testMeRefusesAnAnonymousCaller()
    {
        $this->assertReply($this->call('AuthApiHarness', 'me', array(), array(), array(), null), 401, 'Not signed in.');
    }

    public function testMeReturnsTheCurrentAdmin()
    {
        $r = $this->call('AuthApiHarness', 'me', array(), array(), array(), array('id' => 3, 'email' => 'a@b.c', 'name' => 'A', 'password_hash' => 'secret'));

        $this->assertReply($r, 200);
        $this->assertSame(array('id' => 3, 'email' => 'a@b.c', 'name' => 'A'), $r->body()['user']);
    }

    /** @dataProvider authorizationHeaders */
    public function testLogout($header, $status, $error)
    {
        $r = $this->call('AuthApiHarness', 'logout', array(), array(), array(), false, function ($c) use ($header) {
            $c->input = new FakeInput($header);
        });

        $this->assertReply($r, $status, $error);
    }

    public function authorizationHeaders()
    {
        return array(
            'no header' => array(null, 400, 'No token provided.'),
            'not a bearer token' => array('Basic abc', 400, 'No token provided.'),
            'bearer token' => array('Bearer tok123', 200, null),
        );
    }
}
