<?php
defined('BASEPATH') or exit('No direct script access allowed');

require_once APPPATH . 'libraries/Photo_references.php';
require_once APPPATH . 'libraries/Photo_store.php';

// One-off photo maintenance from the command line.
//
//   php index.php Photos_CLI purgeRoomPhotos
//
// Deletes every roomphoto/ file that no database row references. Run once
// right after migrations/2026-10-15_room_photo_kinds.sql, which dropped every
// flat room photo reference; run later it would also delete a photo uploaded
// but not yet saved. Extends CI_Controller directly and refuses HTTP, like
// Admins_CLI.
class Photos_CLI extends CI_Controller
{
    public function __construct()
    {
        parent::__construct();
        if (!$this->input->is_cli_request()) {
            show_404();
        }
        $this->load->database();
    }

    public function purgeRoomPhotos()
    {
        $db = $this->db;
        $references = new Photo_references(array(
            'reader' => function ($table, array $columns) use ($db) {
                return $db->select(implode(', ', $columns))->get($table)->result_array();
            },
        ));
        $referenced = $references->referencedPaths();

        $protected = !empty($_ENV['PROTECTED_UPLOAD_ROOT'])
            ? $_ENV['PROTECTED_UPLOAD_ROOT']
            : dirname(FCPATH, 2) . '/protected-uploads/';
        $store = new Photo_store(array(
            'public_root' => !empty($_ENV['UPLOAD_ROOT']) ? $_ENV['UPLOAD_ROOT'] : FCPATH . 'uploads/',
            'protected_root' => $protected,
        ));

        $deleted = 0;
        foreach ($store->listPhotos() as $photo) {
            if (strpos($photo['path'], 'roomphoto/') !== 0 || isset($referenced[$photo['path']])) {
                continue;
            }
            $result = $store->remove($photo['path']);
            echo ($result['ok'] ? 'Deleted ' : 'Could not delete ') . $photo['path'] . PHP_EOL;
            $deleted += $result['ok'] ? 1 : 0;
        }
        echo "Done: {$deleted} file(s) deleted." . PHP_EOL;
    }
}
