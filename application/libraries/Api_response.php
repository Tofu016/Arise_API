<?php
defined('BASEPATH') or exit('No direct script access allowed');

// How a JSON reply leaves the app. An action builds a reply as a plain
// value and returns it; MY_Controller::_remap is the one place that turns
// it into a status code, a Content-Type and a body. Guards that must stop
// an action (401/403) throw an Api_abort carrying the reply instead of
// calling exit(), so they can be tested and can't skip the sending code.
//
// Every reply has one of two shapes, and the frontend depends on them:
//   success  { "success": true, <data keys...> }        status 200
//   failure  { "success": false, "error": "message" }   status 4xx
//
// Free of CodeIgniter dependencies, like Photo_store.
class Api_response
{
    private $status;
    private $body;

    private function __construct($status, array $body)
    {
        $this->status = $status;
        $this->body = $body;
    }

    // $data keys are added after "success", which can't be overridden:
    //   Api_response::ok(array('node' => $node))
    public static function ok(array $data = array())
    {
        return new self(200, array('success' => true) + $data);
    }

    public static function fail($status, $error)
    {
        return new self((int) $status, array('success' => false, 'error' => $error));
    }

    public function status()
    {
        return $this->status;
    }

    public function body()
    {
        return $this->body;
    }

    public function json()
    {
        return json_encode($this->body);
    }

    // Sends the reply. Headers are skipped if output has already started
    // (a stray notice, say) rather than raising a second warning on top.
    public function emit()
    {
        if (!headers_sent()) {
            http_response_code($this->status);
            header('Content-Type: application/json');
        }
        echo $this->json();
    }

    // Runs an action and returns the reply to send:
    //   - an Api_response the action returned, or
    //   - the reply of an Api_abort it (or a guard) threw, or
    //   - null when the action returned nothing, meaning it already
    //     echoed its own reply the old way and there is nothing to send.
    public static function run($action)
    {
        try {
            $result = call_user_func($action);
        } catch (Api_abort $abort) {
            return $abort->response();
        }
        return $result instanceof self ? $result : null;
    }

    // Whether $method may be reached from a URL. Once a controller defines
    // _remap, CodeIgniter stops applying its own check of this, so it is
    // reproduced here: only public, non-static methods declared by the
    // concrete controller — never inherited from $baseClasses (the shared
    // bases and CI itself), never a constructor, never starting with "_".
    public static function isAction($controller, $method, array $baseClasses)
    {
        if (!is_string($method) || $method === '' || $method[0] === '_') {
            return false;
        }
        try {
            $ref = new ReflectionMethod($controller, $method);
        } catch (ReflectionException $e) {
            return false;
        }
        return $ref->isPublic()
            && !$ref->isStatic()
            && !$ref->isConstructor()
            && !in_array($ref->getDeclaringClass()->getName(), $baseClasses, true);
    }
}

// Thrown to stop an action immediately and send $response instead.
class Api_abort extends Exception
{
    private $response;

    public function __construct(Api_response $response)
    {
        parent::__construct('API abort: HTTP ' . $response->status());
        $this->response = $response;
    }

    public function response()
    {
        return $this->response;
    }
}
