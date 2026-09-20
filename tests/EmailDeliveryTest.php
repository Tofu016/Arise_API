<?php
use PHPUnit\Framework\TestCase;

// Email_Model::enqueue and deliverPending, against a FakeDb that behaves
// like a small in-memory database and a FakeMailer standing in for SMTP.
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

    private function row($id, $to, $sentAt = null, $attempts = 0)
    {
        return array(
            'id' => $id, 'to_email' => $to, 'subject' => "Subject {$id}", 'body_html' => "<p>Body {$id}</p>",
            'attempts' => $attempts, 'last_error' => null, 'sent_at' => $sentAt,
            'created_at' => sprintf('2026-09-01 10:00:%02d', $id),
        );
    }

    // The stored row with this id, as the database now holds it.
    private function stored($id)
    {
        foreach ($this->model->db->tables['email_queue'] as $row) {
            if ($row['id'] === $id) {
                return $row;
            }
        }
        $this->fail("No stored row {$id}");
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
        $this->assertNotNull($this->stored(1)['sent_at']);
        $this->assertNotNull($this->stored(2)['sent_at']);
    }

    public function testAFailedEmailIsReportedAndNotMarkedSent()
    {
        $this->waiting(array($this->row(1, 'bad@x.ph')));
        $this->mailer->failFor = array('bad@x.ph');

        $result = $this->model->deliverPending($this->mailer);

        $this->assertSame(array(), $result['sent']);
        $this->assertSame(array(array('id' => 1, 'to' => 'bad@x.ph', 'error' => 'SMTP Error: could not deliver to bad@x.ph')), $result['failed']);
        $this->assertNull($this->stored(1)['sent_at']);
    }

    public function testOneFailureDoesNotStopTheRestOfTheBatch()
    {
        $this->waiting(array($this->row(1, 'a@x.ph'), $this->row(2, 'bad@x.ph'), $this->row(3, 'c@x.ph')));
        $this->mailer->failFor = array('bad@x.ph');

        $result = $this->model->deliverPending($this->mailer);

        $this->assertSame(array(1, 3), $result['sent']);
        $this->assertSame(array(2), array_column($result['failed'], 'id'));
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

    // ---- recording failures ---------------------------------------

    public function testAFailureCountsAnAttemptAndRemembersWhy()
    {
        $this->waiting(array($this->row(1, 'bad@x.ph')));
        $this->mailer->failFor = array('bad@x.ph');

        $this->model->deliverPending($this->mailer);

        $this->assertSame(1, $this->stored(1)['attempts']);
        $this->assertSame('SMTP Error: could not deliver to bad@x.ph', $this->stored(1)['last_error']);
    }

    public function testEachFailedRunAddsOneAttemptAndKeepsTheLatestError()
    {
        $this->waiting(array($this->row(1, 'bad@x.ph')));
        $this->mailer->failFor = array('bad@x.ph');

        $this->model->deliverPending($this->mailer);
        $this->model->deliverPending($this->mailer);
        $this->model->deliverPending($this->mailer);

        $this->assertSame(3, $this->stored(1)['attempts']);
        $this->assertNull($this->stored(1)['sent_at']);
    }

    public function testASuccessDoesNotCountAnAttemptAgainstTheRow()
    {
        $this->waiting(array($this->row(1, 'a@x.ph')));

        $this->model->deliverPending($this->mailer);

        $this->assertSame(0, $this->stored(1)['attempts']);
    }

    public function testARowThatFailedBeforeKeepsItsCountWhenItFinallySends()
    {
        $this->waiting(array($this->row(1, 'flaky@x.ph')));
        $this->mailer->failFor = array('flaky@x.ph');
        $this->model->deliverPending($this->mailer);
        $this->model->deliverPending($this->mailer);

        $this->mailer->failFor = array();
        $result = $this->model->deliverPending($this->mailer);

        $this->assertSame(array(1), $result['sent']);
        $this->assertSame(2, $this->stored(1)['attempts']);
        $this->assertNotNull($this->stored(1)['sent_at']);
    }

    public function testAVeryLongErrorIsTruncatedToWhatTheColumnHolds()
    {
        $this->waiting(array($this->row(1, 'bad@x.ph')));
        $mailer = new class extends FakeMailer {
            public function send($toEmail, $subject, $bodyHtml)
            {
                throw new RuntimeException(str_repeat('é', 600));
            }
        };

        $this->model->deliverPending($mailer);

        $stored = $this->stored(1)['last_error'];
        $this->assertSame(500, mb_strlen($stored));
        $this->assertTrue(mb_check_encoding($stored, 'UTF-8'));
    }

    // ---- a failing row can never starve the rest -------------------

    public function testTheQueueIsReadFreshMailFirstThenOldestFirst()
    {
        $this->model->deliverPending($this->mailer);

        $this->assertContains(array('order_by', 'attempts', 'ASC'), $this->model->db->log);
        $this->assertContains(array('order_by', 'created_at', 'ASC'), $this->model->db->log);
        $this->assertLessThan(
            array_search(array('order_by', 'created_at', 'ASC'), $this->model->db->log, true),
            array_search(array('order_by', 'attempts', 'ASC'), $this->model->db->log, true)
        );
    }

    public function testAnEmailThatHasNeverBeenTriedGoesBeforeOneThatKeepsFailing()
    {
        $this->waiting(array($this->row(1, 'old-and-failing@x.ph', null, 3), $this->row(2, 'new@x.ph')));

        $this->model->deliverPending($this->mailer);

        $this->assertSame(array('new@x.ph', 'old-and-failing@x.ph'), array_column($this->mailer->sent, 0));
    }

    public function testEqualAttemptsMeansOldestFirst()
    {
        $this->waiting(array($this->row(2, 'second@x.ph'), $this->row(1, 'first@x.ph')));

        $this->model->deliverPending($this->mailer);

        $this->assertSame(array('first@x.ph', 'second@x.ph'), array_column($this->mailer->sent, 0));
    }

    public function testTwentyUndeliverableEmailsAtTheFrontNoLongerBlockLaterOnes()
    {
        // This used to starve the queue: the batch was the oldest 20 unsent
        // rows, so 20 permanently failing rows meant later emails were never
        // reached. The failing rows now sink behind untried ones.
        $rows = array();
        for ($i = 1; $i <= 20; $i++) {
            $rows[] = $this->row($i, 'bad@x.ph');
        }
        $rows[] = $this->row(21, 'good@x.ph');
        $this->waiting($rows);
        $this->mailer->failFor = array('bad@x.ph');

        $first = $this->model->deliverPending($this->mailer);
        $second = $this->model->deliverPending($this->mailer);

        $this->assertSame(array(), $first['sent']);
        $this->assertSame(array(21), $second['sent']);
        $this->assertSame(array('good@x.ph'), array_column($this->mailer->sent, 0));
    }

    public function testEvenManyFailingRowsLeaveRoomForFreshMailOnEveryRun()
    {
        $rows = array();
        for ($i = 1; $i <= 60; $i++) {
            $rows[] = $this->row($i, 'bad@x.ph');
        }
        $this->waiting($rows);
        $this->mailer->failFor = array('bad@x.ph');
        for ($run = 0; $run < 5; $run++) {
            $this->model->deliverPending($this->mailer);
        }

        // A new email arrives behind 60 stuck ones.
        $this->model->db->tables['email_queue'][] = $this->row(61, 'fresh@x.ph');
        $result = $this->model->deliverPending($this->mailer);

        $this->assertContains(61, $result['sent']);
    }

    // ---- never given up on -----------------------------------------

    public function testAnUndeliverableEmailIsRetriedForeverAndNeverAbandoned()
    {
        $this->waiting(array($this->row(1, 'bad@x.ph')));
        $this->mailer->failFor = array('bad@x.ph');

        for ($run = 1; $run <= 30; $run++) {
            $result = $this->model->deliverPending($this->mailer);
            $this->assertSame(array(1), array_column($result['failed'], 'id'), "run {$run}");
        }

        $this->assertSame(30, $this->stored(1)['attempts']);
        $this->assertNull($this->stored(1)['sent_at']);
    }

    public function testALongOutageAbandonsNothingAndEverythingGoesOutWhenItEnds()
    {
        $rows = array();
        for ($i = 1; $i <= 25; $i++) {
            $rows[] = $this->row($i, "user{$i}@x.ph");
            $this->mailer->failFor[] = "user{$i}@x.ph";
        }
        $this->waiting($rows);

        // Two hours of a 2-minute cron with SMTP down.
        for ($run = 0; $run < 60; $run++) {
            $this->model->deliverPending($this->mailer);
        }
        $this->assertSame(array(), $this->mailer->sent);

        $this->mailer->failFor = array();
        $sent = array();
        for ($run = 0; $run < 3; $run++) {
            $sent = array_merge($sent, $this->model->deliverPending($this->mailer)['sent']);
        }

        sort($sent);
        $this->assertSame(range(1, 25), $sent);
    }
}
