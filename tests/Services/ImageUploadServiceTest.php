<?php

declare(strict_types=1);

namespace Habi\Tests\Services;

use Habi\Services\AdminActionException;
use Habi\Services\ImageUploadService;
use Nyholm\Psr7\Stream;
use Nyholm\Psr7\UploadedFile;
use PHPUnit\Framework\TestCase;

/**
 * Boundary tests for product image uploads (PRD R23, SDD §10): real JPEG,
 * PNG and WebP bytes are accepted with the extension detected from content
 * (never the client filename), fake or oversized files are rejected, stored
 * files are locatable with the right MIME type, and locate() refuses any
 * name it could not have produced (including path traversal).
 */
final class ImageUploadServiceTest extends TestCase
{
    private string $directory;
    private ImageUploadService $uploads;
    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/habi-upload-test-' . getmypid() . '-' . uniqid();
        self::assertTrue(mkdir($this->directory, 0775, true));
        $this->uploads = new ImageUploadService($this->directory);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory . '/*') ?: [] as $file) {
            @unlink($file);
        }
        if (is_dir($this->directory)) {
            @rmdir($this->directory);
        }
    }

    public function testAcceptsRealPngJpegAndWebpWithContentDetectedExtension(): void
    {
        $cases = [
            'png' => [$this->imageBytes('png'), 'png'],
            'jpeg' => [$this->imageBytes('jpeg'), 'jpg'],
            'webp' => [$this->imageBytes('webp'), 'webp'],
        ];

        foreach ($cases as $label => [$bytes, $extension]) {
            // The client filename lies on purpose: content decides.
            $file = $this->upload($bytes, 'evil.gif', 'image/gif');
            $image = $this->uploads->validate($file);
            self::assertSame($bytes, $image['bytes']);
            self::assertSame($extension, $image['extension'], "Type detected from bytes for {$label}.");
        }
    }

    public function testRejectsATextFileNamedPng(): void
    {
        $file = $this->upload('<?php echo "not an image";', 'photo.png', 'image/png');

        try {
            $this->uploads->validate($file);
            self::fail('Expected an AdminActionException for non-image bytes.');
        } catch (AdminActionException $exception) {
            self::assertSame('image', $exception->field);
        }
    }

    public function testRejectsFilesOverTwoMegabytes(): void
    {
        $bytes = str_repeat('A', ImageUploadService::MAX_BYTES + 1);
        $file = $this->upload($bytes, 'big.png', 'image/png');

        try {
            $this->uploads->validate($file);
            self::fail('Expected an AdminActionException for > 2 MB.');
        } catch (AdminActionException $exception) {
            self::assertSame('image', $exception->field);
            self::assertStringContainsString('2 MB', $exception->getMessage());
        }

        // Exactly 2 MB passes the size check (and fails only on content).
        $exact = $this->upload(str_repeat('B', ImageUploadService::MAX_BYTES), 'exact.png', 'image/png');
        try {
            $this->uploads->validate($exact);
            self::fail('Expected a content rejection for 2 MB of non-image bytes.');
        } catch (AdminActionException $exception) {
            self::assertStringNotContainsString('2 MB', $exception->getMessage());
        }
    }

    public function testStoredFileIsLocatableWithCorrectMimeAndBytes(): void
    {
        $bytes = $this->imageBytes('png');
        $image = $this->uploads->validate($this->upload($bytes, 'bag.png', 'image/png'));

        $publicPath = $this->uploads->store($image);
        self::assertMatchesRegularExpression('/^\/uploads\/[a-f0-9]{32}\.png$/', $publicPath);

        $name = basename($publicPath);
        $located = $this->uploads->locate($name);
        self::assertNotNull($located);
        self::assertSame('image/png', $located['mime']);
        self::assertSame($this->directory . '/' . $name, $located['path']);
        self::assertFileExists($located['path']);
        self::assertSame($bytes, file_get_contents($located['path']));

        // A different random name maps to its own MIME type.
        $jpeg = $this->uploads->validate($this->upload($this->imageBytes('jpeg'), 'bag.jpg', 'image/jpeg'));
        $jpegName = basename($this->uploads->store($jpeg));
        self::assertSame('image/jpeg', $this->uploads->locate($jpegName)['mime']);
        $webp = $this->uploads->validate($this->upload($this->imageBytes('webp'), 'bag.webp', 'image/webp'));
        $webpName = basename($this->uploads->store($webp));
        self::assertSame('image/webp', $this->uploads->locate($webpName)['mime']);
    }

    public function testLocateRejectsTraversalAndNamesItCouldNotHaveProduced(): void
    {
        $validName = bin2hex(random_bytes(16)) . '.png';
        file_put_contents($this->directory . '/' . $validName, $this->imageBytes('png'));

        self::assertNull($this->uploads->locate('../' . basename($this->directory) . '/app.sqlite'));
        self::assertNull($this->uploads->locate('../../etc/passwd'));
        self::assertNull($this->uploads->locate('..' . DIRECTORY_SEPARATOR . 'secret.png'));
        self::assertNull($this->uploads->locate('photo.png'), 'Too short to be a stored name.');
        self::assertNull($this->uploads->locate(str_repeat('z', 32) . '.png'), 'Not hex.');
        self::assertNull($this->uploads->locate(bin2hex(random_bytes(16)) . '.gif'), 'Unknown extension.');
        self::assertNull($this->uploads->locate(bin2hex(random_bytes(16)) . '.png'), 'Valid shape but never stored.');
        self::assertNull($this->uploads->locate(''));

        self::assertNotNull($this->uploads->locate($validName), 'A stored name still resolves.');
    }

    private function upload(string $bytes, string $clientName, string $clientMime): UploadedFile
    {
        return new UploadedFile(Stream::create($bytes), strlen($bytes), UPLOAD_ERR_OK, $clientName, $clientMime);
    }

    /** A real tiny checked-in image in the given format (no GD needed). */
    private function imageBytes(string $format): string
    {
        $bytes = file_get_contents(dirname(__DIR__) . '/Fixtures/images/tiny.' . $format);
        self::assertNotFalse($bytes);

        return $bytes;
    }
}
