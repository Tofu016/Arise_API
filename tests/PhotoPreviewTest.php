<?php
use PHPUnit\Framework\TestCase;

// Exercises Photo_preview against real images generated with GD in a temp
// directory. max_width is small so the fixtures stay tiny.
class PhotoPreviewTest extends TestCase
{
    const MAX_WIDTH = 64;

    private $base;
    private $preview;

    protected function setUp(): void
    {
        if (!function_exists('imagecreatetruecolor') || !function_exists('imagewebp')) {
            $this->markTestSkipped('GD with WebP support is required.');
        }
        $this->base = sys_get_temp_dir() . '/photopreview-' . uniqid();
        mkdir($this->base . '/photos', 0777, true);
        $this->preview = new Photo_preview(array(
            'cache_root' => $this->base . '/cache',
            'max_width' => self::MAX_WIDTH,
        ));
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->base);
    }

    private function image($name, $width, $height, $writer)
    {
        $path = $this->base . '/photos/' . $name;
        $image = imagecreatetruecolor($width, $height);
        call_user_func($writer, $image, $path);
        imagedestroy($image);
        return $path;
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

    private function assertJpegOfSize($path, $width, $height)
    {
        $info = getimagesize($path);
        $this->assertSame('image/jpeg', $info['mime']);
        $this->assertSame(array($width, $height), array($info[0], $info[1]));
    }

    public function testASmallJpegIsServedAsIsWithoutCaching()
    {
        $source = $this->image('small.jpg', 40, 20, 'imagejpeg');

        $r = $this->preview->jpegFor($source);

        $this->assertTrue($r['ok']);
        $this->assertSame($source, $r['full_path']);
        $this->assertDirectoryDoesNotExist($this->base . '/cache');
    }

    public function testALargeJpegIsDownscaledKeepingItsAspectRatio()
    {
        $source = $this->image('wide.jpg', 256, 128, 'imagejpeg');

        $r = $this->preview->jpegFor($source);

        $this->assertTrue($r['ok']);
        $this->assertNotSame($source, $r['full_path']);
        $this->assertJpegOfSize($r['full_path'], 64, 32);
    }

    public function testASmallWebpIsConvertedToJpegAtItsOwnSize()
    {
        $source = $this->image('small.webp', 40, 20, 'imagewebp');

        $r = $this->preview->jpegFor($source);

        $this->assertTrue($r['ok']);
        $this->assertJpegOfSize($r['full_path'], 40, 20);
    }

    public function testALargePngIsConvertedAndDownscaled()
    {
        $source = $this->image('wide.png', 128, 64, 'imagepng');

        $r = $this->preview->jpegFor($source);

        $this->assertTrue($r['ok']);
        $this->assertJpegOfSize($r['full_path'], 64, 32);
    }

    public function testASecondRequestReusesTheCachedCopy()
    {
        $source = $this->image('wide.webp', 256, 128, 'imagewebp');
        $first = $this->preview->jpegFor($source);
        $modified = filemtime($first['full_path']);
        touch($first['full_path'], $modified - 100);

        $second = $this->preview->jpegFor($source);

        $this->assertSame($first['full_path'], $second['full_path']);
        clearstatcache();
        $this->assertSame($modified - 100, filemtime($second['full_path']), 'The cached copy was rewritten.');
    }

    public function testReplacingTheSourceProducesAFreshCopy()
    {
        $source = $this->image('wide.webp', 256, 128, 'imagewebp');
        $first = $this->preview->jpegFor($source);

        $this->image('wide.webp', 128, 128, 'imagewebp');
        touch($source, time() + 10);
        clearstatcache();
        $second = $this->preview->jpegFor($source);

        $this->assertNotSame($first['full_path'], $second['full_path']);
        $this->assertJpegOfSize($second['full_path'], 64, 64);
    }

    public function testAFileThatIsNotAnImageIsUnsupported()
    {
        $source = $this->base . '/photos/fake.jpg';
        file_put_contents($source, '<?php echo "not an image";');

        $r = $this->preview->jpegFor($source);

        $this->assertFalse($r['ok']);
        $this->assertSame('unsupported', $r['reason']);
    }

    public function testACallerCanAskForAWiderCopyThanTheDefault()
    {
        $source = $this->image('wide.webp', 256, 128, 'imagewebp');

        $r = $this->preview->jpegFor($source, 128);

        $this->assertJpegOfSize($r['full_path'], 128, 64);
    }

    public function testEachWidthIsCachedSeparately()
    {
        $source = $this->image('wide.webp', 256, 128, 'imagewebp');

        $small = $this->preview->jpegFor($source, 64);
        $large = $this->preview->jpegFor($source, 128);

        $this->assertNotSame($small['full_path'], $large['full_path']);
        $this->assertJpegOfSize($small['full_path'], 64, 32);
        $this->assertJpegOfSize($large['full_path'], 128, 64);
    }

    public function testAJpegWithinTheRequestedWidthIsServedAsIs()
    {
        $source = $this->image('mid.jpg', 100, 50, 'imagejpeg');

        $r = $this->preview->jpegFor($source, 128);

        $this->assertSame($source, $r['full_path']);
    }

    public function testOnlyTheListedWidthsAreAllowed()
    {
        $this->assertSame(Photo_preview::DEFAULT_MAX_WIDTH, Photo_preview::allowedWidth(null));
        $this->assertSame(Photo_preview::DEFAULT_MAX_WIDTH, Photo_preview::allowedWidth(''));
        foreach (Photo_preview::WIDTHS as $width) {
            $this->assertSame($width, Photo_preview::allowedWidth((string) $width));
        }
        foreach (array('3000', '999999', '-1024', '1024.0', ' 1024', 'abc', '0') as $bad) {
            $this->assertNull(Photo_preview::allowedWidth($bad), "accepted width '{$bad}'");
        }
    }

    public function testNoTemporaryFilesAreLeftInTheCache()
    {
        $source = $this->image('wide.png', 128, 64, 'imagepng');

        $this->preview->jpegFor($source);

        $this->assertEmpty(glob($this->base . '/cache/*.tmp'));
        $this->assertCount(1, glob($this->base . '/cache/*.jpg'));
    }
}
