<?php
defined('BASEPATH') or exit('No direct script access allowed');

// The outgoing-email queue. Requests only ever enqueue() — an SMTP call
// must never block or fail the request that triggered it. A separate
// periodic job (Cron_API::processEmails) calls deliverPending() to
// actually send what is waiting.
class Email_Model extends CI_Model
{
    public function __construct()
    {
        parent::__construct();
        $this->load->database();
    }

    public function enqueue($toEmail, $subject, $bodyHtml)
    {
        $this->db->insert('email_queue', array(
            'to_email' => $toEmail,
            'subject' => $subject,
            'body_html' => $bodyHtml,
        ));
    }

    // Sends the waiting emails (at most $limit) through $mailer — anything
    // with send($to, $subject, $bodyHtml) that throws when a message cannot
    // be sent (see Smtp_mailer). A row is marked sent only once its send
    // succeeded. A failure never abandons the email: it stays waiting for
    // the next run with its attempt count raised and the reason recorded
    // (attempts, last_error), so a stuck email is visible and diagnosable
    // rather than silently lost. Returns
    //   array('sent'   => list of email ids,
    //         'failed' => list of array('id', 'to', 'error')).
    public function deliverPending($mailer, $limit = 20)
    {
        $sent = array();
        $failed = array();

        foreach ($this->getPending($limit) as $row) {
            try {
                $mailer->send($row['to_email'], $row['subject'], $row['body_html']);
            } catch (Exception $e) {
                $this->recordFailure($row['id'], $e->getMessage());
                $failed[] = array('id' => $row['id'], 'to' => $row['to_email'], 'error' => $e->getMessage());
                continue;
            }
            $this->markSent($row['id']);
            $sent[] = $row['id'];
        }

        return array('sent' => $sent, 'failed' => $failed);
    }

    // Batch-limited on purpose — a single run shouldn't try to blast
    // through an unbounded backlog if the job hasn't run in a while for
    // some reason (the SMTP server going down, the task getting
    // disabled, etc.). Runs again on the next scheduled tick regardless.
    //
    // Ordered by attempts first, so an email that keeps failing sinks
    // behind everything not yet tried. Without that, enough undeliverable
    // rows at the front (a bad address, say) would fill every batch and
    // starve every email behind them. Nothing is ever given up on: with
    // spare room in the batch they are simply tried again.
    private function getPending($limit = 20)
    {
        $this->db->select('*');
        $this->db->from('email_queue');
        $this->db->where('sent_at', null);
        $this->db->order_by('attempts', 'ASC');
        $this->db->order_by('created_at', 'ASC');
        $this->db->limit($limit);
        $query = $this->db->get();
        return $query->result_array();
    }

    // Counts one more failed attempt (atomically, in SQL) and keeps the
    // latest reason, cut to what the last_error column holds.
    private function recordFailure($id, $error)
    {
        $error = (string) $error;
        $this->db->set('attempts', 'attempts + 1', false);
        $this->db->set('last_error', function_exists('mb_substr') ? mb_substr($error, 0, 500) : substr($error, 0, 500));
        $this->db->where('id', $id);
        $this->db->update('email_queue');
    }

    private function markSent($id)
    {
        $this->db->where('id', $id);
        return $this->db->update('email_queue', array(
            'sent_at' => date('Y-m-d H:i:s'),
        ));
    }
}
