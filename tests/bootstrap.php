<?php
// Tests load modules directly — no CodeIgniter boot, no database, no
// HTTP. BASEPATH and APPPATH are only defined so the "no direct script
// access" guards and require_once paths in application files resolve.
define('BASEPATH', __DIR__ . '/../system/');
define('APPPATH', __DIR__ . '/../application/');

require_once __DIR__ . '/../vendor/autoload.php';
require_once APPPATH . 'libraries/Photo_store.php';
require_once APPPATH . 'libraries/Api_response.php';
require_once APPPATH . 'libraries/Photo_references.php';
require_once APPPATH . 'libraries/Api_input.php';
require_once APPPATH . 'libraries/Account_policy.php';
require_once APPPATH . 'libraries/Neighbor_links.php';
require_once APPPATH . 'libraries/Account_mail.php';
require_once APPPATH . 'libraries/Smtp_mailer.php';

// MY_Controller extends CI_Controller and calls show_404(). Stubbed so its
// own guard and dispatch code can be tested without booting the framework;
// tests build controllers that skip its (CI-dependent) constructor.
class CI_Controller
{
    // A public method a real controller inherits from CI, which must not
    // become reachable from a URL.
    public function ciInherited()
    {
    }
}

class Show404Called extends RuntimeException
{
}

function show_404()
{
    throw new Show404Called('show_404');
}

// CI's log_message(), recording instead of writing to application/logs.
$GLOBALS['logged_messages'] = array();

function log_message($level, $message)
{
    $GLOBALS['logged_messages'][] = array($level, $message);
}

require_once APPPATH . 'core/MY_Controller.php';

// Controllers whose actions are tested directly (see ActionTestCase).
foreach (array('Auth', 'Nodes', 'TourStops', 'Buildings', 'Users', 'TourSections', 'PlacardDialogs') as $controller) {
    require_once APPPATH . "controllers/{$controller}_API.php";
}
// Models extend CI_Model; stubbed so their methods can be called with a
// FakeDb assigned in place of the real query builder (constructors, which
// load the database, are skipped by the test harness classes).
class CI_Model
{
}

require_once APPPATH . 'models/Email_Model.php';
require_once APPPATH . 'models/Nodes_Model.php';
require_once APPPATH . 'models/TourStops_Model.php';
require_once __DIR__ . '/support/FakeDb.php';
require_once __DIR__ . '/support/FakeMailer.php';
require_once __DIR__ . '/support/ModelHarnesses.php';
require_once __DIR__ . '/support/FakeModel.php';
require_once __DIR__ . '/support/ActionTestCase.php';
