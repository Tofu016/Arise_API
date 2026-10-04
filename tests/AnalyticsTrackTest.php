<?php
// Pins how Analytics_API::track decides a session's platform: only a paired
// kiosk's own token makes it "kiosk"; everything else, including a browser
// showing the kiosk layout, is "web".
class AnalyticsTrackTest extends ActionTestCase
{
    const SESSION = '3f2b8c1e-7a4d-4b59-9c0e-1d2a3b4c5d6e';

    private function track(array $body, $paired)
    {
        $returns = array('Kiosks_Model' => array('findByToken' => $paired ? array('id' => '3') : null));
        $reply = $this->call('AnalyticsApiHarness', 'track', array(), $body + array('session_id' => self::SESSION), $returns, null);
        $this->assertReply($reply, 200);
        $calls = array();
        foreach ($this->controller->Analytics_Model->calls as $call) {
            if ($call[0] === 'ensureSession') {
                $calls[] = $call[1];
            }
        }
        return $calls[0];
    }

    public function testAPairedKioskTokenMakesAKioskSession()
    {
        $args = $this->track(array('kiosk_token' => 'good', 'has_gate' => true), true);
        $this->assertSame('kiosk', $args[1]);
        $this->assertTrue($args[4]);
    }

    public function testNoTokenIsAWebSessionEvenOnTheKioskLayout()
    {
        $args = $this->track(array('has_gate' => true), false);
        $this->assertSame('web', $args[1]);
        $this->assertTrue($args[4]);
    }

    public function testARevokedOrUnknownTokenIsAWebSession()
    {
        $this->assertSame('web', $this->track(array('kiosk_token' => 'revoked'), false)[1]);
    }

    public function testAClaimedPlatformIsIgnored()
    {
        $this->assertSame('web', $this->track(array('platform' => 'kiosk'), false)[1]);
    }

    public function testASessionWithoutAGateStartsExploring()
    {
        $this->assertFalse($this->track(array(), false)[4]);
    }

    public function testNeedsAUuidSession()
    {
        $reply = $this->call('AnalyticsApiHarness', 'track', array(), array('session_id' => 'nope'), array(), null);
        $this->assertReply($reply, 400, 'A UUID session_id is required.');
    }
}
