<?php
defined('BASEPATH') or exit('No direct script access allowed');

// The emails an account receives, written once. Each returns
// array('subject' => ..., 'html' => ...). The recipient's name is
// user-supplied, so it is always escaped before it goes into HTML.
//
// Pure, like Account_policy: nothing here sends anything (see
// MY_Controller::sendMail).
class Account_mail
{
    // Sent right after registration: the account exists but is pending.
    public static function welcome($name)
    {
        return array(
            'subject' => 'Welcome to ARISE Campus Navigator',
            'html' => '<p>Hi ' . htmlspecialchars($name) . ',</p><p>Thanks for registering. Your account is pending approval. You will receive another email once an administrator approves it.</p>',
        );
    }

    // Sent when an administrator approves a pending account.
    public static function accountApproved($name)
    {
        return array(
            'subject' => 'Your ARISE Campus Navigator account has been approved',
            'html' => '<p>Hi ' . htmlspecialchars($name) . ',</p><p>Your account has been approved. You can now sign in to ARISE Campus Navigator.</p>',
        );
    }

    // $baseUrl is the web app's own address (scheme + host, with or
    // without a trailing slash); /reset-password is that app's route for
    // the reset page.
    public static function passwordReset($name, $baseUrl, $token)
    {
        $link = rtrim($baseUrl, '/') . '/reset-password?token=' . urlencode($token);

        return array(
            'subject' => 'Reset your ARISE Campus Navigator password',
            'html' => '<p>Hi ' . htmlspecialchars($name) . ',</p>'
                . '<p>Click the link below to reset your password. This link expires in 1 hour and can only be used once.</p>'
                . '<p><a href="' . htmlspecialchars($link) . '">' . htmlspecialchars($link) . '</a></p>'
                . '<p>If you did not request this, you can safely ignore this email.</p>',
        );
    }
}
