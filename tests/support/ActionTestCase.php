<?php
use PHPUnit\Framework\TestCase;

// Lets a test call a real controller action directly. CodeIgniter attaches
// models to the controller as plain properties, so a FakeModel can be
// assigned in their place; the constructor (which loads the real models)
// is skipped, and the request body and current user are supplied directly.
trait ControllerHarness
{
    public $body = array();
    public $user = null;
    public $input;

    public function __construct()
    {
    }

    protected function getInput()
    {
        return $this->body;
    }

    protected function getCurrentUser()
    {
        return $this->user;
    }
}

// One harness per controller under test, naming the models it uses.
class NodesApiHarness extends Nodes_API
{
    use ControllerHarness;
    public $modelNames = array('Nodes_Model', 'Buildings_Model');
}

class TourStopsApiHarness extends TourStops_API
{
    use ControllerHarness;
    public $modelNames = array('TourStops_Model');
}

class BuildingsApiHarness extends Buildings_API
{
    use ControllerHarness;
    public $modelNames = array('Buildings_Model');
}

class UsersApiHarness extends Users_API
{
    use ControllerHarness;
    public $modelNames = array('Users_Model', 'Auth_Model');
}

class TourSectionsApiHarness extends TourSections_API
{
    use ControllerHarness;
    public $modelNames = array('TourSections_Model');
}

class PlacardDialogsApiHarness extends PlacardDialogs_API
{
    use ControllerHarness;
    public $modelNames = array('PlacardDialogs_Model');
}

class AuthApiHarness extends Auth_API
{
    use ControllerHarness;
    public $modelNames = array('Auth_Model');
}

abstract class ActionTestCase extends TestCase
{
    // The controller the last call() ran, so a test can inspect what its
    // fake models were asked to do.
    protected $controller;

    // Runs $action on a fresh $harnessClass and returns the Api_response
    // it produced (a returned reply or a guard's abort).
    //   $returns — model name => method name => return value.
    //   $user    — the signed-in user row; defaults to an admin, null for
    //              an anonymous caller.
    //   $setup   — optional callable($controller) for anything else.
    protected function call($harnessClass, $action, array $params = array(), array $body = array(), array $returns = array(), $user = false, $setup = null)
    {
        $c = new $harnessClass();
        $c->body = $body;
        $c->user = $user === false ? array('id' => '1', 'role' => 'admin') : $user;
        foreach ($c->modelNames as $model) {
            $c->$model = new FakeModel(isset($returns[$model]) ? $returns[$model] : array());
        }
        if ($setup !== null) {
            $setup($c);
        }
        $this->controller = $c;

        return Api_response::run(function () use ($c, $action, $params) {
            return call_user_func_array(array($c, $action), $params);
        });
    }

    protected function assertReply($reply, $status, $error = null)
    {
        $this->assertInstanceOf(Api_response::class, $reply, 'The action returned no reply.');
        $this->assertSame($status, $reply->status());
        if ($error !== null) {
            $this->assertFalse($reply->body()['success']);
            $this->assertSame($error, $reply->body()['error']);
        } else {
            $this->assertTrue($reply->body()['success'], 'Expected success, got: ' . $reply->json());
        }
    }
}
