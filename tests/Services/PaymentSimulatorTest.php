<?php

declare(strict_types=1);

namespace Habi\Tests\Services;

use Habi\Services\PaymentSimulator;
use PHPUnit\Framework\TestCase;

/**
 * Boundary tests for the simulated gateway (PRD R13/R14, SDD §9): Luhn
 * and length rules, strictly future MM/YY expiry, CVC format, the three
 * test-card outcomes plus the any-other-valid approval, simulated latency
 * bounds and the rule that results never carry sensitive card data.
 */
final class PaymentSimulatorTest extends TestCase
{
    /** @var list<int> Microsecond delays the simulator asked for. */
    private array $sleeps = [];

    private function simulator(): PaymentSimulator
    {
        $this->sleeps = [];

        return new PaymentSimulator(function (int $microseconds): void {
            $this->sleeps[] = $microseconds;
        });
    }

    public function testValidCardWithSpacesIsApprovedWithDisplayFieldsOnly(): void
    {
        $result = $this->simulator()->simulate([
            'card_number' => '4242 4242 4242 4242',
            'expiry' => '12/99',
            'cvc' => '123',
        ]);

        self::assertSame(PaymentSimulator::RESULT_APPROVED, $result['result']);
        self::assertSame([], $result['errors']);
        self::assertSame('4242', $result['last4']);
        self::assertSame('Visa', $result['brand']);
        self::assertStringNotContainsString('4242424242424242', var_export($result, true));
    }

    public function testTestCardsMapToTheirDocumentedOutcomes(): void
    {
        $simulator = $this->simulator();

        $declined = $simulator->simulate([
            'card_number' => '4000 0000 0000 0002',
            'expiry' => '12/99',
            'cvc' => '123',
        ]);
        self::assertSame(PaymentSimulator::RESULT_DECLINED, $declined['result']);
        self::assertSame([], $declined['errors']);
        self::assertArrayNotHasKey('last4', $declined);
        self::assertArrayNotHasKey('brand', $declined);

        $insufficient = $simulator->simulate([
            'card_number' => '4000 0000 0000 9995',
            'expiry' => '12/99',
            'cvc' => '123',
        ]);
        self::assertSame(PaymentSimulator::RESULT_INSUFFICIENT_FUNDS, $insufficient['result']);
        self::assertSame([], $insufficient['errors']);
        self::assertArrayNotHasKey('last4', $insufficient);
        self::assertArrayNotHasKey('brand', $insufficient);
    }

    public function testAnyOtherLuhnValidNumberIsApproved(): void
    {
        $result = $this->simulator()->simulate([
            'card_number' => '5555 5555 5555 4444',
            'expiry' => '12/99',
            'cvc' => '1234',
        ]);

        self::assertSame(PaymentSimulator::RESULT_APPROVED, $result['result']);
        self::assertSame('4444', $result['last4']);
        self::assertSame('Mastercard', $result['brand']);
    }

    public function testInvalidSubmissionsReturnInlineErrorsWithoutCardData(): void
    {
        $simulator = $this->simulator();

        $luhn = $simulator->simulate([
            'card_number' => '4242 4242 4242 4241',
            'expiry' => '12/99',
            'cvc' => '123',
        ]);
        self::assertSame(PaymentSimulator::RESULT_INVALID, $luhn['result']);
        self::assertArrayHasKey('card_number', $luhn['errors']);
        self::assertArrayNotHasKey('last4', $luhn);
        self::assertArrayNotHasKey('brand', $luhn);
        self::assertStringNotContainsString('4242', var_export($luhn, true));

        $short = $simulator->simulate([
            'card_number' => '424242424242',
            'expiry' => '12/99',
            'cvc' => '123',
        ]);
        self::assertSame(PaymentSimulator::RESULT_INVALID, $short['result']);
        self::assertArrayHasKey('card_number', $short['errors']);

        $nonNumeric = $simulator->simulate([
            'card_number' => '4242-4242-4242-4242',
            'expiry' => '12/99',
            'cvc' => '123',
        ]);
        self::assertArrayHasKey('card_number', $nonNumeric['errors']);

        $array = $simulator->simulate([
            'card_number' => ['4242424242424242'],
            'expiry' => '12/99',
            'cvc' => '123',
        ]);
        self::assertArrayHasKey('card_number', $array['errors']);
    }

    public function testExpiryMustBeStrictlyFutureMonth(): void
    {
        $simulator = $this->simulator();

        $nextMonth = new \DateTimeImmutable('first day of next month');
        $currentMonth = new \DateTimeImmutable('now');
        $lastMonth = new \DateTimeImmutable('first day of last month');

        $future = $simulator->simulate([
            'card_number' => '4242424242424242',
            'expiry' => $nextMonth->format('m/y'),
            'cvc' => '123',
        ]);
        self::assertSame(PaymentSimulator::RESULT_APPROVED, $future['result']);

        // The current month is already expired.
        $current = $simulator->simulate([
            'card_number' => '4242424242424242',
            'expiry' => $currentMonth->format('m/y'),
            'cvc' => '123',
        ]);
        self::assertSame(PaymentSimulator::RESULT_INVALID, $current['result']);
        self::assertArrayHasKey('expiry', $current['errors']);

        $past = $simulator->simulate([
            'card_number' => '4242424242424242',
            'expiry' => $lastMonth->format('m/y'),
            'cvc' => '123',
        ]);
        self::assertArrayHasKey('expiry', $past['errors']);

        foreach (['13/50', '00/50', '1/3', '2030/1', 'abc/xy', ['12', '99']] as $bad) {
            $result = $simulator->simulate([
                'card_number' => '4242424242424242',
                'expiry' => $bad,
                'cvc' => '123',
            ]);
            self::assertArrayHasKey('expiry', $result['errors']);
        }
    }

    public function testCvcMustBeThreeOrFourDigits(): void
    {
        foreach (['12', '12345', '12a', '', ['123']] as $badCvc) {
            $result = $this->simulator()->simulate([
                'card_number' => '4242424242424242',
                'expiry' => '12/99',
                'cvc' => $badCvc,
            ]);
            self::assertArrayHasKey('cvc', $result['errors']);
        }

        foreach (['123', '1234'] as $goodCvc) {
            $result = $this->simulator()->simulate([
                'card_number' => '4242424242424242',
                'expiry' => '12/99',
                'cvc' => $goodCvc,
            ]);
            self::assertSame(PaymentSimulator::RESULT_APPROVED, $result['result']);
        }
    }

    public function testValidSimulationsWaitWithinTheDocumentedLatency(): void
    {
        $this->simulator()->simulate([
            'card_number' => '4242424242424242',
            'expiry' => '12/99',
            'cvc' => '123',
        ]);

        self::assertCount(1, $this->sleeps);
        self::assertGreaterThanOrEqual(1500000, $this->sleeps[0]);
        self::assertLessThanOrEqual(2500000, $this->sleeps[0]);
    }

    public function testInvalidSubmissionsDoNotWait(): void
    {
        $this->simulator()->simulate([
            'card_number' => 'not-a-card',
            'expiry' => '12/99',
            'cvc' => '123',
        ]);

        self::assertSame([], $this->sleeps);
    }
}
