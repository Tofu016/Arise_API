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

    // Sends the oldest waiting emails (at most $limit) through $mailer —
    // anything with send($to, $subject, $bodyHtml) that throws when a
    // message cannot be sent (see Smtp_mailer). A row is marked sent only
    // once its send succeeded; a failure leaves it waiting for the next
    // run, deliberately, rather than silently losing it. Returns
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
    public function getPending($limit = 20)
    {
        $this->db->select('*');
        $this->db->from('email_queue');
        $this->db->where('sent_at', null);
        $this->db->order_by('created_at', 'ASC');
        $this->db->limit($limit);
        $query = $this->db->get();
        return $query->result_array();
    }

    public function markSent($id)
    {
        $this->db->where('id', $id);
        return $this->db->update('email_queue', array(
            'sent_at' => date('Y-m-d H:i:s'),
        ));
    }
}
