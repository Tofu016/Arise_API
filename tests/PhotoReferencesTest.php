<?php
use PHPUnit\Framework\TestCase;

class PhotoReferencesTest extends TestCase
{
    private $tables = array();
    private $asked = array();

    protected function setUp(): void
    {
        $this->tables = array();
        $this->asked = array();
    }

    // A reader over in-memory rows: table => list of rows.
    private function store()
    {
        return new Photo_references(array('reader' => function ($table, array $columns) {
            $this->asked[$table] = $columns;
            return isset($this->tables[$table]) ? $this->tables[$table] : array();
        }));
    }

    public function testNothingReferencedWhenTablesAreEmpty()
    {
        $this->assertSame(array(), $this->store()->referencedPaths());
    }

    public function testCollectsPathsFromEveryDeclaredTable()
    {
        $this->tables = array(
            'nodes' => array(array('photo_path' => 'panoramas/gd1/a.jpg')),
            'tour_stops' => array(array('photo_path' => 'tourpanorama/b.jpg')),
            'tour_sections' => array(array('cover_photo_path' => 'tourcover/c.jpg')),
            'tour_stop_marker_photos' => array(array('photo_path' => 'tourmarker/d.jpg')),
            'placard_dialogs' => array(array('photo_path' => 'roomphoto/gd1/e.jpg', 'photo_360_path' => 'room360/gd1/f.webp')),
        );

        $paths = array_keys($this->store()->referencedPaths());
        sort($paths);

        $this->assertSame(array(
            'panoramas/gd1/a.jpg',
            'room360/gd1/f.webp',
            'roomphoto/gd1/e.jpg',
            'tourcover/c.jpg',
            'tourmarker/d.jpg',
            'tourpanorama/b.jpg',
        ), $paths);
    }

    public function testReturnsASetKeyedByPath()
    {
        $this->tables = array('nodes' => array(array('photo_path' => 'panoramas/gd1/a.jpg')));

        $this->assertSame(array('panoramas/gd1/a.jpg' => true), $this->store()->referencedPaths());
    }

    public function testBlankAndNullValuesAreNotReferences()
    {
        $this->tables = array(
            'nodes' => array(array('photo_path' => ''), array('photo_path' => null), array('photo_path' => 'panoramas/gd1/a.jpg')),
            'placard_dialogs' => array(array('photo_path' => 'roomphoto/gd1/e.jpg', 'photo_360_path' => '')),
        );

        $this->assertSame(array('panoramas/gd1/a.jpg', 'roomphoto/gd1/e.jpg'), array_keys($this->store()->referencedPaths()));
    }

    public function testASharedPathIsListedOnce()
    {
        $this->tables = array(
            'nodes' => array(array('photo_path' => 'panoramas/gd1/a.jpg')),
            'tour_stops' => array(array('photo_path' => 'panoramas/gd1/a.jpg')),
        );

        $this->assertSame(array('panoramas/gd1/a.jpg'), array_keys($this->store()->referencedPaths()));
    }

    public function testAsksTheReaderForExactlyTheDeclaredTablesAndColumns()
    {
        $this->store()->referencedPaths();

        $this->assertSame(Photo_references::SOURCES, $this->asked);
    }

    public function testReadsFreshOnEveryCallSoAJustAttachedPhotoIsSeen()
    {
        $store = $this->store();
        $this->assertSame(array(), $store->referencedPaths());

        // Another admin attaches a photo between two calls.
        $this->tables['nodes'] = array(array('photo_path' => 'panoramas/gd1/a.jpg'));

        $this->assertArrayHasKey('panoramas/gd1/a.jpg', $store->referencedPaths());
    }

    // ---- drift guard: SOURCES must match schema.sql ---------------

    // table => [columns], parsed from the CREATE TABLE blocks.
    private function schemaColumns()
    {
        $tables = array();
        $current = null;
        foreach (file(__DIR__ . '/../schema.sql') as $line) {
            $line = rtrim($line);
            if (preg_match('/^CREATE TABLE `([^`]+)`/', $line, $m)) {
                $current = $m[1];
                $tables[$current] = array();
            } elseif ($current !== null && preg_match('/^\)/', $line)) {
                $current = null;
            } elseif ($current !== null && preg_match('/^\s*`([^`]+)`\s/', $line, $m)) {
                $tables[$current][] = $m[1];
            }
        }
        return $tables;
    }

    public function testTheSchemaParserFindsTheTablesItShould()
    {
        $schema = $this->schemaColumns();

        $this->assertArrayHasKey('nodes', $schema);
        $this->assertContains('photo_path', $schema['nodes']);
        $this->assertGreaterThan(10, count($schema));
    }

    public function testEveryPhotoColumnInTheSchemaIsDeclared()
    {
        $undeclared = array();
        foreach ($this->schemaColumns() as $table => $columns) {
            foreach ($columns as $column) {
                $declared = isset(Photo_references::SOURCES[$table]) && in_array($column, Photo_references::SOURCES[$table], true);
                if (stripos($column, 'photo') !== false && !$declared) {
                    $undeclared[] = "{$table}.{$column}";
                }
            }
        }

        $this->assertSame(array(), $undeclared, 'Photo columns in schema.sql that Photo_references::SOURCES does not list — an in-use photo could be offered for deletion.');
    }

    public function testEveryDeclaredColumnExistsInTheSchema()
    {
        $schema = $this->schemaColumns();
        $missing = array();
        foreach (Photo_references::SOURCES as $table => $columns) {
            foreach ($columns as $column) {
                if (!isset($schema[$table]) || !in_array($column, $schema[$table], true)) {
                    $missing[] = "{$table}.{$column}";
                }
            }
        }

        $this->assertSame(array(), $missing, 'Declared in Photo_references::SOURCES but not in schema.sql.');
    }
}
