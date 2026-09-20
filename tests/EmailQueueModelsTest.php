<?php
use PHPUnit\Framework\TestCase;

// Pins the email_queue queries as they stand: what is written when an
// email is queued, and what the sender reads and updates.
class EmailQueueModelsTest extends TestCase
{
    private function model($class, array $tables = array())
    {
        $model = new $class();
        $model->db = new FakeDb();
        $model->db->tables = $tables;
        return $model;
    }

    public function testQueueEmailInsertsOneRowWithNoSentTime()
    {
        $model = $this->model('AuthModelHarness');

        $model->queueEmail('a@sdca.edu.ph', 'Hello', '<p>Hi</p>');

        $this->assertSame(array(
            array('insert', 'email_queue', array('to_email' => 'a@sdca.edu.ph', 'subject' => 'Hello', 'body_html' => '<p>Hi</p>')),
        ), $model->db->log);
    }

    public function testGetPendingReadsUnsentRowsOldestFirstWithABatchLimit()
    {
        $model = $this->model('EmailModelHarness');

        $model->getPending();

        $this->assertSame(array(
            array('select', '*'),
            array('from', 'email_queue'),
            array('where', 'sent_at', null),
            array('order_by', 'created_at', 'ASC'),
            array('limit', 20),
            array('get', 'email_queue'),
        ), $model->db->log);
    }

    public function testGetPendingBatchSizeIsAdjustable()
    {
        $model = $this->model('EmailModelHarness');

        $model->getPending(5);

        $this->assertContains(array('limit', 5), $model->db->log);
    }

    public function testGetPendingReturnsOnlyUnsentRows()
    {
        $model = $this->model('EmailModelHarness', array('email_queue' => array(
            array('id' => 1, 'sent_at' => '2026-09-01 10:00:00'),
            array('id' => 2, 'sent_at' => null),
        )));

        $this->assertSame(array(2), array_column($model->getPending(), 'id'));
    }

    public function testMarkSentStampsTheRowWithTheCurrentTime()
    {
        $model = $this->model('EmailModelHarness');

        $model->markSent(7);

        $this->assertSame(array('where', 'id', 7), $model->db->log[0]);
        $this->assertSame('update', $model->db->log[1][0]);
        $this->assertSame('email_queue', $model->db->log[1][1]);
        $this->assertSame(array('sent_at'), array_keys($model->db->log[1][2]));
        $this->assertMatchesRegularExpression('/^\d{4}-\d\d-\d\d \d\d:\d\d:\d\d$/', $model->db->log[1][2]['sent_at']);
    }
}
