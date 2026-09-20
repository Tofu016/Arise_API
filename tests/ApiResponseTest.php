<?php
use PHPUnit\Framework\TestCase;

// A controller for exercising MY_Controller's real guard and dispatch
// code: its constructor is skipped (it needs CodeIgniter) and the current
// user is supplied directly.
class ApiTestController extends MY_Controller
{
    public $user = null;

    public function __construct()
    {
    }

    protected function getCurrentUser()
    {
        return $this->user;
    }

    public function adminOnly()
    {
        $this->requireAdmin();
        return Api_response::ok(array('secret' => 1));
    }

    public function withParam($id)
    {
        return Api_response::ok(array('id' => $id));
    }

    public function conflict()
    {
        return Api_response::fail(409, 'Already there.');
    }

    // Not yet migrated: echoes its own reply and returns nothing.
    public function legacy()
    {
        echo '{"success":true,"old":"style"}';
    }

    protected function protectedHelper()
    {
    }

    private function privateHelper()
    {
    }

    public static function staticThing()
    {
    }

    public function _underscored()
    {
    }
}

class ApiResponseTest extends TestCase
{
    const BASES = array('MY_Controller', 'CI_Controller');

    // ---- the reply value ------------------------------------------

    public function testOkIsStatus200WithSuccessFirstThenDataKeys()
    {
        $r = Api_response::ok(array('node' => array('id' => 1)));

        $this->assertSame(200, $r->status());
        $this->assertSame('{"success":true,"node":{"id":1}}', $r->json());
    }

    public function testOkWithNoDataIsJustSuccess()
    {
        $this->assertSame('{"success":true}', Api_response::ok()->json());
    }

    public function testDataCannotOverrideSuccess()
    {
        $r = Api_response::ok(array('success' => false));

        $this->assertTrue($r->body()['success']);
    }

    public function testFailCarriesItsStatusAndTheErrorShape()
    {
        $r = Api_response::fail(409, 'Already there.');

        $this->assertSame(409, $r->status());
        $this->assertSame('{"success":false,"error":"Already there."}', $r->json());
    }

    public function testEmitWritesTheBody()
    {
        ob_start();
        Api_response::fail(400, 'Nope.')->emit();

        $this->assertSame('{"success":false,"error":"Nope."}', ob_get_clean());
    }

    // ---- running an action ----------------------------------------

    public function testRunReturnsTheReplyAnActionReturned()
    {
        $r = Api_response::run(function () {
            return Api_response::ok(array('a' => 1));
        });

        $this->assertSame(200, $r->status());
    }

    public function testRunReturnsTheReplyOfAnAbort()
    {
        $r = Api_response::run(function () {
            throw new Api_abort(Api_response::fail(401, 'Not signed in.'));
        });

        $this->assertSame(401, $r->status());
    }

    public function testRunReturnsNullWhenTheActionReturnedNothing()
    {
        $this->assertNull(Api_response::run(function () {
        }));
    }

    public function testRunIgnoresReturnValuesThatAreNotReplies()
    {
        $this->assertNull(Api_response::run(function () {
            return array('success' => true);
        }));
    }

    public function testRunDoesNotSwallowOtherExceptions()
    {
        $this->expectException(LogicException::class);

        Api_response::run(function () {
            throw new LogicException('boom');
        });
    }

    // ---- which methods a URL can reach ----------------------------

    /** @dataProvider reachable */
    public function testIsActionAcceptsPublicMethodsOfTheConcreteController($method)
    {
        $this->assertTrue(Api_response::isAction(new ApiTestController(), $method, self::BASES));
    }

    public function reachable()
    {
        return array(array('adminOnly'), array('withParam'), array('legacy'), array('conflict'));
    }

    /** @dataProvider unreachable */
    public function testIsActionRefusesEverythingElse($method)
    {
        $this->assertFalse(Api_response::isAction(new ApiTestController(), $method, self::BASES));
    }

    public function unreachable()
    {
        return array(
            'protected helper' => array('protectedHelper'),
            'private helper' => array('privateHelper'),
            'protected inherited from base' => array('getInput'),
            'public inherited from base' => array('_remap'),
            'public inherited from CI' => array('ciInherited'),
            'constructor' => array('__construct'),
            'underscored' => array('_underscored'),
            'static' => array('staticThing'),
            'does not exist' => array('nope'),
            'empty' => array(''),
            'not a string' => array(null),
        );
    }

    // ---- guards ---------------------------------------------------

    private function replyOf(ApiTestController $c, $action)
    {
        return Api_response::run(function () use ($c, $action) {
            return $c->$action();
        });
    }

    public function testAdminOnlyRefusesAnonymousWith401()
    {
        $r = $this->replyOf(new ApiTestController(), 'adminOnly');

        $this->assertSame(401, $r->status());
        $this->assertSame('Not signed in.', $r->body()['error']);
    }

    public function testAdminOnlyRefusesANonAdminWith403()
    {
        $c = new ApiTestController();
        $c->user = array('role' => 'user');

        $r = $this->replyOf($c, 'adminOnly');

        $this->assertSame(403, $r->status());
        $this->assertSame('Admin access required.', $r->body()['error']);
    }

    public function testAdminOnlyLetsAnAdminThrough()
    {
        $c = new ApiTestController();
        $c->user = array('role' => 'admin');

        $r = $this->replyOf($c, 'adminOnly');

        $this->assertSame(200, $r->status());
        $this->assertSame(1, $r->body()['secret']);
    }

    // ---- _remap: the whole path a request takes -------------------

    public function testRemapSendsAGuardsAbortAsJson()
    {
        ob_start();
        (new ApiTestController())->_remap('adminOnly');

        $this->assertSame('{"success":false,"error":"Not signed in."}', ob_get_clean());
    }

    public function testRemapSendsAReturnedReplyAndPassesUrlParams()
    {
        ob_start();
        (new ApiTestController())->_remap('withParam', array('42'));

        $this->assertSame('{"success":true,"id":"42"}', ob_get_clean());
    }

    public function testRemapSendsNothingExtraForALegacyAction()
    {
        ob_start();
        (new ApiTestController())->_remap('legacy');

        $this->assertSame('{"success":true,"old":"style"}', ob_get_clean());
    }

    public function testRemapShows404ForAProtectedHelper()
    {
        $this->expectException(Show404Called::class);

        (new ApiTestController())->_remap('protectedHelper');
    }

    public function testRemapShows404ForAnUnknownMethod()
    {
        $this->expectException(Show404Called::class);

        (new ApiTestController())->_remap('doesNotExist');
    }
}
