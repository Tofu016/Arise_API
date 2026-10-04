<?php
// Pins how the public actions use their rate limits: feedback's honeypot and
// 30 second limit, login's failure lockout, and analytics' flood cap.
class RateLimitedActionsTest extends ActionTestCase
{
    const VISITOR = '3f2b8c1e-7a4d-4b59-9c0e-1d2a3b4c5d6e';

    private function recorded()
    {
        $hits = array();
        foreach ($this->controller->Rate_limit_Model->calls as $call) {
            if ($call[0] === 'record') {
                $hits[] = $call[1][0];
            }
        }
        return $hits;
    }

    // countSince is faked to return the same number for every bucket; a
    // test that needs one bucket over its limit picks the number to suit.
    private function limits($count, $oldest = null)
    {
        return array('Rate_limit_Model' => array('countSince' => $count, 'oldestSince' => $oldest));
    }

    public function testFeedbackAcceptedWhenUnderTheLimitsAndBothHitsAreRecorded()
    {
        $reply = $this->call('FeedbackApiHarness', 'submit', array(), array('rating' => 4, 'visitor_id' => self::VISITOR), $this->limits(0), null);

        $this->assertReply($reply, 200);
        $this->assertSame(array('feedback_ip', 'feedback_visitor'), $this->recorded());
        $this->assertSame('create', $this->controller->Feedback_Model->calls[0][0]);
    }

    public function testFeedbackWithinThirtySecondsOfTheLastIsRefusedWithRetryAfter()
    {
        $oldest = date('Y-m-d H:i:s', strtotime('-10 seconds'));
        $reply = $this->call('FeedbackApiHarness', 'submit', array(), array('rating' => 4, 'visitor_id' => self::VISITOR), $this->limits(1, $oldest), null);

        $this->assertSame(429, $reply->status());
        $this->assertFalse($reply->body()['success']);
        $this->assertEqualsWithDelta(20, $reply->body()['retry_after'], 1);
        $this->assertSame($reply->body()['retry_after'], (int) filter_var($reply->body()['error'], FILTER_SANITIZE_NUMBER_INT));
        $this->assertSame(array(), $this->recorded());
        $this->assertSame(array(), $this->controller->Feedback_Model->calls);
    }

    public function testTheVisitorLimitIsKeyedOnTheVisitorIdNotTheIp()
    {
        $this->call('FeedbackApiHarness', 'submit', array(), array('rating' => 4, 'visitor_id' => self::VISITOR), $this->limits(0), null);
        $keyed = $this->subjectsChecked();
        $this->call('FeedbackApiHarness', 'submit', array(), array('rating' => 4, 'visitor_id' => 'junk'), $this->limits(0), null);
        $fallback = $this->subjectsChecked();

        $this->assertSame(Rate_limit::subject(self::VISITOR), $keyed['feedback_visitor']);
        $this->assertSame(Rate_limit::subject('10.0.0.5'), $keyed['feedback_ip']);
        $this->assertSame(Rate_limit::subject('10.0.0.5'), $fallback['feedback_visitor']);
    }

    private function subjectsChecked()
    {
        $subjects = array();
        foreach ($this->controller->Rate_limit_Model->calls as $call) {
            if ($call[0] === 'countSince') {
                $subjects[$call[1][0]] = $call[1][1];
            }
        }
        return $subjects;
    }

    public function testAFilledHoneypotLooksLikeSuccessAndStoresNothing()
    {
        $body = array('rating' => 5, Rate_limit::HONEYPOT_FIELD => 'http://spam.example');
        $reply = $this->call('FeedbackApiHarness', 'submit', array(), $body, $this->limits(99), null);

        $this->assertReply($reply, 200);
        $this->assertNull($reply->body()['feedback']['id']);
        $this->assertSame(array(), $this->controller->Feedback_Model->calls);
        $this->assertSame(array(), $this->controller->Rate_limit_Model->calls);
    }

