<?php

declare(strict_types=1);

namespace Habi\Services;

use Psr\Http\Message\UploadedFileInterface;
use RuntimeException;

/**
 * Validated product image uploads (PRD R23, SDD §10).
 *
 * Only real JPEG, PNG or WebP images up to 2 MB are accepted: the type is
 * detected from the file's bytes (getimagesizefromstring), never from the
 * client filename or MIME header. Files get a random name with the detected
 * extension and live in data/uploads/, outside the public document root, so
 * nothing uploaded is ever executed. serve() maps the stored extension back
 * to its MIME type.
 */
final class ImageUploadService
{
    public const MAX_BYTES = 2 * 1024 * 1024;
    public const MAX_DIMENSION = 6000;
    public const PUBLIC_PREFIX = '/uploads/';

    /** @var array<int, string> image type => stored extension */
    private const TYPES = [
        IMAGETYPE_JPEG => 'jpg',
        IMAGETYPE_PNG => 'png',
        IMAGETYPE_WEBP => 'webp',
    ];

    /** @var array<string, string> stored extension => served MIME type */
    public const MIME = [
        'jpg' => 'image/jpeg',
        'png' => 'image/png',
        'webp' => 'image/webp',
    ];

    private const NAME_PATTERN = '/^[a-f0-9]{32}\.(jpg|png|webp)$/';

    public function __construct(private readonly string $directory)
    {
    }

    /** True when a file was chosen at all (an empty file input is not an upload). */
    public static function provided(?UploadedFileInterface $file): bool
    {
        return $file !== null && $file->getError() !== UPLOAD_ERR_NO_FILE;
    }

    /**
     * Validates the upload and returns its stored bytes plus extension
     * without writing anything.
     *
     * @return array{bytes: string, extension: string}
     *
     * @throws AdminActionException With field "image".
     */
    public function validate(UploadedFileInterface $file): array
    {
        $error = $file->getError();
        if ($error === UPLOAD_ERR_INI_SIZE || $error === UPLOAD_ERR_FORM_SIZE) {
            throw new AdminActionException('The image is larger than 2 MB.', 'image');
        }
        if ($error !== UPLOAD_ERR_OK) {
            throw new AdminActionException('The image could not be uploaded. Try again.', 'image');
        }
        $size = $file->getSize();
        if ($size !== null && $size > self::MAX_BYTES) {
            throw new AdminActionException('The image is larger than 2 MB.', 'image');
        }

        $stream = $file->getStream();
        $stream->rewind();
        $bytes = $stream->read(self::MAX_BYTES + 1);
        if (strlen($bytes) > self::MAX_BYTES) {
            throw new AdminActionException('The image is larger than 2 MB.', 'image');
        }

        $info = $bytes === '' ? false : @getimagesizefromstring($bytes);
        if ($info === false || !isset(self::TYPES[$info[2]])) {
            throw new AdminActionException('Upload a JPG, PNG or WebP image.', 'image');
        }
        if ($info[0] < 1 || $info[1] < 1 || $info[0] > self::MAX_DIMENSION || $info[1] > self::MAX_DIMENSION) {
            throw new AdminActionException(
                sprintf('Images must be at most %d × %d pixels.', self::MAX_DIMENSION, self::MAX_DIMENSION),
                'image'
            );
        }

        return ['bytes' => $bytes, 'extension' => self::TYPES[$info[2]]];
    }

    /**
     * Writes a validated image under a random name.
     *
     * @param array{bytes: string, extension: string} $image
     *
     * @return string Public path, e.g. /uploads/0f3c….webp
     */
    public function store(array $image): string
    {
        if (!is_dir($this->directory) && !mkdir($this->directory, 0775, true) && !is_dir($this->directory)) {
            throw new RuntimeException('Cannot create the upload directory.');
        }
        $name = bin2hex(random_bytes(16)) . '.' . $image['extension'];
        if (file_put_contents($this->directory . '/' . $name, $image['bytes'], LOCK_EX) === false) {
            throw new RuntimeException('Cannot store the uploaded image.');
        }

        return self::PUBLIC_PREFIX . $name;
    }

    /** Removes a stored upload (used when the database write fails). */
    public function discard(string $publicPath): void
    {
        $name = substr($publicPath, strlen(self::PUBLIC_PREFIX));
        if (str_starts_with($publicPath, self::PUBLIC_PREFIX) && preg_match(self::NAME_PATTERN, $name) === 1) {
            @unlink($this->directory . '/' . $name);
        }
    }

    /**
     * Absolute file path and MIME type for a stored name, or null for any
     * name this service could not have produced.
     *
     * @return array{path: string, mime: string}|null
     */
    public function locate(string $name): ?array
    {
        if (preg_match(self::NAME_PATTERN, $name, $match) !== 1) {
            return null;
        }
        $path = $this->directory . '/' . $name;

        return is_file($path) ? ['path' => $path, 'mime' => self::MIME[$match[1]]] : null;
    }
}
