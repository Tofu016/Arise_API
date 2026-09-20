<?php
use PHPUnit\Framework\TestCase;

class AuthSessionTest extends TestCase
{
    /** @dataProvider tokens */
    public function testTokenFromReadsWhatFollowsTheBearerScheme($header, $token)
    {
        $this->assertSame($token, Auth_session::tokenFrom($header));
    }

    public function tokens()
    {
        return array(
            'plain' => array('Bearer abc', 'abc'),
            'lowercase scheme' => array('bearer abc', 'abc'),
            'mixed case scheme' => array('BeArEr abc', 'abc'),
            'trimmed' => array("Bearer \t abc  ", 'abc'),
            'inner space kept' => array('Bearer a b', 'a b'),
            'blank token' => array('Bearer    ', ''),
        );
    }

    /** @dataProvider notBearer */
    public function testTokenFromIsNullForAnythingThatIsNotABearerHeader($header)
    {
        $this->assertNull(Auth_session::tokenFrom($header));
    }

    public function notBearer()
    {
        return array(
            'null' => array(null),
            'empty' => array(''),
            'zero string' => array('0'),
            'basic' => array('Basic abc'),
            'no scheme' => array('abc'),
            'scheme only' => array('Bearer'),
            'scheme not first' => array('X Bearer abc'),
        );
    }

    public function testUserForHandsTheTokenToTheLookupAndReturnsItsRow()
    {
        $seen = array();
        $row = array('id' => 1, 'role' => 'admin');

        $user = Auth_session::userFor('Bearer tok', function ($token) use (&$seen, $row) {
            $seen[] = $token;
            return $row;
        });

        $this->assertSame($row, $user);
        $this->assertSame(array('tok'), $seen);
    }

    /** @dataProvider refusals */
    public function testUserForIsNullWhenTheLookupRefusesTheToken($result)
    {
        $this->assertNull(Auth_session::userFor('Bearer tok', function () use ($result) {
            return $result;
        }));
    }

    public function refusals()
    {
        return array('false' => array(false), 'null' => array(null), 'empty array' => array(array()), 'empty string' => array(''));
    }

    public function testUserForNeverCallsTheLookupWithoutABearerToken()
    {
        $called = false;

        $user = Auth_session::userFor('Basic abc', function () use (&$called) {
            $called = true;
            return array('id' => 1);
        });

        $this->assertNull($user);
        $this->assertFalse($called);
    }

    public function testUserForStillAsksTheLookupAboutABlankToken()
    {
        $seen = null;

        Auth_session::userFor('Bearer  ', function ($token) use (&$seen) {
            $seen = $token;
            return false;
        });

        $this->assertSame('', $seen);
    }
}
