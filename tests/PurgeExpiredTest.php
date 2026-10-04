<?php
use PHPUnit\Framework\TestCase;

// Pins the housekeeping purge: what it deletes, and above all what it
// leaves alone.
class PurgeExpiredTest extends TestCase
{
    private function ago($interval)
    {
        return date('Y-m-d H:i:s', strtotime($interval));
    }

    private function authModel(array $rows)
    {
        $model = new AuthModelHarness();
        $model->db = new FakeDb();
        $model->db->tables = array('auth_tokens' => $rows);
        return $model;
    }

    public function testExpiredLoginTokensAreDeletedAndLiveOnesKept()
    {
        $model = $this->authModel(array(
            array('id' => 1, 'expires_at' => $this->ago('-1 hour')),
            array('id' => 2, 'expires_at' => $this->ago('-2 days')),
            array('id' => 3, 'expires_at' => $this->ago('+1 hour')),
        ));

        $this->assertSame(2, $model->purgeExpiredTokens());
        $this->assertSame(array(3), array_column($model->db->tables['auth_tokens'], 'id'));
    }

    public function testPurgingWithNothingExpiredDeletesNothing()
    {
        $model = $this->authModel(array(array('id' => 1, 'expires_at' => $this->ago('+1 hour'))));

        $this->assertSame(0, $model->purgeExpiredTokens());
        $this->assertCount(1, $model->db->tables['auth_tokens']);
    }
}
