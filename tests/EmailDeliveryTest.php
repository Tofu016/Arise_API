<?php
use PHPUnit\Framework\TestCase;

// Email_Model::enqueue and deliverPending, against a recording FakeDb and
// a FakeMailer standing in for SMTP.
class EmailDeliveryTest extends TestCase
{
    private $model;
    private $mailer;

    protected function setUp(): void
    {
        $this->model = new EmailModelHarness();
        $this->model->db = new FakeDb();
        $this->mailer = new FakeMailer();
    }

    private function waiting(array $rows)
    {
        $this->model->db->tables['email_queue'] = $rows;
    }

    private function row($id, $to, $sentAt = null)
    {
        return array('id' => $id, 'to_email' => $to, 'subject' => "Subject {$id}", 'body_html' => "<p>Body {$id}</p>", 'sent_at' => $sentAt);
    }

    private function updates()
    {
        return array_values(array_filter($this->model->db->log, function ($entry) {
            return $entry[0] === 'update';
        }));
    }

    // ---- enqueue ---------------------------------------------------

    public function testEnqueueInsertsOneUnsentRow()
    {
        $this->model->enqueue('a@sdca.edu.ph', 'Hello', '<p>Hi</p>');

        $this->assertSame(array(
            array('insert', 'email_queue', array('to_email' => 'a@sdca.edu.ph', 'subject' => 'Hello', 'body_html' => '<p>Hi</p>')),
        ), $this->model->db->log);
    }

    // ---- deliverPending -------------------------------------------

    public function testEveryWaitingEmailIsSentThroughTheMailerInOrder()
    {
        $this->waiting(array($this->row(1, 'a@x.ph'), $this->row(2, 'b@x.ph')));

        $this->model->deliverPending($this->mailer);

        $this->assertSame(array(
            array('a@x.ph', 'Subject 1', '<p>Body 1</p>'),
            array('b@x.ph', 'Subject 2', '<p>Body 2</p>'),
        ), $this->mailer->sent);
    }

    public function testEachSentEmailIsMarkedSent()
    {
        $this->waiting(array($this->row(1, 'a@x.ph'), $this->row(2, 'b@x.ph')));

        $result = $this->model->deliverPending($this->mailer);

        $this->assertSame(array(1, 2), $result['sent']);
        $this->assertSame(array(), $result['failed']);
        $this->assertSame(array(array('where', 'id', 1), array('where', 'id', 2)), array_values(array_filter($this->model->db->log, function ($e) {
            return $e[0] === 'where' && $e[1] === 'id';
        })));
        $this->assertCount(2, $this->updates());
    }

    public function testAFailedEmailIsReportedAndNotMarkedSent()
    {
        $this->waiting(array($this->row(1, 'bad@x.ph')));
        $this->mailer->failFor = array('bad@x.ph');

        $result = $this->model->deliverPending($this->mailer);

        $this->assertSame(array(), $result['sent']);
        $this->assertSame(array(array('id' => 1, 'to' => 'bad@x.ph', 'error' => 'SMTP Error: could not deliver to bad@x.ph')), $result['failed']);
        $this->assertSame(array(), $this->updates());
    }

    public function testOneFailureDoesNotStopTheRestOfTheBatch()
    {
        $this->waiting(array($this->row(1, 'a@x.ph'), $this->row(2, 'bad@x.ph'), $this->row(3, 'c@x.ph')));
        $this->mailer->failFor = array('bad@x.ph');

        $result = $this->model->deliverPending($this->mailer);

        $this->assertSame(array(1, 3), $result['sent']);
        $this->assertSame(array(2), array_column($result['failed'], 'id'));
        $this->assertCount(2, $this->updates());
    }

    public function testAnEmptyQueueSendsNothing()
    {
        $result = $this->model->deliverPending($this->mailer);

        $this->assertSame(array('sent' => array(), 'failed' => array()), $result);
        $this->assertSame(array(), $this->mailer->sent);
    }

    public function testAlreadySentEmailsAreNotSentAgain()
    {
        $this->waiting(array($this->row(1, 'a@x.ph', '2026-09-01 10:00:00'), $this->row(2, 'b@x.ph')));

        $this->model->deliverPending($this->mailer);

        $this->assertSame(array('b@x.ph'), array_column($this->mailer->sent, 0));
    }

    public function testTheBatchLimitIsPassedToTheQueueRead()
    {
        $this->model->deliverPending($this->mailer, 5);

        $this->assertContains(array('limit', 5), $this->model->db->log);
    }

    public function testTheDefaultBatchIsTwenty()
    {
        $this->model->deliverPending($this->mailer);

        $this->assertContains(array('limit', 20), $this->model->db->log);
    }

    // ---- today's retry behaviour, pinned --------------------------

    public function testAnUndeliverableEmailIsRetriedOnEveryRunAndNeverGivenUpOn()
    {
        // Known gap, pinned so changing it is a decision: there is no
        // attempt count or dead-letter state, so a row that can never be
        // delivered stays at the front of the queue forever.
        $this->waiting(array($this->row(1, 'bad@x.ph')));
        $this->mailer->failFor = array('bad@x.ph');

        $first = $this->model->deliverPending($this->mailer);
        $second = $this->model->deliverPending($this->mailer);
        $third = $this->model->deliverPending($this->mailer);

        foreach (array($first, $second, $third) as $run) {
            $this->assertSame(array(1), array_column($run['failed'], 'id'));
        }
        $this->assertSame(array(), $this->updates());
    }

    public function testEnoughUndeliverableEmailsAtTheFrontStarveEveryoneBehindThem()
    {
        // Known gap, pinned: the batch is the oldest 20 unsent rows, so 20
        // permanently failing rows mean later emails are never reached.
        $rows = array();
        for ($i = 1; $i <= 20; $i++) {
            $rows[] = $this->row($i, 'bad@x.ph');
        }
        $rows[] = $this->row(21, 'good@x.ph');
        $this->waiting($rows);
        $this->mailer->failFor = array('bad@x.ph');

        $result = $this->model->deliverPending($this->mailer);

        $this->assertSame(array(), $result['sent']);
        $this->assertSame(array(), $this->mailer->sent);
        $this->assertCount(20, $result['failed']);
    }
}
