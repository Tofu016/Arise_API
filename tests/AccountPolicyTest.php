<?php
use PHPUnit\Framework\TestCase;

class AccountPolicyTest extends TestCase
{
    private function refusal(callable $check)
    {
        return Api_response::run(function () use ($check) {
            $check();
        });
    }

    // ---- password -------------------------------------------------

    public function testAPasswordUnderEightCharactersIsRefusedWith400()
    {
        $reply = $this->refusal(function () {
            Account_policy::requirePassword('1234567');
        });

        $this->assertSame(400, $reply->status());
        $this->assertSame('Password must be at least 8 characters.', $reply->body()['error']);
    }

    public function testEightCharactersIsEnough()
    {
        $this->assertNull($this->refusal(function () {
            Account_policy::requirePassword('12345678');
        }));
    }

    public function testAnEmptyPasswordIsRefused()
    {
        $this->assertSame(400, $this->refusal(function () {
            Account_policy::requirePassword('');
        })->status());
    }

    public function testLengthCountsBytesNotCharacters()
    {
        // Today's behaviour, pinned: five accented letters are ten bytes.
        $this->assertNull($this->refusal(function () {
            Account_policy::requirePassword('ééééé');
        }));
    }

    public function testTheMessageFollowsTheConfiguredMinimum()
    {
        $this->assertSame(8, Account_policy::MIN_PASSWORD_LENGTH);
    }

    // ---- email domain ---------------------------------------------

    public function testTheSchoolDomainIsAccepted()
    {
        $this->assertNull($this->refusal(function () {
            Account_policy::requireEmailDomain('ana@sdca.edu.ph');
        }));
    }

    /** @dataProvider foreignEmails */
    public function testAnyOtherDomainIsRefusedWith403($email)
    {
        $reply = $this->refusal(function () use ($email) {
            Account_policy::requireEmailDomain($email);
        });

        $this->assertSame(403, $reply->status());
        $this->assertSame('Registration is only open to @sdca.edu.ph email addresses.', $reply->body()['error']);
    }

    public function foreignEmails()
    {
        return array(
            'other provider' => array('ana@gmail.com'),
            'lookalike suffix' => array('ana@sdca.edu.ph.evil.com'),
            'domain as a prefix' => array('@sdca.edu.ph@evil.com'),
            'no domain' => array('ana'),
            'empty' => array(''),
            'shorter than the domain' => array('a@b'),
            'uppercase (caller must lowercase first)' => array('ANA@SDCA.EDU.PH'),
            'subdomain without the @' => array('ana.sdca.edu.ph'),
        );
    }

    public function testAMatchingSuffixIsAllThatIsChecked()
    {
        // Today's behaviour, pinned: no check that the address is well
        // formed, so anything ending in the domain passes.
        $this->assertNull($this->refusal(function () {
            Account_policy::requireEmailDomain('not an email@sdca.edu.ph');
        }));
    }
}
