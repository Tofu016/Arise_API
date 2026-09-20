<?php
use PHPUnit\Framework\TestCase;

// Supplies the Authorization header the way CodeIgniter's input class does.
class FakeInput
{
    public $header;

    public function __construct($header = null)
    {
        $this->header = $header;
    }

    public function get_request_header($name)
    {
        return $this->header;
    }
}

// A controller that runs MY_Controller's REAL getCurrentUser (unlike the
// action harness, which stubs it): the header comes from FakeInput and the
// token lookup from a FakeModel's validateToken.
class AuthGuardHarness extends MY_Controller
{
    public $Auth_Model;

    public function __construct()
    {
    }

    public function adminOnly()
    {
        $this->requireAdmin();
        return Api_response::ok();
    }

    public function whoAmI()
    {
        return Api_response::ok(array('user' => $this->getCurrentUser()));
    }

    // Two guards in one request, to see how often the token is looked up.
    public function twoGuards()
    {
        $this->requireAdmin();
        $this->requireAdmin();
        return Api_response::ok();
    }
}

// Pins how a request becomes a user and a permission: the Authorization
// header, the token lookup, the role check. Every one of the ~45 admin
// endpoints depends on this chain.
class AuthChainTest extends TestCase
{
    private $controller;

    private function request($header, $action, $validateTokenReturns = false)
    {
        $c = new AuthGuardHarness();
        $c->input = new FakeInput($header);
        $c->Auth_Model = new FakeModel(array('validateToken' => $validateTokenReturns));
        $this->controller = $c;

        return Api_response::run(function () use ($c, $action) {
            return $c->$action();
        });
    }

    private function lookups()
    {
        return array_map(function ($call) {
            return $call[1][0];
        }, $this->controller->Auth_Model->calls);
    }

    private function user($role)
    {
        return array('id' => 3, 'email' => 'a@sdca.edu.ph', 'name' => 'Ana', 'role' => $role, 'password_hash' => 'x');
    }

    // ---- roles -----------------------------------------------------

    public function testAnAdminTokenPassesTheAdminGuard()
    {
        $r = $this->request('Bearer goodtoken', 'adminOnly', $this->user('admin'));

        $this->assertSame(200, $r->status());
        $this->assertSame(array('goodtoken'), $this->lookups());
    }

    /** @dataProvider nonAdminRoles */
    public function testAnyNonAdminRoleIsRefusedWith403($role)
    {
        $r = $this->request('Bearer goodtoken', 'adminOnly', $this->user($role));

        $this->assertSame(403, $r->status());
        $this->assertSame('Admin access required.', $r->body()['error']);
    }

    public function nonAdminRoles()
    {
        return array('user' => array('user'), 'pending' => array('pending'), 'unknown' => array('superadmin'), 'wrong case' => array('Admin'));
    }

    public function testATokenThatDoesNotValidateIsAnonymous()
    {
        $r = $this->request('Bearer badtoken', 'adminOnly', false);

        $this->assertSame(401, $r->status());
        $this->assertSame('Not signed in.', $r->body()['error']);
        $this->assertSame(array('badtoken'), $this->lookups());
    }

    /** @dataProvider emptyLookupResults */
    public function testAnyEmptyLookupResultCountsAsAnonymous($result)
    {
        $this->assertSame(401, $this->request('Bearer t', 'adminOnly', $result)->status());
    }

    public function emptyLookupResults()
    {
        return array('false' => array(false), 'null' => array(null), 'empty array' => array(array()));
    }

    public function testTheUserRowTheLookupReturnedIsTheCurrentUser()
    {
        $row = $this->user('user');

        $r = $this->request('Bearer t', 'whoAmI', $row);

        $this->assertSame($row, $r->body()['user']);
    }

    // ---- the Authorization header ---------------------------------

    /** @dataProvider headersThatAreNotBearerTokens */
    public function testAHeaderThatIsNotABearerTokenIsAnonymousAndNeverLooksAnythingUp($header)
    {
        $r = $this->request($header, 'adminOnly', $this->user('admin'));

        $this->assertSame(401, $r->status());
        $this->assertSame(array(), $this->lookups());
    }

    public function headersThatAreNotBearerTokens()
    {
        return array(
            'no header' => array(null),
            'empty header' => array(''),
            'basic scheme' => array('Basic abc'),
            'token with no scheme' => array('goodtoken'),
            'scheme with no space' => array('Bearer'),
            'scheme not at the start' => array('Token Bearer abc'),
        );
    }

    /** @dataProvider bearerHeaders */
    public function testTheTokenIsWhatFollowsTheSchemeTrimmed($header, $token)
    {
        $this->request($header, 'adminOnly', $this->user('admin'));

        $this->assertSame(array($token), $this->lookups());
    }

    public function bearerHeaders()
    {
        return array(
            'plain' => array('Bearer abc123', 'abc123'),
            'lowercase scheme' => array('bearer abc123', 'abc123'),
            'uppercase scheme' => array('BEARER abc123', 'abc123'),
            'extra spaces around' => array('Bearer    abc123   ', 'abc123'),
            'token containing a space is kept whole' => array('Bearer abc 123', 'abc 123'),
        );
    }

    public function testABlankTokenIsStillLookedUpAndFailsThere()
    {
        // "Bearer " passes the scheme check; the empty token is handed to
        // the lookup, which refuses it.
        $r = $this->request('Bearer    ', 'adminOnly', false);

        $this->assertSame(401, $r->status());
        $this->assertSame(array(''), $this->lookups());
    }

    // ---- once per request -----------------------------------------

    public function testTheTokenIsLookedUpOnceHoweverManyGuardsAsk()
    {
        $this->request('Bearer t', 'twoGuards', $this->user('admin'));

        $this->assertSame(array('t'), $this->lookups());
    }

    public function testAnAnonymousResultIsAlsoRememberedForTheRequest()
    {
        $c = new AuthGuardHarness();
        $c->input = new FakeInput('Bearer t');
        $c->Auth_Model = new FakeModel(array('validateToken' => false));

        Api_response::run(function () use ($c) {
            $c->whoAmI();
            $c->whoAmI();
        });

        $this->assertCount(1, $c->Auth_Model->calls);
    }
}
