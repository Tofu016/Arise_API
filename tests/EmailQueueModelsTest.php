<?php
use PHPUnit\Framework\TestCase;

// Pins the email_queue queries as they stand, seen through the model's
// interface: what deliverPending reads to find waiting emails, and what
// it writes when one is sent.
class EmailQueueModelsTest extends TestCase
{
    private function model(array $rows = array())
    {
        $model = new EmailModelHarness();
        $model->db = new FakeDb();
        $model->db->tables = array('email_queue' => $rows);
        return $model;
    }

    public function testItReadsUnsentRowsOldestFirstWithABatchLimit()
    {
        $model = $this->model();

        $model->deliverPending(new FakeMailer());

        $this->assertSame(array(
            array('select', '*'),
            array('from', 'email_queue'),
            array('where', 'sent_at', null),
            array('order_by', 'created_at', 'ASC'),
            array('limit', 20),
            array('get', 'email_queue'),
        ), $model->db->log);
    }

    public function testItSendsOnlyRowsThatAreStillUnsent()
    {
        $model = $this->model(array(
            array('id' => 1, 'to_email' => 'done@x.ph', 'subject' => 's', 'body_html' => 'b', 'sent_at' => '2026-09-01 10:00:00'),
            array('id' => 2, 'to_email' => 'wait@x.ph', 'subject' => 's', 'body_html' => 'b', 'sent_at' => null),
        ));
        $mailer = new FakeMailer();

        $model->deliverPending($mailer);

        $this->assertSame(array('wait@x.ph'), array_column($mailer->sent, 0));
    }

    public function testASentRowIsStampedWithTheCurrentTime()
    {
        $model = $this->model(array(
            array('id' => 7, 'to_email' => 'wait@x.ph', 'subject' => 's', 'body_html' => 'b', 'sent_at' => null),
        ));

        $model->deliverPending(new FakeMailer());

        $writes = array_values(array_filter($model->db->log, function ($entry) {
            return in_array($entry[0], array('update', 'insert', 'delete'), true);
        }));
        $this->assertCount(1, $writes);
        $this->assertSame('update', $writes[0][0]);
        $this->assertSame('email_queue', $writes[0][1]);
        $this->assertSame(array('sent_at'), array_keys($writes[0][2]));
        $this->assertMatchesRegularExpression('/^\d{4}-\d\d-\d\d \d\d:\d\d:\d\d$/', $writes[0][2]['sent_at']);
        $this->assertContains(array('where', 'id', 7), $model->db->log);
    }

    public function testTheQueueIsNotWrittenToByARunThatSendsNothing()
    {
        $model = $this->model();

        $model->deliverPending(new FakeMailer());

        $this->assertSame(array(), array_filter($model->db->log, function ($entry) {
            return in_array($entry[0], array('update', 'insert', 'delete'), true);
        }));
    }
}