    public function testAnInvalidRatingDoesNotUseUpTheVisitorsWindow()
    {
        $reply = $this->call('FeedbackApiHarness', 'submit', array(), array('rating' => 9), $this->limits(0), null);

        $this->assertReply($reply, 400, 'Rating must be between 1 and 5.');
        $this->assertSame(array(), $this->recorded());
    }

    public function testLoginLocksOutBeforeCheckingThePassword()
    {
        $returns = $this->limits(Rate_limit::LOGIN_MAX_FAILURES) + array('Auth_Model' => array('verifyCredentials' => array('id' => 1, 'email' => 'a@b.c', 'name' => 'A', 'status' => 'approved'), 'createToken' => 't'));
        $reply = $this->call('AuthApiHarness', 'login', array(), array('email' => 'a@b.c', 'password' => 'right'), $returns, null);

        $this->assertSame(429, $reply->status());
        $this->assertArrayHasKey('retry_after', $reply->body());
        $this->assertSame(array(), $this->controller->Auth_Model->calls);
    }

    public function testAFailedLoginIsCountedAgainstTheIpAndTheEmail()
    {
        $returns = $this->limits(0) + array('Auth_Model' => array('verifyCredentials' => false));
        $reply = $this->call('AuthApiHarness', 'login', array(), array('email' => 'A@B.C', 'password' => 'x'), $returns, null);

        $this->assertReply($reply, 401, 'Invalid email or password.');
        $this->assertSame(array('login_ip', 'login_email'), $this->recorded());
        $subjects = array();
        foreach ($this->controller->Rate_limit_Model->calls as $call) {
            if ($call[0] === 'record') {
                $subjects[$call[1][0]] = $call[1][1];
            }
        }
        $this->assertSame(Rate_limit::subject('10.0.0.5'), $subjects['login_ip']);
        $this->assertSame(Rate_limit::subject('a@b.c'), $subjects['login_email']);
    }

    public function testASuccessfulLoginRecordsNothing()
    {
        $returns = $this->limits(0) + array('Auth_Model' => array('verifyCredentials' => array('id' => 1, 'email' => 'a@b.c', 'name' => 'A', 'status' => 'approved'), 'createToken' => 't'));
        $reply = $this->call('AuthApiHarness', 'login', array(), array('email' => 'a@b.c', 'password' => 'x'), $returns, null);

        $this->assertReply($reply, 200);
        $this->assertSame(array(), $this->recorded());
    }

    public function testAnEmptyLoginBodyIsNotCounted()
    {
        $reply = $this->call('AuthApiHarness', 'login', array(), array(), $this->limits(0), null);

        $this->assertReply($reply, 400, 'Email and password are required.');
        $this->assertSame(array(), $this->controller->Rate_limit_Model->calls);
    }

    public function testTrackIsRefusedOverTheIpCapBeforeAnythingElseHappens()
    {
        $reply = $this->call('AnalyticsApiHarness', 'track', array(), array('session_id' => 'x'), $this->limits(Rate_limit::TRACK_IP_MAX), null);

        $this->assertSame(429, $reply->status());
        $this->assertSame(array(), $this->controller->Analytics_Model->calls);
    }

    public function testTrackCountsTheIpAndTheSession()
    {
        $body = array('session_id' => self::VISITOR);
        $reply = $this->call('AnalyticsApiHarness', 'track', array(), $body, $this->limits(0), null);

        $this->assertReply($reply, 200);
        $this->assertSame(array('track_ip', 'track_session'), $this->recorded());
    }

    public function testTrackRefusesASessionOverItsOwnCap()
    {
        $reply = $this->call('AnalyticsApiHarness', 'track', array(), array('session_id' => self::VISITOR), $this->limits(Rate_limit::TRACK_SESSION_MAX), null);

        $this->assertSame(429, $reply->status());
        $this->assertSame(array(), $this->controller->Analytics_Model->calls);
    }
}
