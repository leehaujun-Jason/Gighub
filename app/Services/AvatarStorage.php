<?php
declare(strict_types=1);
namespace GigHub\Services;

use GdImage;
use RuntimeException;

final class AvatarStorage
{
    public function __construct(private readonly string $directory) {}

    private function path(string $key): string
    {
        if (!preg_match('/\A[a-f0-9]{64}\z/', $key)) {
            throw new RuntimeException('Invalid photo key.');
        }
        $directory = realpath($this->directory);
        $webroot = realpath(dirname(__DIR__, 3));
        $documentRoot = realpath($_SERVER['DOCUMENT_ROOT'] ?? '');
        if ($directory === false || $webroot === false || !is_dir($directory)) {
            throw new RuntimeException('Photo storage unavailable.');
        }
        $normalize = static fn (string $path): string => strtolower(rtrim(str_replace(chr(92), '/', $path), '/'));
        $resolved = $normalize($directory);
        $root = $normalize($webroot);
        if ($resolved === $root || str_starts_with($resolved, $root . '/')
            || ($documentRoot !== false && ($resolved === $normalize($documentRoot)
                || str_starts_with($resolved, $normalize($documentRoot) . '/')))
            || $resolved !== $normalize($this->directory)) {
            throw new RuntimeException('Photo storage unavailable.');
        }
        return $directory . DIRECTORY_SEPARATOR . $key . '.webp';
    }

    public function store(array $file): string
    {
        $path = $file['tmp_name'] ?? null;
        if (($file['error'] ?? null) !== UPLOAD_ERR_OK || !is_string($path) || !is_uploaded_file($path)) {
            throw new \InvalidArgumentException('Choose a JPEG, PNG, or WebP image up to 2 MB.');
        }
        $size = filesize($path);
        $info = $size !== false && $size > 0 && $size <= 2097152 ? @getimagesize($path) : false;
        if ($info === false || $info[0] < 1 || $info[1] < 1 || $info[0] * $info[1] > 12000000) {
            throw new \InvalidArgumentException('Choose a valid image up to 2 MB and 12 megapixels.');
        }
        $decode = match ($info[2]) {
            IMAGETYPE_JPEG => 'imagecreatefromjpeg',
            IMAGETYPE_PNG => 'imagecreatefrompng',
            IMAGETYPE_WEBP => 'imagecreatefromwebp',
            default => null,
        };
        if ($decode === null || !function_exists($decode) || !function_exists('imagewebp')) {
            throw new \InvalidArgumentException('Choose a supported JPEG, PNG, or WebP image.');
        }
        $source = @$decode($path);
        if (!$source instanceof GdImage) {
            throw new \InvalidArgumentException('This image could not be decoded.');
        }
        $scale = min(1, 512 / max($info[0], $info[1]));
        $image = imagescale($source, max(1, (int) ($info[0] * $scale)), max(1, (int) ($info[1] * $scale)));
        if (!$image instanceof GdImage || !imagepalettetotruecolor($image)) {
            throw new RuntimeException('Photo processing failed.');
        }
        imagealphablending($image, false);
        imagesavealpha($image, true);
        $key = bin2hex(random_bytes(32));
        $target = $this->path($key);
        $handle = @fopen($target, 'xb');
        if ($handle === false) {
            throw new RuntimeException('Photo storage unavailable.');
        }
        try {
            $written = @imagewebp($image, $handle, 85) && fflush($handle);
        } finally {
            fclose($handle);
        }
        $result = $written ? @getimagesize($target) : false;
        if ($result === false || $result[2] !== IMAGETYPE_WEBP) {
            $this->remove($key);
            throw new RuntimeException('Photo processing failed.');
        }
        return $key;
    }

    public function read(string $key): string|false
    {
        $path = $this->path($key);
        return !is_link($path) && is_file($path) ? @file_get_contents($path, false, null, 0, 2097153) : false;
    }

    public function remove(string $key): void
    {
        $path = $this->path($key);
        if (!is_link($path) && is_file($path)) {
            @unlink($path);
        }
    }
}
