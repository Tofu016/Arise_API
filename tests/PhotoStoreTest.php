<?php
use PHPUnit\Framework\TestCase;

// Exercises the Photo store only through its interface, against a real
// temp directory — the mover copies instead of move_uploaded_file, which
// only works for genuine HTTP uploads.
class PhotoStoreTest extends TestCase
{
    const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';
    const GIF = 'R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7';

    private $base;
    private $store;

    protected function setUp(): void
    {
        $this->base = sys_get_temp_dir() . '/photostore-' . uniqid();
        mkdir($this->base . '/incoming', 0777, true);
        $this->store = $this->newStore();
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->base);
    }

    private function newStore(array $extra = array())
    {
        return new Photo_store(array_merge(array(
            'public_root' => $this->base . '/public',
            'protected_root' => $this->base . '/protected/',
            'mover' => function ($tmp, $target) {
                return copy($tmp, $target);
            },
            'limits' => array('post_max_size' => 8 * 1048576, 'upload_max_filesize' => 2 * 1048576),
        ), $extra));
    }

    private function upload($contents, $clientName = 'photo.png', $error = UPLOAD_ERR_OK)
    {
        $tmp = $this->base . '/incoming/' . uniqid();
        file_put_contents($tmp, $contents);
        return array(
            'file' => array('name' => $clientName, 'tmp_name' => $tmp, 'error' => $error, 'size' => strlen($contents)),
            'request_bytes' => strlen($contents),
            'body_discarded' => false,
        );
    }

    private function png($clientName = 'photo.png')
    {
        return $this->upload(base64_decode(self::PNG), $clientName);
    }

    private function removeTree($dir)
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            is_dir($path) ? $this->removeTree($path) : unlink($path);
        }
        rmdir($dir);
    }

    // ---- save: where files go -------------------------------------

    public function testSavesAFlatPublicPhotoUnderThePublicRoot()
    {
        $r = $this->store->save('tourcover', $this->png(), 'library');

        $this->assertTrue($r['ok']);
        $this->assertSame('tourcover/library.png', $r['path']);
        $this->assertFileExists($this->base . '/public/tourcover/library.png');
    }

    public function testSavesAPerBuildingProtectedPhotoUnderTheProtectedRoot()
    {
        $r = $this->store->save('panoramas', $this->png(), 'lobby', 'gd1');

        $this->assertTrue($r['ok']);
        $this->assertSame('panoramas/gd1/lobby.png', $r['path']);
        $this->assertFileExists($this->base . '/protected/panoramas/gd1/lobby.png');
    }

    public function testFallsBackToTheUploadedFilesOwnNameWhenNoNameIsGiven()
    {
        $r = $this->store->save('tourmarker', $this->png('from-client.png'));

        $this->assertSame('tourmarker/from-client.png', $r['path']);
    }

    public function testSavedExtensionComesFromTheVerifiedImageNotTheClientName()
    {
        $r = $this->store->save('tourcover', $this->png('shell.php'), 'shell.php');
        $this->assertSame('tourcover/shell.png', $r['path']);

        $gif = $this->upload(base64_decode(self::GIF), 'x.png');
        $r = $this->store->save('tourcover', $gif, 'anim.png');
        $this->assertSame('tourcover/anim.gif', $r['path']);
    }

    public function testRejectsAFileThatIsNotAnImage()
    {
        $r = $this->store->save('tourcover', $this->upload('<?php echo 1;', 'photo.jpg'), 'photo');

        $this->assertFalse($r['ok']);
        $this->assertSame(400, $r['status']);
        $this->assertStringContainsString('not a valid, recognized image', $r['error']);
        $this->assertFileDoesNotExist($this->base . '/public/tourcover/photo.jpg');
    }

    public function testReportsAFailedMove()
    {
        $store = $this->newStore(array('mover' => function () {
            return false;
        }));

        $r = $store->save('tourcover', $this->png(), 'x');

        $this->assertSame(500, $r['status']);
        $this->assertSame('Failed to save the uploaded file.', $r['error']);
    }

    // ---- save: traversal defences ---------------------------------

    /** @dataProvider unsafeNames */
    public function testRejectsUnsafeFilenames($name)
    {
        $r = $this->store->save('tourcover', $this->png(), $name);

        $this->assertFalse($r['ok']);
        $this->assertSame(400, $r['status']);
        $this->assertSame('Invalid filename.', $r['error']);
    }

    public function unsafeNames()
    {
        return array(
            'double dot' => array('..'),
            'single dot' => array('.'),
            'dots only' => array('...'),
            'space' => array('bad name'),
            'array' => array(array('x')),
            'empty' => array(''),
        );
    }

    /** @dataProvider unsafeBuildings */
    public function testRejectsUnsafeOrMissingBuildings($building)
    {
        $r = $this->store->save('panoramas', $this->png(), 'x', $building);

        $this->assertFalse($r['ok']);
        $this->assertSame('Invalid or missing building.', $r['error']);
    }

    public function unsafeBuildings()
    {
        return array(
            'missing' => array(null),
            'double dot' => array('..'),
            'slash' => array('a/b'),
            'backslash' => array('a\\b'),
            'empty' => array(''),
        );
    }

    public function testAFlatCategoryCannotTakeABuilding()
    {
        $this->expectException(InvalidArgumentException::class);
        $this->store->save('tourcover', $this->png(), 'x', 'gd1');
    }

    public function testAnUnknownCategoryIsAProgrammingError()
    {
        $this->expectException(InvalidArgumentException::class);
        $this->store->save('nonsense', $this->png(), 'x');
    }

    // ---- save: telling the admin what actually went wrong ---------

    public function testABodyPhpDiscardedIsReportedAsTooLargeNotMissing()
    {
        $incoming = array('file' => null, 'request_bytes' => 20 * 1048576, 'body_discarded' => true);

        $r = $this->store->save('tourcover', $incoming, 'x');

        $this->assertSame(413, $r['status']);
        $this->assertStringContainsString('20 MB', $r['error']);
        $this->assertStringContainsString('8 MB', $r['error']);
    }

    public function testAFileOverTheUploadLimitIsReportedWithTheLimit()
    {
        $incoming = $this->upload('x', 'p.png', UPLOAD_ERR_INI_SIZE);

        $r = $this->store->save('tourcover', $incoming, 'x');

        $this->assertSame(413, $r['status']);
        $this->assertStringContainsString('2 MB', $r['error']);
    }

    public function testNoFileAtAllIsA400()
    {
        $r = $this->store->save('tourcover', array('file' => null, 'request_bytes' => 0, 'body_discarded' => false), 'x');

        $this->assertSame(400, $r['status']);
        $this->assertStringContainsString('No file was attached', $r['error']);
    }

    public function testAPartialUploadIsA400()
    {
        $r = $this->store->save('tourcover', $this->upload('x', 'p.png', UPLOAD_ERR_PARTIAL), 'x');

        $this->assertSame(400, $r['status']);
        $this->assertStringContainsString('partially', $r['error']);
    }

    public function testServerSideUploadFailuresAreA500WithAPrivateLogNote()
    {
        $r = $this->store->save('tourcover', $this->upload('x', 'p.png', UPLOAD_ERR_NO_TMP_DIR), 'x');

        $this->assertSame(500, $r['status']);
        $this->assertStringNotContainsString('code', $r['error']);
        $this->assertStringContainsString('error code ' . UPLOAD_ERR_NO_TMP_DIR, $r['log']);
    }

    // ---- resolve --------------------------------------------------

    public function testResolvesASavedPhoto()
    {
        $this->store->save('panoramas', $this->png(), 'lobby', 'gd1');

        $r = $this->store->resolve('panoramas/gd1/lobby.png');

        $this->assertTrue($r['ok']);
        $this->assertSame('protected', $r['visibility']);
        $this->assertSame('panoramas', $r['category']);
        $this->assertSame('image/png', $r['content_type']);
        $this->assertFileExists($r['full_path']);
    }

    public function testResolveReportsPublicVisibilityForTourCategories()
    {
        $this->store->save('tourcover', $this->png(), 'c');

        $this->assertSame('public', $this->store->resolve('tourcover/c.png')['visibility']);
    }

    /** @dataProvider invalidPaths */
    public function testResolveRejectsPathsThatCouldEscapeOrDontMatchALayout($path)
    {
        $r = $this->store->resolve($path);

        $this->assertFalse($r['ok']);
        $this->assertSame('invalid_path', $r['reason']);
        $this->assertSame(400, $r['status']);
    }

    public function invalidPaths()
    {
        return array(
            'parent traversal' => array('../secret.png'),
            'traversal in building' => array('panoramas/../../secret.png'),
            'dots only' => array('panoramas/../..'),
            'unknown category' => array('etc/passwd'),
            'flat too deep' => array('tourcover/a/b.png'),
            'per-building too shallow' => array('panoramas/x.png'),
            'empty segment' => array('panoramas//x.png'),
            'empty' => array(''),
            'not a string' => array(null),
        );
    }

    public function testResolveReportsAMissingFileAsNotFound()
    {
        $r = $this->store->resolve('tourcover/missing.png');

        $this->assertFalse($r['ok']);
        $this->assertSame('not_found', $r['reason']);
        $this->assertSame(404, $r['status']);
    }

    // ---- remove ---------------------------------------------------

    public function testRemoveDeletesThePhotoFile()
    {
        $this->store->save('tourcover', $this->png(), 'gone');

        $this->assertTrue($this->store->remove('tourcover/gone.png')['ok']);
        $this->assertFileDoesNotExist($this->base . '/public/tourcover/gone.png');
        $this->assertSame('not_found', $this->store->remove('tourcover/gone.png')['reason']);
    }

    public function testRemoveRefusesAnInvalidPathAndTouchesNothing()
    {
        $outside = $this->base . '/secret.png';
        file_put_contents($outside, 'keep me');

        $r = $this->store->remove('tourcover/../../secret.png');

        $this->assertSame('invalid_path', $r['reason']);
        $this->assertFileExists($outside);
    }

    // ---- listPhotos -----------------------------------------------

    public function testListsPhotosFromEveryListedCategory()
    {
        $this->store->save('tourcover', $this->png(), 'c');
        $this->store->save('panoramas', $this->png(), 'p', 'gd1');
        $this->store->save('roomphoto', $this->png(), 'r', 'gd2');

        $photos = $this->store->listPhotos();
        $byPath = array();
        foreach ($photos as $p) {
            $byPath[$p['path']] = $p;
        }

        $this->assertSame(array('panoramas/gd1/p.png', 'roomphoto/gd2/r.png', 'tourcover/c.png'), $this->sortedKeys($byPath));
        $this->assertSame('public', $byPath['tourcover/c.png']['visibility']);
        $this->assertSame('protected', $byPath['panoramas/gd1/p.png']['visibility']);
        $this->assertGreaterThan(0, $byPath['tourcover/c.png']['size_bytes']);
        $this->assertGreaterThan(0, $byPath['tourcover/c.png']['modified_at']);
    }

    public function testDoesNotListPhotosInTheReviewHoldingArea()
    {
        $this->store->save('panoramas-review', $this->png(), 'pending', 'gd1');

        $this->assertSame(array(), $this->store->listPhotos());
        // ...but it is still an ordinary, resolvable Photo.
        $this->assertTrue($this->store->resolve('panoramas-review/gd1/pending.png')['ok']);
    }

    public function testListingAnEmptyStoreIsEmpty()
    {
        $this->assertSame(array(), $this->store->listPhotos());
    }

    private function sortedKeys(array $arr)
    {
        $keys = array_keys($arr);
        sort($keys);
        return $keys;
    }
}
