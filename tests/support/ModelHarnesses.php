<?php
// Models with their constructors (which load the real database) skipped,
// so a test can assign a FakeDb to ->db and call the methods directly.
class AuthModelHarness extends Auth_Model
{
    public $db;

    public function __construct()
    {
    }
}

class AdminsModelHarness extends Admins_Model
{
    public $db;

    public function __construct()
    {
    }
}

class ElevatorsModelHarness extends Elevators_Model
{
    public $db;

    public function __construct()
    {
    }
}
