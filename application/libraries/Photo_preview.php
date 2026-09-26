<?php
defined('BASEPATH') or exit('No direct script access allowed');

// A downscaled JPEG copy of a Photo, for the mobile app: its 3D viewers
// (expo-gl, ViroReact) are only ever handed JPEG, and a full-size
// 6080x3040 original is more than a phone should download or decode for
// one view. The client picks the width from WIDTHS — a fixed list, so a
// request can't make the server convert and cache arbitrary sizes.
//
// Every source format is handled the same way, JPEG included: a JPEG
// original that is wider than max_width is just as slow to decode as a
// WebP one would be. Converted copies are cached on disk, so GD only
// ever does the (memory- and CPU-heavy) work once per file version.
//
// Free of CodeIgniter dependencies, like Photo_store: the caller resolves
// the Photo path to a file first and hands over the full path.
class Photo_preview
{
    // The widths a client may ask for. 1024 is the default, for clients
    // that don't ask (older app versions, the AR screens).
    const WIDTHS = array(1024, 2048, 4096);
    const DEFAULT_MAX_WIDTH = 1024;
    const QUALITY = 85;

    private $cacheRoot;
    private $maxWidth;

    // $config:
    //   cache_root — absolute directory for converted copies (required).
    //                Created on first use.
    //   max_width  — optional, pixels; defaults to DEFAULT_MAX_WIDTH.
    public function __construct(array $config)
    {
        $this->cacheRoot = rtrim($config['cache_root'], '/\\') . '/';
        $this->maxWidth = isset($config['max_width']) ? (int) $config['max_width'] : self::DEFAULT_MAX_WIDTH;
    }

    // $requested (a query-string value, or null) as an allowed width, or
    // null when it isn't one of WIDTHS.
    public static function allowedWidth($requested)
    {
        if ($requested === null || $requested === '') {
            return self::DEFAULT_MAX_WIDTH;
        }
        if (!ctype_digit((string) $requested)) {
            return null;
        }
        return in_array((int) $requested, self::WIDTHS, true) ? (int) $requested : null;
    }

    // The JPEG to send for the image at $sourcePath, at most $maxWidth
    // wide (defaults to the configured max_width).
    // Returns array('ok' => true, 'full_path' => string) — either the
    // source itself (already a JPEG no wider than that) or a cached copy —
    // or array('ok' => false, 'reason' => 'unsupported') when this
    // server's GD can't read the file, in which case the caller should
    // fall back to the original.
    public function jpegFor($sourcePath, $maxWidth = null)
    {
        $maxWidth = $maxWidth !== null ? (int) $maxWidth : $this->maxWidth;

        $info = @getimagesize($sourcePath);
        if ($info === false) {
            return $this->unsupported();
        }
        list($width, , ) = $info;
        $mime = $info['mime'];

        if ($mime === 'image/jpeg' && $width <= $maxWidth) {
            return array('ok' => true, 'full_path' => $sourcePath);
        }

        $cached = $this->cacheRoot . $this->cacheKey($sourcePath, $maxWidth) . '.jpg';
        if (is_file($cached)) {
            return array('ok' => true, 'full_path' => $cached);
        }

        $image = $this->decode($sourcePath, $mime);
        if ($image === false) {
            return $this->unsupported();
        }
        $image = $this->downscale($image, $maxWidth);

        if (!is_dir($this->cacheRoot)) {
            @mkdir($this->cacheRoot, 0755, true);
        }
        // Written to a temp name and renamed into place, so a request
        // arriving mid-write never serves a half-written file.
        $tmp = $cached . '.' . uniqid('', true) . '.tmp';
        $written = imagejpeg($image, $tmp, self::QUALITY);
        imagedestroy($image);
        if (!$written || !@rename($tmp, $cached)) {
            @unlink($tmp);
            return $this->unsupported();
        }

        return array('ok' => true, 'full_path' => $cached);
    }

    // Changes whenever the source file is replaced (new mtime or size) or
    // the target width changes, so a stale copy is never served.
    private function cacheKey($sourcePath, $maxWidth)
    {
        return sha1(implode('|', array(
            realpath($sourcePath),
            filemtime($sourcePath),
            filesize($sourcePath),
            $maxWidth,
        )));
    }

    private function decode($path, $mime)
    {
        $readers = array(
            'image/jpeg' => 'imagecreatefromjpeg',
            'image/png' => 'imagecreatefrompng',
            'image/gif' => 'imagecreatefromgif',
            'image/webp' => 'imagecreatefromwebp',
        );
        if (!isset($readers[$mime]) || !function_exists($readers[$mime]) || !function_exists('imagejpeg')) {
            return false;
        }
        return @call_user_func($readers[$mime], $path);
    }

    private function downscale($image, $maxWidth)
    {
        $width = imagesx($image);
        $height = imagesy($image);
        if ($width <= $maxWidth) {
            return $image;
        }

        $targetHeight = max(1, (int) round($height * ($maxWidth / $width)));
        $resized = imagecreatetruecolor($maxWidth, $targetHeight);
        imagecopyresampled($resized, $image, 0, 0, 0, 0, $maxWidth, $targetHeight, $width, $height);
        imagedestroy($image);
        return $resized;
    }

    private function unsupported()
    {
        return array('ok' => false, 'reason' => 'unsupported');
    }
}
