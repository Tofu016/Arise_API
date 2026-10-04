<?php
defined('BASEPATH') or exit('No direct script access allowed');

require_once APPPATH . 'libraries/Kiosk_rules.php';

// Kiosks: physical devices an admin registers, ties to a map node, and
// pairs once with a short-lived code (see Kiosk_rules).
//
// Admin: getAll, create, update, resetPairing, delete.
// The kiosk itself, with the X-Kiosk-Token header it was given at pairing:
//   pair    public, redeems a code for a token (rate-limited by IP)
//   me      who this kiosk is and where it stands; doubles as the heartbeat
//   unpair  forgets this device
// An unknown or revoked token is a plain 401 the web app treats as "not
// paired", never something to show a visitor.
class Kiosks_API extends MY_Controller
{
    public function __construct()
    {
        parent::__construct();
        $this->load->model('Kiosks_Model');
    }

    // GET /Kiosks_API/getAll: admin only.
    public function getAll()
    {
        $this->requireAdmin();
        return Api_response::ok(array('kiosks' => $this->Kiosks_Model->getAll(), 'server_time' => date('Y-m-d H:i:s')));
    }

    // POST /Kiosks_API/create: admin only. Body: name, node_id (optional).
    // Replies with the kiosk and its pairing code, shown once: only the
    // code's hash is stored.
    public function create()
    {
        $this->requireAdmin();

        $fields = Kiosk_rules::kioskFields($this->getInput(), true);
        $code = Kiosk_rules::generateCode();
        $kiosk = $this->Kiosks_Model->create($fields, Kiosk_rules::hash($code), Kiosk_rules::codeExpiry(date('Y-m-d H:i:s')));
        return Api_response::ok(array('kiosk' => $kiosk, 'pairing_code' => $code));
    }

    // PATCH /Kiosks_API/update/{id}: admin only. Any subset of name, node_id.
    public function update($id = null)
    {
        $this->requireAdmin();
        Api_input::requireId($id, 'kiosk');

        if ($this->Kiosks_Model->find($id) === null) {
            return Api_response::fail(404, 'Kiosk not found.');
        }
        $fields = Kiosk_rules::kioskFields($this->getInput(), false);
        if (empty($fields)) {
            return Api_response::fail(400, 'No valid fields to update.');
        }
        return Api_response::ok(array('kiosk' => $this->Kiosks_Model->update($id, $fields)));
    }

    // POST /Kiosks_API/resetPairing/{id}: admin only. Revokes the device's
    // token and issues a fresh code (shown once, like create's).
    public function resetPairing($id = null)
    {
        $this->requireAdmin();
        Api_input::requireId($id, 'kiosk');

        if ($this->Kiosks_Model->find($id) === null) {
            return Api_response::fail(404, 'Kiosk not found.');
        }
        $code = Kiosk_rules::generateCode();
        $kiosk = $this->Kiosks_Model->issueCode($id, Kiosk_rules::hash($code), Kiosk_rules::codeExpiry(date('Y-m-d H:i:s')));
        return Api_response::ok(array('kiosk' => $kiosk, 'pairing_code' => $code));
    }

    // DELETE /Kiosks_API/delete/{id}: admin only. Its token stops working.
    public function delete($id = null)
    {
        $this->requireAdmin();
        Api_input::requireId($id, 'kiosk');

        if ($this->Kiosks_Model->find($id) === null) {
            return Api_response::fail(404, 'Kiosk not found.');
        }
        $this->Kiosks_Model->delete($id);
        return Api_response::ok();
    }

    // POST /Kiosks_API/pair: public. Body: code. Replies { token, kiosk }.
    // The same error for a wrong and an expired code, and a lockout after
    // MAX_FAILURES misses per IP, so the 8 digits cannot be brute-forced.
    public function pair()
    {
        $now = date('Y-m-d H:i:s');
        $ip = $this->clientIp();
        $since = date('Y-m-d H:i:s', strtotime($now . ' -' . Kiosk_rules::FAILURE_WINDOW_MINUTES . ' minutes'));
        if ($this->Kiosks_Model->recentFailures($ip, $since) >= Kiosk_rules::MAX_FAILURES) {
            return Api_response::fail(429, 'Too many attempts. Try again in a few minutes.');
        }

        $data = $this->getInput();
        $code = Kiosk_rules::normalizeCode(isset($data['code']) ? $data['code'] : '');
        $token = Kiosk_rules::generateToken();
        $kiosk = strlen($code) === Kiosk_rules::CODE_LENGTH
            ? $this->Kiosks_Model->redeemCode(Kiosk_rules::hash($code), Kiosk_rules::hash($token), $now)
            : null;

        if ($kiosk === null) {
            $this->Kiosks_Model->recordFailure($ip, $now);
            return Api_response::fail(400, 'That code is not valid, or it has expired.');
        }
        return Api_response::ok(array('token' => $token, 'kiosk' => $this->publicKiosk($kiosk)));
    }

    // GET /Kiosks_API/me: the kiosk token's own kiosk, and the heartbeat.
    public function me()
    {
        $kiosk = $this->requireKiosk();
        $this->Kiosks_Model->touch($kiosk['id'], date('Y-m-d H:i:s'));
        return Api_response::ok(array('kiosk' => $this->publicKiosk($kiosk)));
    }

    // POST /Kiosks_API/unpair: the kiosk token's own kiosk forgets this device.
    public function unpair()
    {
        $kiosk = $this->requireKiosk();
        $this->Kiosks_Model->clearToken($kiosk['id']);
        return Api_response::ok();
    }

    // What a kiosk may know about itself: no pairing or admin bookkeeping.
    protected function publicKiosk(array $kiosk)
    {
        return array('id' => $kiosk['id'], 'name' => $kiosk['name'], 'node_id' => $kiosk['node_id']);
    }

    protected function requireKiosk()
    {
        $token = $this->input->get_request_header('X-Kiosk-Token');
        $kiosk = is_string($token) && $token !== ''
            ? $this->Kiosks_Model->findByToken(Kiosk_rules::hash($token))
            : null;
        if ($kiosk === null) {
            throw new Api_abort(Api_response::fail(401, 'This device is not paired.'));
        }
        return $kiosk;
    }
}
