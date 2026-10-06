<?php
defined('BASEPATH') or exit('No direct script access allowed');

// The web app sidebar Directory's visibility settings: one row (id 1) with a
// JSON document. Campuses and buildings are stored as "hidden" lists, so one
// created later appears by default. A building's rooms are stored per
// building as { incoming, listed, removed }; see Directory_API::settings.
class Directory_Model extends CI_Model
{
    private $table = 'directory_settings';

    // A missing row is the normal state of a fresh install, not an error.
    const DEFAULT_CONFIG = array(
        'show_saved' => true,
        'hidden_campuses' => array(),
        'hidden_buildings' => array(),
        'building_rooms' => array(),
        // Buildings the sidebar opens expanded (a solo campus counts as its one building).
        'expanded_buildings' => array(),
    );

    public function __construct()
    {
        parent::__construct();
        $this->load->database();
    }

    public function getConfig()
    {
        $this->db->select('config');
        $this->db->from($this->table);
        $this->db->where('id', 1);
        $row = $this->db->get()->row_array();
        $stored = $row ? json_decode($row['config'], true) : null;
        return array_merge(self::DEFAULT_CONFIG, is_array($stored) ? $stored : array());
    }

    // Upserts the single row, filling unsent fields from what is stored so a
    // partial save never drops the rest.
    public function updateConfig(array $fields)
    {
        $config = array_merge($this->getConfig(), $fields);
        $this->db->replace($this->table, array(
            'id' => 1,
            'config' => json_encode($config),
            'updated_at' => date('Y-m-d H:i:s'),
        ));
        return $this->getConfig();
    }
}
