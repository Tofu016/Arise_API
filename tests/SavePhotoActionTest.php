<?php
use PHPUnit\Framework\TestCase;

// A controller whose Photo store points at a temp directory, so
// MY_Controller::savePhoto() — the glue between the store and the reply —
// runs for real against the request globals.
class PhotoActionController extends MY_Controller
{
    public $store;

    public function __construct()
    {
    }

    protected function photoStore()
    {
        return $this->store;
    }

    public function uploadCover()
    {
        return $this->savePhoto('tourcover');
    }

    public function uploadPanorama($building)
    {
        return $this->savePhoto('panoramas', $building);
    }
}

class SavePhotoActionTest extends TestCase
{
    const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';

    private $base;
    private $controller;

    protected function setUp(): void
    {
        $this->base = sys_get_temp_dir() . '/savephoto-' . uniqid();
        mkdir($this->base . '/incoming', 0777, true);

        $this->controller = new PhotoActionController();
        $this->controller->store = new Photo_store(array(
            'public_root' => $this->base . '/public',
            'protected_root' => $this->base . '/protected',
            'mover' => function ($tmp, $target) {
                return copy($tmp, $target);
            },
        ));

        $_POST = array();
        $_FILES = array();
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['CONTENT_LENGTH'] = '100';
        $GLOBALS['logged_messages'] = array();
    }

    protected function tearDown(): void
    {
        $_POST = array();
        $_FILES = array();
        unset($_SERVER['CONTENT_LENGTH']);
        $this->removeTree($this->base);
    }

    private function attach($contents, $error = UPLOAD_ERR_OK)
    {
        $tmp = $this->base . '/incoming/' . uniqid();
        file_put_contents($tmp, $contents);
        $_FILES['file'] = array('name' => 'upload.png', 'tmp_name' => $tmp, 'error' => $error, 'size' => strlen($contents));
    }

    private function removeTree($dir)
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                $path = $dir . '/' . $entry;
                is_dir($path) ? $this->removeTree($path) : unlink($path);
            }
        }
        rmdir($dir);
    }

    public function testASavedPhotoIsRepliedAsSuccessWithItsPath()
    {
        $this->attach(base64_decode(self::PNG));
        $_POST['filename'] = 'library';

        $r = $this->controller->uploadCover();

        $this->assertSame(200, $r->status());
        $this->assertSame('{"success":true,"path":"tourcover\/library.png"}', $r->json());
        $this->assertFileExists($this->base . '/public/tourcover/library.png');
    }

    public function testTheBuildingIsPassedThroughForPerBuildingCategories()
    {
        $this->attach(base64_decode(self::PNG));
        $_POST['filename'] = 'lobby';

        $r = $this->controller->uploadPanorama('gd1');

        $this->assertSame('panoramas/gd1/lobby.png', $r->body()['path']);
    }

    public function testAMissingBuildingIsRepliedAs400()
    {
        $this->attach(base64_decode(self::PNG));

        $r = $this->controller->uploadPanorama(null);

        $this->assertSame(400, $r->status());
        $this->assertSame('Invalid or missing building.', $r->body()['error']);
    }

    public function testAFileThatIsNotAnImageIsRepliedAs400()
    {
        $this->attach('<?php echo 1;');

        $r = $this->controller->uploadCover();

        $this->assertSame(400, $r->status());
        $this->assertSame('{"success":false,"error":"The uploaded file is not a valid, recognized image."}', $r->json());
    }

    public function testARequestPhpDiscardedIsRepliedAs413()
    {
        // post_max_size blown: bytes were sent, but $_POST and $_FILES are empty.
        $_SERVER['CONTENT_LENGTH'] = (string) (50 * 1048576);

        $r = $this->controller->uploadCover();

        $this->assertSame(413, $r->status());
        $this->assertStringContainsString('too large', $r->body()['error']);
    }

    public function testAServerSideFailureIsLoggedAndNotExplainedToTheAdmin()
    {
        $this->attach('x', UPLOAD_ERR_CANT_WRITE);

        $r = $this->controller->uploadCover();

        $this->assertSame(500, $r->status());
        $this->assertStringNotContainsString('code', $r->body()['error']);
        $this->assertCount(1, $GLOBALS['logged_messages']);
        $this->assertSame('error', $GLOBALS['logged_messages'][0][0]);
        $this->assertStringContainsString('error code ' . UPLOAD_ERR_CANT_WRITE, $GLOBALS['logged_messages'][0][1]);
    }

    public function testAnOrdinaryFailureIsNotLogged()
    {
        $this->attach('<?php echo 1;');

        $this->controller->uploadCover();

        $this->assertSame(array(), $GLOBALS['logged_messages']);
    }
}
