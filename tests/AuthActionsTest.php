<?php
// Pins Auth_API's guards, including the order registration checks run in
// (required fields, then domain, then password length, then duplicates)
// and the account policy as it stands today, gaps included.
class AuthActionsTest extends ActionTestCase
{
    const OK_PASSWORD = 'longenough';

    private function auth(array $returns = array())
    {
        return array('Auth_Model' => $returns);
    }

    private function registrationDone()
    {
        return $this->auth(array(
            'emailExists' => false,
            'register' => array('id' => 5, 'email' => 'a@sdca.edu.ph', 'name' => 'Ana', 'role' => 'pending'),
            'createToken' => 'tok',
        ));
    }

    /** @dataProvider replies */
    public function testReplies($action, array $body, array $returns, $status, $error)
    {
        $this->assertReply($this->call('AuthApiHarness', $action, array(), $body, $returns), $status, $error);
    }

    public function replies()
    {
        $tooShort = 'Password must be at least 8 characters.';
        $registerBody = array('email' => 'a@sdca.edu.ph', 'password' => self::OK_PASSWORD, 'name' => 'Ana');

        return array(
            // login
            'login: empty body' => array('login', array(), $this->auth(), 400, 'Email and password are required.'),
            'login: no password' => array('login', array('email' => 'a@b.c'), $this->auth(), 400, 'Email and password are required.'),
            'login: wrong credentials' => array('login', array('email' => 'a@b.c', 'password' => 'x'), $this->auth(array('verifyCredentials' => false)), 401, 'Invalid email or password.'),
            'login: valid' => array('login', array('email' => 'a@b.c', 'password' => 'x'),
                $this->auth(array('verifyCredentials' => array('id' => 1, 'email' => 'a@b.c', 'name' => 'A', 'role' => 'user'), 'createToken' => 't')), 200, null),

            // register: required -> domain (403) -> password -> duplicate (409)
            'register: empty body' => array('register', array(), $this->auth(), 400, 'Email, password, and name are all required.'),
            'register: no name' => array('register', array_diff_key($registerBody, array('name' => 1)), $this->auth(), 400, 'Email, password, and name are all required.'),
            'register: blank name' => array('register', array_merge($registerBody, array('name' => '  ')), $this->auth(), 400, 'Email, password, and name are all required.'),
            'register: wrong domain' => array('register', array_merge($registerBody, array('email' => 'a@gmail.com')), $this->auth(), 403, 'Registration is only open to @sdca.edu.ph email addresses.'),
            'register: domain is matched as a suffix, case-insensitively' => array('register', array_merge($registerBody, array('email' => 'A@SDCA.EDU.PH')), $this->registrationDone(), 200, null),
            'register: short password' => array('register', array_merge($registerBody, array('password' => '1234567')), $this->auth(array('emailExists' => false)), 400, $tooShort),
            'register: 8 characters is enough' => array('register', array_merge($registerBody, array('password' => '12345678')), $this->registrationDone(), 200, null),
            'register: wrong domain is reported before a short password' => array('register', array('email' => 'a@gmail.com', 'password' => '1', 'name' => 'A'), $this->auth(), 403, 'Registration is only open to @sdca.edu.ph email addresses.'),
            'register: short password is reported before a duplicate' => array('register', array_merge($registerBody, array('password' => '1')), $this->auth(array('emailExists' => true)), 400, $tooShort),
            'register: duplicate email' => array('register', $registerBody, $this->auth(array('emailExists' => true)), 409, 'An account with this email already exists.'),
            'register: valid' => array('register', $registerBody, $this->registrationDone(), 200, null),
            // Gaps in today's policy, pinned so any change is a decision:
            'register: nothing checks the email format' => array('register', array_merge($registerBody, array('email' => 'not an email@sdca.edu.ph')), $this->registrationDone(), 200, null),
            'register: password length counts bytes, not characters' => array('register', array_merge($registerBody, array('password' => 'ééééé')), $this->registrationDone(), 200, null),

            // forgotPassword
            'forgotPassword: empty body' => array('forgotPassword', array(), $this->auth(), 400, 'Email is required.'),
            'forgotPassword: unknown email still succeeds' => array('forgotPassword', array('email' => 'x@y.z'), $this->auth(array('findByEmail' => null)), 200, null),

            // resetPassword
            'resetPassword: empty body' => array('resetPassword', array(), $this->auth(), 400, 'Token and new password are required.'),
            'resetPassword: no password' => array('resetPassword', array('token' => 't'), $this->auth(), 400, 'Token and new password are required.'),
            'resetPassword: short password' => array('resetPassword', array('token' => 't', 'password' => '1234567'), $this->auth(array('validatePasswordResetToken' => 5)), 400, $tooShort),
            'resetPassword: short password is reported before a bad token' => array('resetPassword', array('token' => 't', 'password' => '1'), $this->auth(array('validatePasswordResetToken' => false)), 400, $tooShort),
            'resetPassword: bad token' => array('resetPassword', array('token' => 't', 'password' => self::OK_PASSWORD), $this->auth(array('validatePasswordResetToken' => false)), 400, 'This reset link is invalid or has expired.'),
            'resetPassword: valid' => array('resetPassword', array('token' => 't', 'password' => self::OK_PASSWORD), $this->auth(array('validatePasswordResetToken' => 5)), 200, null),
        );
    }

