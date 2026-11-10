<?php

declare(strict_types=1);

namespace Habi\Tests\Services;

use Habi\Services\ShippingService;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * Boundary tests for the shipping step (PRD R11/R12): the binding region
 * rates, the strict free-shipping threshold, unknown-region rejection and
 * the validation rules that gate every shipping submission.
 */
final class ShippingServiceTest extends TestCase
{
    public function testFeesMatchPrdRatesBelowTheFreeThreshold(): void
    {
        self::assertSame(12000, ShippingService::fee('metro_manila', 0));
        self::assertSame(18000, ShippingService::fee('luzon', 250000));
        self::assertSame(22000, ShippingService::fee('visayas', 499999));
        self::assertSame(25000, ShippingService::fee('mindanao', 500000));
    }

    public function testFreeShippingAppliesStrictlyAboveTheThreshold(): void
    {
        // Exactly 5,000 pesos still pays the region fee.
        self::assertSame(12000, ShippingService::fee('metro_manila', 500000));
        self::assertSame(0, ShippingService::fee('metro_manila', 500001));
        self::assertSame(0, ShippingService::fee('mindanao', 999999999));
    }

    public function testUnknownRegionIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        ShippingService::fee('cebu', 100);
    }

    public function testValidSubmissionTrimsValuesAndReturnsNoErrors(): void
    {
        $result = ShippingService::validate([
            'name' => '  Maria Clara  ',
            'email' => 'maria@example.com',
            'phone' => '+63 917 123 4567',
            'address1' => ' 12 Katipunan Ave ',
            'address2' => '',
            'city' => 'Quezon City',
            'postal_code' => '1108',
            'region' => 'metro_manila',
        ]);

        self::assertSame([], $result['errors']);
        self::assertSame('Maria Clara', $result['shipping']['name']);
        self::assertSame('12 Katipunan Ave', $result['shipping']['address1']);
        self::assertSame('maria@example.com', $result['shipping']['email']);
        self::assertSame('1108', $result['shipping']['postal_code']);
        self::assertSame('metro_manila', $result['shipping']['region']);
    }

    public function testRequiredAndFormatErrorsAreKeyedByField(): void
    {
        $result = ShippingService::validate([
            'name' => '',
            'email' => 'not-an-email',
            'phone' => '123',
            'address1' => '',
            'address2' => 'optional anyway',
            'city' => '',
            'postal_code' => 'AB12',
            'region' => 'mars',
        ]);

        self::assertSame(
            ['name', 'email', 'phone', 'address1', 'city', 'postal_code', 'region'],
            array_keys($result['errors'])
        );
        self::assertArrayNotHasKey('address2', $result['errors']);
    }

    public function testArrayPayloadsAreRejectedAndNeverEchoed(): void
    {
        $result = ShippingService::validate([
            'name' => ['inject', 'ed'],
            'email' => 'maria@example.com',
            'phone' => '+63 917 123 4567',
            'address1' => ['x'],
            'address2' => ['y'],
            'city' => 42,
            'postal_code' => '1108',
            'region' => ['metro_manila'],
        ]);

        self::assertSame(
            ['name', 'address1', 'address2', 'city', 'region'],
            array_keys($result['errors'])
        );
        self::assertSame('', $result['shipping']['name']);
        self::assertSame('', $result['shipping']['address1']);
        self::assertSame('', $result['shipping']['city']);
        // Safe submitted fields are still preserved for redisplay.
        self::assertSame('maria@example.com', $result['shipping']['email']);
        self::assertSame('+63 917 123 4567', $result['shipping']['phone']);
        self::assertSame('1108', $result['shipping']['postal_code']);
    }

    public function testOverlongValuesAreRejected(): void
    {
        $result = ShippingService::validate([
            'name' => str_repeat('a', 101),
            'email' => str_repeat('a', 190) . '@example.com',
            'phone' => '+63 917 123 4567',
            'address1' => str_repeat('a', 201),
            'address2' => str_repeat('a', 201),
            'city' => str_repeat('a', 101),
            'postal_code' => '12345678901',
            'region' => 'metro_manila',
        ]);

        self::assertSame(
            ['name', 'email', 'address1', 'address2', 'city', 'postal_code'],
            array_keys($result['errors'])
        );
    }
}
