<?php

declare(strict_types=1);

namespace Habi\Services;

use Habi\Support\Translator;
use Psr\Http\Message\UploadedFileInterface;
use RuntimeException;

/**
 * Proof-of-payment images for the transfer method (PRD R13, N4, N11; SDD §15.5).
 *
 * The bytes are validated by ImageUploadService (JPEG, PNG or WebP detected
 * from content, at most 2 MB) and written under a random name to
 * data/proofs/tmp/ at the payment step. Placement copies the staged file
 * into data/proofs/ inside its transaction and removes the copy again if
 * the transaction rolls back, so a retry can still use the staged file.
 * Both directories live outside public/ and no route serves them: proofs
 * are never reachable by URL. Staged files older than 24 hours are removed
 * on a best-effort basis.
 */
final class PaymentProofService
{
    public const STALE_SECONDS = 86400;

    private const NAME_PATTERN = '/^[a-f0-9]{32}\.(jpg|png|webp)$/';

    public function __construct(private readonly string $directory)
    {
    }

    /** True for names this service could have produced. */
    public static function isValidName(mixed $name): bool
    {
        return is_string($name) && preg_match(self::NAME_PATTERN, $name) === 1;
    }

    /**
     * Validates an uploaded proof and stages it; returns the random name.
     *
     * @throws CheckoutException With reason invalid and a field-level message.
     */
    public function stage(?UploadedFileInterface $file): string
    {
        if (!ImageUploadService::provided($file)) {
            throw new CheckoutException(
                Translator::translate('payment.error.proof_missing'),
                CheckoutException::REASON_INVALID
            );
        }
        try {
            $image = (new ImageUploadService($this->tempDirectory()))->validate($file);
        } catch (AdminActionException $invalid) {
            throw new CheckoutException($invalid->getMessage(), CheckoutException::REASON_INVALID);
        }

        $this->purgeStale();
        self::ensureDirectory($this->tempDirectory());
        $name = bin2hex(random_bytes(16)) . '.' . $image['extension'];
        if (file_put_contents($this->tempDirectory() . '/' . $name, $image['bytes'], LOCK_EX) === false) {
            throw new RuntimeException('Cannot store the proof of payment.');
        }

        return $name;
    }

    /** True when a staged proof with this name is still on disk. */
    public function isStaged(string $name): bool
    {
        return self::isValidName($name) && is_file($this->tempDirectory() . '/' . $name);
    }

    /**
     * Copies a staged proof into the permanent directory and returns the
     * stored name. The staged file stays until release() after commit.
     */
    public function promote(string $name): string
    {
        if (!$this->isStaged($name)) {
            throw new RuntimeException('The staged proof of payment is missing.');
        }
        self::ensureDirectory($this->directory);
        if (!copy($this->tempDirectory() . '/' . $name, $this->directory . '/' . $name)) {
            throw new RuntimeException('Cannot store the proof of payment.');
        }

        return $name;
    }

    /** Removes a promoted copy after a rolled-back placement. */
    public function discard(string $name): void
    {
        if (self::isValidName($name)) {
            @unlink($this->directory . '/' . $name);
        }
    }

    /** Removes the staged file once its order has committed. */
    public function release(string $name): void
    {
        if (self::isValidName($name)) {
            @unlink($this->tempDirectory() . '/' . $name);
        }
    }

    /**
     * Absolute path of a stored proof, or null for unknown or malformed names.
     * For the administrator proof view (M13); never used by a public route.
     */
    public function locate(string $name): ?string
    {
        $path = $this->directory . '/' . $name;

        return self::isValidName($name) && is_file($path) ? $path : null;
    }

    /** Best effort: deletes staged proofs older than STALE_SECONDS. */
    public function purgeStale(?int $now = null): void
    {
        $cutoff = ($now ?? time()) - self::STALE_SECONDS;
        foreach (glob($this->tempDirectory() . '/*') ?: [] as $path) {
            if (self::isValidName(basename($path)) && (int) @filemtime($path) < $cutoff) {
                @unlink($path);
            }
        }
    }

    private function tempDirectory(): string
    {
        return $this->directory . '/tmp';
    }

    private static function ensureDirectory(string $directory): void
    {
        if (!is_dir($directory) && !mkdir($directory, 0770, true) && !is_dir($directory)) {
            throw new RuntimeException('Cannot create the proof directory.');
        }
    }
}
