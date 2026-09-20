<?php
// Stands in for Smtp_mailer: records every message it is asked to send, and
// throws for the addresses listed in $failFor, the way a real send fails.
class FakeMailer
{
    public $sent = array();
    public $failFor = array();

    public function __construct(array $failFor = array())
    {
        $this->failFor = $failFor;
    }

    public function send($toEmail, $subject, $bodyHtml)
    {
        if (in_array($toEmail, $this->failFor, true)) {
            throw new RuntimeException("SMTP Error: could not deliver to {$toEmail}");
        }
        $this->sent[] = array($toEmail, $subject, $bodyHtml);
    }
}
