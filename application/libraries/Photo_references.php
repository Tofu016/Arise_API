<?php
defined('BASEPATH') or exit('No direct script access allowed');

// Which Photo paths the database currently references — the definition
// of an "in-use photo". The admin gallery uses it to flag files, and
// Photos_API::delete uses it to refuse deleting one. Getting this wrong
// in the "missing column" direction means a still-in-use photo gets
// offered for deletion, so the knowledge of WHERE photo paths are stored
// lives here, in one place, and nowhere else.
//
// SOURCES lists every Photo column. The convention is that a column
// holding a Photo path has "photo" in its name; a test parses schema.sql
// and fails if a column named that way is not declared here (or if this
// declares one the schema no longer has), so a new photo column cannot
// be forgotten silently. A photo column named without "photo" would slip
// past that test — don't.
//
// Free of CodeIgniter dependencies, like Photo_store: the database is
// reached through an injected reader.
class Photo_references
{
    // table => the columns in it that store a Photo path.
    const SOURCES = array(
        'nodes' => array('photo_path'),
        'tour_stops' => array('photo_path'),
        'placard_dialogs' => array('photo_path', 'photo_360_path'),
        'tour_sections' => array('cover_photo_path'),
        'tour_stop_marker_photos' => array('photo_path'),
    );

    private $reader;

    // $config['reader'] — callable($table, array $columns): array of
    // associative rows holding at least those columns.
    public function __construct(array $config)
    {
        $this->reader = $config['reader'];
    }

    // Every referenced Photo path exactly as stored (e.g.
    // "panoramas/gd1/lobby.webp"), as a set: array(path => true). Blank
    // and null values are not references. Read fresh on every call and
    // never cached — Photos_API::delete depends on that, since another
    // admin may have attached a photo since the gallery last loaded.
    public function referencedPaths()
    {
        $referenced = array();

        foreach (self::SOURCES as $table => $columns) {
            foreach (call_user_func($this->reader, $table, $columns) as $row) {
                foreach ($columns as $column) {
                    if (isset($row[$column]) && $row[$column] !== '') {
                        $referenced[(string) $row[$column]] = true;
                    }
                }
            }
        }

        return $referenced;
    }
}
