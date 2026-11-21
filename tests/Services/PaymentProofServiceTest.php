<?php

declare(strict_types=1);

namespace Habi\Tests\Services;

use Habi\Services\CheckoutException;
use Habi\Services\PaymentProofService;
use Nyholm\Psr7\Stream;
use Nyholm\Psr7\UploadedFile;
use PHPUnit\Framework\TestCase;

/**
 * Boundary tests for proof-of-payment staging (PRD N11): only real JPEG,
 * PNG or WebP bytes up to 2 MB are staged, under a random name with the
 * detected extension; missing or fake files are refused with a field
 * message; stale staged files are purged; locate() refuses names the
 * service could not have produced.
 */
final class PaymentProofServiceTest extends TestCase
{
    private string $directory;
    private PaymentProofService $proofs;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/habi-proof-test-' . getmypid() . '-' . uniqid();
        $this->proofs = new PaymentProofService($this->directory);
    }

    protected function tearDown(): void
    {
        foreach ([$this->directory . '/tmp', $this->directory] as $directory) {
            foreach (glob($directory . '/*') ?: [] as $file) {
                @unlink($file);
            }
            @rmdir($directory);
        }
    }

    public function testStagesARealImageUnderARandomNameWithTheDetectedExtension(): void
    {
        $bytes = (string) file_get_contents(habi_repo_root() . '/tests/Fixtures/images/tiny.jpeg');
        $name = $this->proofs->stage($this->upload($bytes, 'receipt.gif'));

        self::assertMatchesRegularExpression('/^[a-f0-9]{32}\.jpg$/', $name);
        self::assertSame($bytes, file_get_contents($this->directory . '/tmp/' . $name));
        self::assertSame($name, $this->proofs->promote($name));
        self::assertSame($this->directory . '/' . $name, $this->proofs->locate($name));
    }

    public function testRefusesMissingFakeAndOversizedFiles(): void
    {
        $cases = [
            'missing' => [null, 'Upload a photo or screenshot of your payment.'],
            'empty input' => [
                new UploadedFile(Stream::create(''), 0, UPLOAD_ERR_NO_FILE),
                'Upload a photo or screenshot of your payment.',
            ],
            'php named png' => [$this->upload('<?php echo 1;', 'receipt.png'), 'Upload a JPG, PNG or WebP image.'],
            'oversized' => [
                $this->upload(str_repeat('a', 2 * 1024 * 1024 + 1), 'big.png'),
                'The image is larger than 2 MB.',
            ],
        ];
        foreach ($cases as $label => [$file, $message]) {
            try {
                $this->proofs->stage($file);
                self::fail('Expected a refusal for ' . $label);
            } catch (CheckoutException $refused) {
                self::assertSame(CheckoutException::REASON_INVALID, $refused->reason, $label);
                self::assertSame($message, $refused->getMessage(), $label);
            }
        }
        self::assertSame([], glob($this->directory . '/tmp/*') ?: []);
    }

    public function testPurgesOnlyStaleStagedProofs(): void
    {
        $bytes = (string) file_get_contents(habi_repo_root() . '/tests/Fixtures/images/tiny.png');
        $old = $this->proofs->stage($this->upload($bytes, 'a.png'));
        $fresh = $this->proofs->stage($this->upload($bytes, 'b.png'));
        touch($this->directory . '/tmp/' . $old, time() - PaymentProofService::STALE_SECONDS - 10);

        $this->proofs->purgeStale();

        self::assertFalse($this->proofs->isStaged($old));
        self::assertTrue($this->proofs->isStaged($fresh));
    }

    public function testLocateRefusesNamesItCouldNotHaveProduced(): void
    {
        foreach (['../app.sqlite', 'tmp/x.png', 'abc.png', str_repeat('a', 32) . '.php', ''] as $name) {
            self::assertNull($this->proofs->locate($name), $name);
            self::assertFalse($this->proofs->isStaged($name), $name);
        }
    }

    private function upload(string $bytes, string $clientName): UploadedFile
    {
        return new UploadedFile(Stream::create($bytes), strlen($bytes), UPLOAD_ERR_OK, $clientName, 'image/png');
    }
}
