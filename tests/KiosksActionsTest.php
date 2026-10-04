<?php
// Pins Kiosks_API's guards and the pairing flow: admin-only management, a
// single-use code that is stored only as a hash, lockout after repeated
// misses, and a token that stops working once revoked.
class KiosksActionsTest extends ActionTestCase
{
    private $row = array('id' => '3', 'name' => 'Lobby', 'node_id' => 'n1');

    private function model(array $returns = array())
    {
        return array('Kiosks_Model' => array_merge(array(
            'find' => $this->row,
            'create' => $this->row,
            'update' => $this->row,
            'issueCode' => $this->row,
            'recentFailures' => 0,
            'redeemCode' => $this->row,
            'findByToken' => $this->row,
        ), $returns));
    }

    private function withToken($token)
    {
        return function ($c) use ($token) {
            $c->input = new FakeKioskInput();
            $c->input->token = $token;
        };
    }

    private function modelCalls($method)
    {
        $calls = array();
        foreach ($this->controller->Kiosks_Model->calls as $call) {
            if ($call[0] === $method) {
                $calls[] = $call[1];
            }
        }
        return $calls;
    }

    /** @dataProvider replies */
    public function testReplies($action, array $params, array $body, array $returns, $status, $error)
    {
        $this->assertReply($this->call('KiosksApiHarness', $action, $params, $body, $this->model($returns)), $status, $error);
    }

    public function replies()
    {
        return array(
            'create: missing name' => array('create', array(), array(), array(), 400, 'name is required.'),
            'create: valid' => array('create', array(), array('name' => 'Lobby', 'node_id' => 'n1'), array(), 200, null),
            'update: no id' => array('update', array(), array('name' => 'x'), array(), 400, 'Missing kiosk id.'),
            'update: unknown' => array('update', array('9'), array('name' => 'x'), array('find' => null), 404, 'Kiosk not found.'),
            'update: nothing to change' => array('update', array('3'), array('bogus' => 1), array(), 400, 'No valid fields to update.'),
            'update: valid' => array('update', array('3'), array('node_id' => ''), array(), 200, null),
            'resetPairing: unknown' => array('resetPairing', array('9'), array(), array('find' => null), 404, 'Kiosk not found.'),
            'resetPairing: valid' => array('resetPairing', array('3'), array(), array(), 200, null),
            'delete: unknown' => array('delete', array('9'), array(), array('find' => null), 404, 'Kiosk not found.'),
            'delete: valid' => array('delete', array('3'), array(), array(), 200, null),
        );
    }

    public function testManagementIsAdminOnly()
    {
        foreach (array('getAll', 'create', 'update', 'resetPairing', 'delete') as $action) {
            $this->assertReply($this->call('KiosksApiHarness', $action, array('3'), array('name' => 'x'), $this->model(), null), 401, 'Not signed in.');
        }
    }

    public function testCreateStoresOnlyTheHashOfTheCodeItReturns()
    {
        $reply = $this->call('KiosksApiHarness', 'create', array(), array('name' => 'Lobby'), $this->model());
        $code = $reply->body()['pairing_code'];
        $this->assertMatchesRegularExpression('/^\d{8}$/', $code);
        $stored = $this->modelCalls('create')[0];
        $this->assertSame(Kiosk_rules::hash($code), $stored[1]);
        $this->assertNotContains($code, $stored[0]);
    }

    public function testPairRedeemsTheCodeForATokenStoredOnlyAsAHash()
    {
        $reply = $this->call('KiosksApiHarness', 'pair', array(), array('code' => '1234 5678'), $this->model(), null, $this->withToken(null));
        $this->assertReply($reply, 200);
        $token = $reply->body()['token'];
        $redeem = $this->modelCalls('redeemCode')[0];
        $this->assertSame(Kiosk_rules::hash('12345678'), $redeem[0]);
        $this->assertSame(Kiosk_rules::hash($token), $redeem[1]);
        $this->assertSame(array('id' => '3', 'name' => 'Lobby', 'node_id' => 'n1'), $reply->body()['kiosk']);
    }

    public function testPairRefusesAWrongOrExpiredCodeAndRecordsTheMiss()
    {
        $reply = $this->call('KiosksApiHarness', 'pair', array(), array('code' => '12345678'), $this->model(array('redeemCode' => null)), null, $this->withToken(null));
        $this->assertReply($reply, 400, 'That code is not valid, or it has expired.');
        $this->assertCount(1, $this->modelCalls('recordFailure'));
    }

    public function testPairRefusesAMalformedCodeWithoutAskingTheDatabase()
    {
        $reply = $this->call('KiosksApiHarness', 'pair', array(), array('code' => '123'), $this->model(), null, $this->withToken(null));
        $this->assertReply($reply, 400, 'That code is not valid, or it has expired.');
        $this->assertCount(0, $this->modelCalls('redeemCode'));
    }

    public function testPairLocksOutAfterTooManyMisses()
    {
        $reply = $this->call('KiosksApiHarness', 'pair', array(), array('code' => '12345678'), $this->model(array('recentFailures' => 5)), null, $this->withToken(null));
        $this->assertReply($reply, 429, 'Too many attempts. Try again in a few minutes.');
        $this->assertCount(0, $this->modelCalls('redeemCode'));
    }

    public function testMeNeedsAKnownToken()
    {
        $this->assertReply($this->call('KiosksApiHarness', 'me', array(), array(), $this->model(), null, $this->withToken(null)), 401, 'This device is not paired.');
        $this->assertReply($this->call('KiosksApiHarness', 'me', array(), array(), $this->model(array('findByToken' => null)), null, $this->withToken('revoked')), 401, 'This device is not paired.');
    }

    public function testMeReportsTheKioskAndBeatsTheHeart()
    {
        $reply = $this->call('KiosksApiHarness', 'me', array(), array(), $this->model(), null, $this->withToken('abc'));
        $this->assertReply($reply, 200);
        $this->assertSame(Kiosk_rules::hash('abc'), $this->modelCalls('findByToken')[0][0]);
        $this->assertCount(1, $this->modelCalls('touch'));
        $this->assertSame('n1', $reply->body()['kiosk']['node_id']);
    }

    public function testUnpairClearsTheToken()
    {
        $this->assertReply($this->call('KiosksApiHarness', 'unpair', array(), array(), $this->model(), null, $this->withToken('abc')), 200);
        $this->assertSame(array(array('3')), $this->modelCalls('clearToken'));
    }
}
