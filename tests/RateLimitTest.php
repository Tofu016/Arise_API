<?php
use PHPUnit\Framework\TestCase;

class RateLimitModelHarness extends Rate_limit_Model
{
    public $db;

    public function __construct()
    {
    }
}

// Pins the pure helpers behind the rate limits and the hit log's queries.
class RateLimitTest extends TestCase
{
    public function testSubjectsAreHashedToTheColumnWidth()
    {
        $subject = Rate_limit::subject('10.0.0.5');

        $this->assertSame(64, strlen($subject));
        $this->assertStringNotContainsString('10.0.0.5', $subject);
        $this->assertSame($subject, Rate_limit::subject('10.0.0.5'));
        $this->assertNotSame($subject, Rate_limit::subject('10.0.0.6'));
    }

    public function testTheWindowStartsThatManySecondsBack()
    {
        $this->assertSame('2026-10-04 12:00:00', Rate_limit::windowStart('2026-10-04 12:00:30', 30));
    }

    /** @dataProvider retryAfters */
    public function testRetryAfter($oldest, $expected)
    {
        $this->assertSame($expected, Rate_limit::retryAfter($oldest, '2026-10-04 12:00:20', 30));
    }

    public function retryAfters()
    {
        return array(
            'ten seconds in' => array('2026-10-04 12:00:10', 20),
            'about to expire' => array('2026-10-04 11:59:50', 1),
            'already past, never below one' => array('2026-10-04 11:00:00', 1),
            'unknown hit waits the window' => array(null, 30),
        );
    }

    /** @dataProvider honeypots */
    public function testHoneypot(array $data, $tripped)
    {
        $this->assertSame($tripped, Rate_limit::honeypotTripped($data));
    }

    public function honeypots()
    {
        return array(
            'absent' => array(array('rating' => 5), false),
            'empty' => array(array('hp_contact_url' => ''), false),
            'blank' => array(array('hp_contact_url' => '   '), false),
            'filled' => array(array('hp_contact_url' => 'http://spam.example'), true),
            'an array is not empty' => array(array('hp_contact_url' => array('x')), true),
        );
    }

    public function testOnlyAUuidIsAVisitorId()
    {
        $uuid = '3F2B8C1E-7A4D-4B59-9C0E-1D2A3B4C5D6E';

        $this->assertSame(strtolower($uuid), Rate_limit::visitorId($uuid));
        $this->assertNull(Rate_limit::visitorId('not-a-uuid'));
        $this->assertNull(Rate_limit::visitorId(null));
        $this->assertNull(Rate_limit::visitorId(array($uuid)));
    }

    private function model(array $rows)
    {
        $model = new RateLimitModelHarness();
        $model->db = new FakeDb();
        $model->db->tables = array('rate_limit_hits' => $rows);
        return $model;
    }

    public function testCountingOnlyLooksAtThisBucketSubjectAndWindow()
    {
        $model = $this->model(array(
            array('id' => 1, 'bucket' => 'login_ip', 'subject' => 'a', 'hit_at' => '2026-10-04 12:00:00'),
            array('id' => 2, 'bucket' => 'login_ip', 'subject' => 'a', 'hit_at' => '2026-10-04 12:05:00'),
            array('id' => 3, 'bucket' => 'login_ip', 'subject' => 'a', 'hit_at' => '2026-10-04 11:00:00'),
            array('id' => 4, 'bucket' => 'login_ip', 'subject' => 'b', 'hit_at' => '2026-10-04 12:05:00'),
            array('id' => 5, 'bucket' => 'login_email', 'subject' => 'a', 'hit_at' => '2026-10-04 12:05:00'),
        ));

        $this->assertSame(2, $model->countSince('login_ip', 'a', '2026-10-04 11:50:00'));
        $this->assertSame('2026-10-04 12:00:00', $model->oldestSince('login_ip', 'a', '2026-10-04 11:50:00'));
        $this->assertNull($model->oldestSince('login_ip', 'zzz', '2026-10-04 11:50:00'));
    }

    public function testRecordingAddsARow()
    {
        $model = $this->model(array());

        $model->record('feedback_visitor', 'a', '2026-10-04 12:00:00');

        $this->assertSame(
            array(array('insert', 'rate_limit_hits', array('bucket' => 'feedback_visitor', 'subject' => 'a', 'hit_at' => '2026-10-04 12:00:00'))),
            $model->db->log
        );
    }

    public function testPurgeDeletesOnlyHitsOlderThanTheCutoff()
    {
        $model = $this->model(array(
            array('id' => 1, 'bucket' => 'b', 'subject' => 's', 'hit_at' => '2026-10-02 12:00:00'),
            array('id' => 2, 'bucket' => 'b', 'subject' => 's', 'hit_at' => '2026-10-04 12:00:00'),
        ));

        $this->assertSame(1, $model->purgeOlderThan('2026-10-03 12:00:00'));
        $this->assertSame(array(2), array_column($model->db->tables['rate_limit_hits'], 'id'));
    }
}
