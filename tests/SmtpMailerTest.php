<?php
use PHPUnit\Framework\TestCase;

// Records what Smtp_mailer sets on the PHPMailer it is given, in place of
// connecting anywhere. Property names are PHPMailer's own.
class RecordingPhpMailer
{
    public $Host;
    public $Port;
    public $SMTPAuth;
    public $Username;
    public $Password;
    public $SMTPSecure;
    public $Subject;
    public $Body;
    public $ErrorInfo = '';
    public $calls = array();
    public $failWith = null;

    public function isSMTP()
    {
        $this->calls[] = array('isSMTP');
    }

    public function setFrom($email, $name)
    {
        $this->calls[] = array('setFrom', $email, $name);
    }

    public function addAddress($email)
    {
        $this->calls[] = array('addAddress', $email);
    }

    public function isHTML($html)
    {
        $this->calls[] = array('isHTML', $html);
    }

    public function send()
    {
        $this->calls[] = array('send');
        if ($this->failWith !== null) {
            $this->ErrorInfo = $this->failWith;
            throw new PHPMailer\PHPMailer\Exception($this->failWith);
        }
    }
}

class TestableSmtpMailer extends Smtp_mailer
{
    public $mail;

    protected function newMail()
    {
        return $this->mail;
    }
}

class SmtpMailerTest extends TestCase
{
    private function mailer(array $env = array())
    {
        $mailer = TestableSmtpMailer::fromEnv($env);
        $mailer->mail = new RecordingPhpMailer();
        return $mailer;
    }

    public function testConfiguresPhpMailerForAuthenticatedStartTlsSmtp()
    {
        $mailer = $this->mailer(array(
            'SMTP_HOST' => 'smtp.brevo.com', 'SMTP_PORT' => '2525', 'SMTP_USER' => 'user', 'SMTP_PASS' => 'secret',
            'SMTP_FROM_EMAIL' => 'noreply@sdca.edu.ph', 'SMTP_FROM_NAME' => 'ARISE',
        ));

        $mailer->send('ana@sdca.edu.ph', 'Hello', '<p>Hi</p>');

        $mail = $mailer->mail;
        $this->assertSame('smtp.brevo.com', $mail->Host);
        $this->assertSame(2525, $mail->Port);
        $this->assertTrue($mail->SMTPAuth);
        $this->assertSame('user', $mail->Username);
        $this->assertSame('secret', $mail->Password);
        $this->assertSame(PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS, $mail->SMTPSecure);
        $this->assertSame('Hello', $mail->Subject);
        $this->assertSame('<p>Hi</p>', $mail->Body);
    }

    public function testSendsFromTheConfiguredAddressToTheRecipientAsHtml()
    {
        $mailer = $this->mailer(array('SMTP_FROM_EMAIL' => 'noreply@sdca.edu.ph', 'SMTP_FROM_NAME' => 'ARISE'));

        $mailer->send('ana@sdca.edu.ph', 'S', 'B');

        $this->assertSame(array(
            array('isSMTP'),
            array('setFrom', 'noreply@sdca.edu.ph', 'ARISE'),
            array('addAddress', 'ana@sdca.edu.ph'),
            array('isHTML', true),
            array('send'),
        ), $mailer->mail->calls);
    }

    public function testAFailedSendThrowsWithPhpMailersOwnErrorText()
    {
        $mailer = $this->mailer();
        $mailer->mail->failWith = 'SMTP Error: Could not authenticate.';

        try {
            $mailer->send('ana@sdca.edu.ph', 'S', 'B');
            $this->fail('Expected the failed send to throw.');
        } catch (RuntimeException $e) {
            $this->assertSame('SMTP Error: Could not authenticate.', $e->getMessage());
            $this->assertInstanceOf(PHPMailer\PHPMailer\Exception::class, $e->getPrevious());
        }
    }

    public function testFromEnvUsesInertPlaceholdersWhenNothingIsSet()
    {
        $mailer = $this->mailer(array());

        $mailer->send('ana@sdca.edu.ph', 'S', 'B');

        $mail = $mailer->mail;
        $this->assertSame('smtp.example.com', $mail->Host);
        $this->assertSame(587, $mail->Port);
        $this->assertSame('', $mail->Username);
        $this->assertSame('', $mail->Password);
        $this->assertContains(array('setFrom', 'noreply@sdca.edu.ph', 'ARISE Campus Navigator'), $mail->calls);
    }

    public function testABlankSettingIsKeptNotReplacedByTheFallback()
    {
        // Same as the old Cron_API: only a missing key falls back.
        $mailer = $this->mailer(array('SMTP_HOST' => ''));

        $mailer->send('ana@sdca.edu.ph', 'S', 'B');

        $this->assertSame('', $mailer->mail->Host);
    }
}
