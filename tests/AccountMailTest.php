<?php
use PHPUnit\Framework\TestCase;

class AccountMailTest extends TestCase
{
    public function testWelcome()
    {
        $mail = Account_mail::welcome('Ana');

        $this->assertSame('Welcome to ARISE Campus Navigator', $mail['subject']);
        $this->assertSame('<p>Hi Ana,</p><p>Thanks for registering. Your account is currently pending approval — you\'ll receive another email once an administrator approves it.</p>', $mail['html']);
    }

    public function testAccountApproved()
    {
        $mail = Account_mail::accountApproved('Ana');

        $this->assertSame('Your ARISE Campus Navigator account has been approved', $mail['subject']);
        $this->assertSame('<p>Hi Ana,</p><p>Your account has been approved. You can now log in and start using ARISE Campus Navigator.</p>', $mail['html']);
    }

    public function testPasswordReset()
    {
        $mail = Account_mail::passwordReset('Ana', 'https://app.sdca.edu.ph', 'abc123');

        $this->assertSame('Reset your ARISE Campus Navigator password', $mail['subject']);
        $this->assertSame(
            '<p>Hi Ana,</p>'
            . '<p>Click the link below to reset your password. This link expires in 1 hour and can only be used once.</p>'
            . '<p><a href="https://app.sdca.edu.ph/reset-password?token=abc123">https://app.sdca.edu.ph/reset-password?token=abc123</a></p>'
            . '<p>If you didn\'t request this, you can safely ignore this email.</p>',
            $mail['html']
        );
    }

    public function testTheResetLinkIsBuiltFromTheGivenBaseWithOrWithoutATrailingSlash()
    {
        $plain = Account_mail::passwordReset('A', 'https://app.sdca.edu.ph', 't');
        $slashed = Account_mail::passwordReset('A', 'https://app.sdca.edu.ph/', 't');

        $this->assertSame($plain['html'], $slashed['html']);
        $this->assertStringContainsString('https://app.sdca.edu.ph/reset-password?token=t', $plain['html']);
    }

    public function testTheTokenIsUrlEncodedInTheLink()
    {
        $mail = Account_mail::passwordReset('A', 'https://x.ph', 'a b+c&d');

        $this->assertStringContainsString('token=a+b%2Bc%26d', $mail['html']);
    }

    /** @dataProvider mails */
    public function testTheRecipientsNameIsEscapedEverywhere($build)
    {
        $mail = $build("<script>alert(1)</script> & Co");

        $this->assertStringNotContainsString('<script>', $mail['html']);
        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt; &amp; Co', $mail['html']);
    }

    public function mails()
    {
        return array(
            'welcome' => array(function ($name) {
                return Account_mail::welcome($name);
            }),
            'approved' => array(function ($name) {
                return Account_mail::accountApproved($name);
            }),
            'reset' => array(function ($name) {
                return Account_mail::passwordReset($name, 'https://x.ph', 't');
            }),
        );
    }

    public function testALinkWithMarkupCharactersIsEscapedInsideTheHtml()
    {
        $mail = Account_mail::passwordReset('A', 'https://x.ph/?a=1&b=2', 't');

        $this->assertStringContainsString('a=1&amp;b=2', $mail['html']);
        $this->assertStringNotContainsString('a=1&b=2', $mail['html']);
    }
}
