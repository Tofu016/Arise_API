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

    private function authModel(array $tables)
    {
        $model = new AuthModelHarness();
        $model->db = new FakeDb();
        $model->db->tables = $tables;
        return $model;
    }

    public function testExpiredLoginAndResetTokensAreDeletedAndLiveOnesKept()
    {
        $model = $this->authModel(array(
            'auth_tokens' => array(
                array('id' => 1, 'expires_at' => $this->ago('-1 hour')),
                array('id' => 2, 'expires_at' => $this->ago('-2 days')),
                array('id' => 3, 'expires_at' => $this->ago('+1 hour')),
            ),
            'password_resets' => array(
                array('id' => 1, 'expires_at' => $this->ago('-5 minutes')),
                array('id' => 2, 'expires_at' => $this->ago('+30 minutes')),
            ),
        ));

        $purged = $model->purgeExpiredTokens();

        $this->assertSame(array('auth_tokens' => 2, 'password_resets' => 1), $purged);
        $this->assertSame(array(3), array_column($model->db->tables['auth_tokens'], 'id'));
        $this->assertSame(array(2), array_column($model->db->tables['password_resets'], 'id'));
    }

    public function testPurgingWithNothingExpiredDeletesNothing()
    {
        $model = $this->authModel(array(
            'auth_tokens' => array(array('id' => 1, 'expires_at' => $this->ago('+1 hour'))),
            'password_resets' => array(),
        ));

        $this->assertSame(array('auth_tokens' => 0, 'password_resets' => 0), $model->purgeExpiredTokens());
        $this->assertCount(1, $model->db->tables['auth_tokens']);
    }

    private function emailModel(array $rows)
    {
        $model = new EmailModelHarness();
        $model->db = new FakeDb();
        $model->db->tables = array('email_queue' => $rows);
        return $model;
    }

    public function testOnlyOldSentEmailsArePurged()
    {
        $model = $this->emailModel(array(
            array('id' => 1, 'sent_at' => $this->ago('-40 days')),
            array('id' => 2, 'sent_at' => $this->ago('-2 days')),
            array('id' => 3, 'sent_at' => null),
        ));

        $this->assertSame(1, $model->purgeSent(30));
        $this->assertSame(array(2, 3), array_column($model->db->tables['email_queue'], 'id'));
    }

    public function testAnUnsentEmailIsNeverPurgedHoweverOldOrOftenFailed()
    {
        $model = $this->emailModel(array(
            array('id' => 1, 'sent_at' => null, 'attempts' => 50, 'created_at' => $this->ago('-400 days')),
        ));

        $this->assertSame(0, $model->purgeSent(30));
        $this->assertCount(1, $model->db->tables['email_queue']);
    }
}
