<?php
// Models with their constructors (which load the real database) skipped,
// so a test can assign a FakeDb to ->db and call the methods directly.
class EmailModelHarness extends Email_Model
{
    public $db;

    public function __construct()
    {
    }
}

class AuthModelHarness extends Auth_Model
{
    public $db;

    public function __construct()
    {
    }
}

class UsersModelHarness extends Users_Model
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
