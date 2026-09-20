<?php
use PHPUnit\Framework\TestCase;

// The account roles exist in three places: the users.role enum in
// schema.sql (the source of truth), the list Users_Model validates against,
// and the literals the code relies on ("admin" for the admin guard,
// "pending" for a new account). These tests fail if they drift apart.
class RolesSchemaTest extends TestCase
{
    // [values in order, default] of the users.role enum, from schema.sql.
    private function schemaRole()
    {
        $inUsers = false;
        foreach (file(__DIR__ . '/../schema.sql') as $line) {
            if (preg_match('/^CREATE TABLE `users`/', $line)) {
                $inUsers = true;
            } elseif ($inUsers && preg_match("/^\s*`role`\s+enum\(([^)]*)\)[^,]*DEFAULT '([^']*)'/", $line, $m)) {
                preg_match_all("/'([^']*)'/", $m[1], $values);
                return array($values[1], $m[2]);
            }
        }
        $this->fail('users.role enum not found in schema.sql');
    }

    public function testTheSchemaParserFindsTheRoleEnum()
    {
        list($values, $default) = $this->schemaRole();

        $this->assertContains('admin', $values);
        $this->assertSame('pending', $default);
    }

    public function testTheRolesUsersModelAcceptsAreExactlyTheEnumInOrder()
    {
        list($values) = $this->schemaRole();

        $this->assertSame($values, (new UsersModelHarness())->getAllowedRoles());
    }

    public function testEveryEnumValueIsAValidRoleAndNothingElseIs()
    {
        list($values) = $this->schemaRole();
        $model = new UsersModelHarness();

        foreach ($values as $role) {
            $this->assertTrue($model->isValidRole($role), $role);
        }
        foreach (array('root', 'superadmin', 'Admin', '', null, 0, false) as $role) {
            $this->assertFalse($model->isValidRole($role), var_export($role, true));
        }
    }

    public function testTheAdminGuardReliesOnARoleThatExists()
    {
        list($values) = $this->schemaRole();

        // MY_Controller::requireAdmin passes only role "admin" (pinned in
        // AuthChainTest); that role must exist in the enum.
        $this->assertContains('admin', $values);
    }

    public function testANewAccountStartsWithTheSchemasDefaultRole()
    {
        list(, $default) = $this->schemaRole();
        $model = new AuthModelHarness();
        $model->db = new FakeDb();

        $model->register('a@sdca.edu.ph', 'hash', 'Ana');

        $insert = $model->db->log[0];
        $this->assertSame('insert', $insert[0]);
        $this->assertSame('users', $insert[1]);
        $this->assertSame($default, $insert[2]['role']);
    }
}
