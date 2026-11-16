<?php
declare(strict_types=1);

namespace GigHub\Services;

use InvalidArgumentException;
use RuntimeException;
use Throwable;

final class ChatAttachmentStorage
{
    private const DIRECTORY = 'C:/MariaDB_Recovery/GigHub_Clean_Test/private/chat-attachments';
    private static ?string $chatDirectory = null;
    private static ?string $marketplaceDirectory = null;
    private readonly string $directory;

    public static function configure(?string $chat, ?string $marketplace): void
    {
        self::$chatDirectory = $chat;
        self::$marketplaceDirectory = $marketplace;
    }

    public static function marketplace(): self
    {
        return new self(self::$marketplaceDirectory ?? 'C:/MariaDB_Recovery/GigHub_Clean_Test/private/marketplace-documents', true);
    }

    public function __construct(?string $directory = null, private readonly bool $documentsOnly = false)
    {
        $this->directory = $directory ?? self::$chatDirectory ?? self::DIRECTORY;
    }

    private function path(string $key): string
    {
        $directory = realpath($this->directory);
        $normalize = static fn(string $path): string => strtolower(str_replace(chr(92), '/', $path));
        if ($directory === false || $normalize($directory) !== $normalize($this->directory)
            || !is_dir($directory) || !preg_match('/\A[a-f0-9]{64}\z/', $key)) {
            throw new RuntimeException('Attachment storage unavailable.');
        }
        foreach ([dirname(__DIR__, 2), $_SERVER['DOCUMENT_ROOT'] ?? ''] as $root) {
            $resolvedRoot = $root !== '' ? realpath($root) : false;
            if ($resolvedRoot !== false && str_starts_with($normalize($directory) . '/', rtrim($normalize($resolvedRoot), '/') . '/')) {
                throw new RuntimeException('Attachment storage unavailable.');
            }
        }
        return $directory . DIRECTORY_SEPARATOR . $key;
    }

    public function store(array $file): array
    {
        $source = $file['tmp_name'] ?? '';
        $name = basename(str_replace(chr(92), '/', (string)($file['name'] ?? '')));
        if (($file['error'] ?? null) !== UPLOAD_ERR_OK || !is_string($source) || !is_uploaded_file($source)
            || !preg_match('/\A[^\p{C}]{1,255}\z/u', $name)) {
            throw new InvalidArgumentException('Choose a supported file within the upload limit.');
        }
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        $images = ['jpg' => IMAGETYPE_JPEG, 'jpeg' => IMAGETYPE_JPEG, 'png' => IMAGETYPE_PNG, 'webp' => IMAGETYPE_WEBP];
        $image = isset($images[$ext]);
        if ($image && $this->documentsOnly) {
            throw new InvalidArgumentException('Choose a PDF, DOCX, XLSX, TXT, or CSV document.');
        }
        $size = filesize($source);
        if (!$size || $size > ($image ? 5 : 10) * 1024 * 1024) {
            throw new InvalidArgumentException('Images can be up to 5 MB and documents up to 10 MB.');
        }
        $bytes = file_get_contents($source);
        if ($bytes === false || strlen($bytes) !== $size) {
            throw new RuntimeException('Attachment could not be read.');
        }
        if ($image) {
            try {
                $info = getimagesizefromstring($bytes);
                if (!$info || $info[2] !== $images[$ext] || $info[0] * $info[1] > 12000000) {
                    throw new InvalidArgumentException();
                }
                $decoded = imagecreatefromstring($bytes);
                if (!$decoded || !imagepalettetotruecolor($decoded)) {
                    throw new InvalidArgumentException();
                }
                imagealphablending($decoded, false);
                imagesavealpha($decoded, true);
            } catch (Throwable) {
                throw new InvalidArgumentException('Choose a valid JPEG, PNG, or WebP up to 12 megapixels.');
            }
        } elseif (!self::validDocument($ext, $source, $bytes)) {
            throw new InvalidArgumentException('Choose a valid PDF, DOCX, XLSX, TXT, or CSV document.');
        }
        $key = bin2hex(random_bytes(32));
        $target = $this->path($key);
        $handle = fopen($target, 'xb');
        if ($handle === false) {
            throw new RuntimeException('Attachment storage unavailable.');
        }
        $ok = false;
        try {
            $ok = ($image ? imagewebp($decoded, $handle, 85) : fwrite($handle, $bytes) === $size)
                && fflush($handle) && fsync($handle);
            if (!$ok || ($image && ((getimagesize($target)[2] ?? null) !== IMAGETYPE_WEBP
                || filesize($target) > 16 * 1024 * 1024))) {
                $ok = false;
                throw new RuntimeException('Attachment write failed.');
            }
        } catch (Throwable $error) {
            $ok = false;
            throw $error;
        } finally {
            fclose($handle);
            if (!$ok) {
                $this->remove($key);
            }
        }
        return ['key' => $key, 'name' => $name, 'image' => $image];
    }

    private static function validDocument(string $ext, string $source, string $bytes): bool
    {
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($source);
        if ($ext === 'pdf') {
            return $mime === 'application/pdf' && str_starts_with($bytes, '%PDF-')
                && str_ends_with(rtrim($bytes), '%%EOF');
        }
        if ($ext === 'txt' || $ext === 'csv') {
            return in_array($mime, ['text/plain', 'text/csv'], true) && mb_check_encoding($bytes, 'UTF-8')
                && !preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]|<\?|<\s*(?:!doctype|html|script|svg)\b/i', $bytes);
        }
        if (!in_array($ext, ['docx', 'xlsx'], true) || !class_exists(\ZipArchive::class)) {
            return false;
        }
        $zip = new \ZipArchive();
        if ($zip->open($source, \ZipArchive::CHECKCONS) !== true) {
            return false;
        }
        try {
            $part = $ext === 'docx' ? 'word/document.xml' : 'xl/workbook.xml';
            $type = $ext === 'docx' ? 'wordprocessingml.document.main+xml' : 'spreadsheetml.sheet.main+xml';
            $types = $zip->getFromName('[Content_Types].xml', 65536);
            if (!is_string($types) || !str_contains($types, $type) || $zip->locateName($part) === false
                || $zip->locateName('_rels/.rels') === false || $zip->numFiles > 2048) {
                return false;
            }
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $entry = $zip->getNameIndex($i);
                if (!is_string($entry) || preg_match('~(?:^/|\\\\|(?:^|/)\.\.(?:/|$)|vbaproject|activex|embeddings|\.(?:exe|dll|js|html|svg|php|bat|ps1)$)~i', $entry)) {
                    return false;
                }
            }
            return true;
        } finally {
            $zip->close();
        }
    }

    public function read(string $key): string|false
    {
        $path = $this->path($key);
        if (is_link($path) || !is_file($path)) {
            return false;
        }
        $bytes = file_get_contents($path, false, null, 0, 16 * 1024 * 1024 + 1);
        return is_string($bytes) && strlen($bytes) <= 16 * 1024 * 1024 ? $bytes : false;
    }

    public function remove(string $key): void
    {
        $path = $this->path($key);
        if (!is_link($path) && is_file($path)) {
            unlink($path);
        }
    }
}
