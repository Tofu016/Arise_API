<?php
// Pins two things Auth_API does with a user row and a header:
//  - the user it sends back to the client is exactly id, email, name and
//    role — never the password hash — from login, me and register;
//  - logout reads the bearer token from the header the same way the
//    guards do.
class AuthPayloadAndLogoutTest extends ActionTestCase
{
    private function row()
    {
        return array('id' => 5, 'email' => 'ana@sdca.edu.ph', 'name' => 'Ana', 'role' => 'pending', 'password_hash' => '$2y$secret', 'created_at' => '2026-01-01 00:00:00', 'updated_at' => '2026-01-01 00:00:00');
    }

    private function expectedPayload()
    {
        return array('id' => 5, 'email' => 'ana@sdca.edu.ph', 'name' => 'Ana', 'role' => 'pending');
    }

    // ---- the user sent to the client ------------------------------

    public function testLoginSendsBackOnlyTheFourClientFields()
    {
        $r = $this->call('AuthApiHarness', 'login', array(), array('email' => 'ana@sdca.edu.ph', 'password' => 'x'), array(
            'Auth_Model' => array('verifyCredentials' => $this->row(), 'createToken' => 'tok'),
        ));

        $this->assertReply($r, 200);
        $this->assertSame($this->expectedPayload(), $r->body()['user']);
        $this->assertSame('tok', $r->body()['token']);
        $this->assertStringNotContainsString('secret', $r->json());
        $this->assertStringNotContainsString('password_hash', $r->json());
    }

    public function testRegisterSendsBackOnlyTheFourClientFields()
    {
        $r = $this->call('AuthApiHarness', 'register', array(), array('email' => 'ana@sdca.edu.ph', 'password' => 'longenough', 'name' => 'Ana'), array(
            'Auth_Model' => array('emailExists' => false, 'register' => $this->row(), 'createToken' => 'tok'),
        ));

        $this->assertReply($r, 200);
        $this->assertSame($this->expectedPayload(), $r->body()['user']);
        $this->assertStringNotContainsString('password_hash', $r->json());
    }

    public function testMeSendsBackOnlyTheFourClientFields()
    {
        $r = $this->call('AuthApiHarness', 'me', array(), array(), array(), $this->row());

        $this->assertReply($r, 200);
        $this->assertSame($this->expectedPayload(), $r->body()['user']);
        $this->assertStringNotContainsString('password_hash', $r->json());
    }

    // ---- logout reads the token like the guards do ----------------

    private function logout($header)
    {
        $r = $this->call('AuthApiHarness', 'logout', array(), array(), array(), false, function ($c) use ($header) {
            $c->input = new FakeInput($header);
        });
        return $r;
    }

    private function deletedToken()
    {
        $calls = $this->controller->Auth_Model->calls;
        return $calls ? $calls[0][1][0] : null;
    }

    /** @dataProvider bearerHeaders */
    public function testLogoutDeletesTheTrimmedTokenAfterTheScheme($header, $token)
    {
        $this->assertReply($this->logout($header), 200);

        $this->assertSame($token, $this->deletedToken());
    }

    public function bearerHeaders()
    {
        return array(
            'plain' => array('Bearer abc123', 'abc123'),
            'lowercase scheme' => array('bearer abc123', 'abc123'),
            'uppercase scheme' => array('BEARER abc123', 'abc123'),
            'extra spaces around' => array('Bearer    abc123   ', 'abc123'),
            'blank token is still passed on' => array('Bearer    ', ''),
        );
    }

    /** @dataProvider notBearerHeaders */
    public function testLogoutRefusesAnythingThatIsNotABearerHeaderAndDeletesNothing($header)
    {
        $this->assertReply($this->logout($header), 400, 'No token provided.');

        $this->assertSame(array(), $this->controller->Auth_Model->calls);
    }

    public function notBearerHeaders()
    {
        return array(
            'none' => array(null),
            'empty' => array(''),
            'basic' => array('Basic abc'),
            'no scheme' => array('abc123'),
            'scheme without a space' => array('Bearer'),
        );
    }
}
