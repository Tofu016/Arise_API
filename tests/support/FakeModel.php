<?php
// Stands in for a CodeIgniter model on a controller. Every method call is
// recorded, and returns whatever $returns says for that method name (null
// when unspecified) — enough to drive an action past its guards and see
// what it asked the model to do.
class FakeModel
{
    public $returns;
    public $calls = array();

    public function __construct(array $returns = array())
    {
        $this->returns = $returns;
    }

    public function __call($method, $args)
    {
        $this->calls[] = array($method, $args);
        return array_key_exists($method, $this->returns) ? $this->returns[$method] : null;
    }
}