    public function testMeRefusesAnAnonymousCaller()
    {
        $this->assertReply($this->call('AuthApiHarness', 'me', array(), array(), array(), null), 401, 'Not signed in.');
    }

    public function testMeReturnsTheCurrentUser()
    {
        $r = $this->call('AuthApiHarness', 'me', array(), array(), array(), array('id' => 3, 'email' => 'a@b.c', 'name' => 'A', 'role' => 'user', 'password_hash' => 'secret'));

        $this->assertReply($r, 200);
        $this->assertSame(array('id' => 3, 'email' => 'a@b.c', 'name' => 'A', 'role' => 'user'), $r->body()['user']);
    }

    /** @dataProvider authorizationHeaders */
    public function testLogout($header, $status, $error)
    {
        $r = $this->call('AuthApiHarness', 'logout', array(), array(), array(), false, function ($c) use ($header) {
            $c->input = new class($header) {
                private $header;

                public function __construct($header)
                {
                    $this->header = $header;
                }

                public function get_request_header($name)
                {
                    return $this->header;
                }
            };
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

    public function testLogoutDeletesTheTokenItWasGiven()
    {
        $this->call('AuthApiHarness', 'logout', array(), array(), array(), false, function ($c) {
            $c->input = new class {
                public function get_request_header($name)
                {
                    return 'Bearer tok123 ';
                }
            };
        });

        $this->assertSame(array('deleteToken', array('tok123')), $this->controller->Auth_Model->calls[0]);
    }

    public function testRegistrationNormalisesTheEmailAndStoresAHashNotThePassword()
    {
        $this->call('AuthApiHarness', 'register', array(), array('email' => ' A@SDCA.EDU.PH ', 'password' => self::OK_PASSWORD, 'name' => ' Ana '), $this->registrationDone());

        $register = $this->controller->Auth_Model->calls[1];
        $this->assertSame('register', $register[0]);
        $this->assertSame('a@sdca.edu.ph', $register[1][0]);
        $this->assertNotSame(self::OK_PASSWORD, $register[1][1]);
        $this->assertTrue(password_verify(self::OK_PASSWORD, $register[1][1]));
        $this->assertSame('Ana', $register[1][2]);
    }

    public function testASuccessfulResetSetsTheHashAndEndsEverySession()
    {
        $this->call('AuthApiHarness', 'resetPassword', array(), array('token' => 't', 'password' => self::OK_PASSWORD), $this->auth(array('validatePasswordResetToken' => 5)));

        $names = array_map(function ($call) {
            return $call[0];
        }, $this->controller->Auth_Model->calls);
        $this->assertSame(array('validatePasswordResetToken', 'updatePassword', 'deletePasswordResetToken', 'deleteAllTokensForUser'), $names);
    }

    public function testARefusedRegistrationWritesNothing()
    {
        $this->call('AuthApiHarness', 'register', array(), array('email' => 'a@gmail.com', 'password' => 'x', 'name' => 'A'));

        $this->assertSame(array(), $this->controller->Auth_Model->calls);
    }
}
