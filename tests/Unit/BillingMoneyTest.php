<?php

namespace Tests\Unit;

use App\Services\Billing\BillingService;
use PHPUnit\Framework\TestCase;

class BillingMoneyTest extends TestCase
{
    public function test_rupees_are_converted_without_float_rounding(): void
    {
        foreach (['0' => 0, '0.01' => 1, '1.1' => 110, '999.99' => 99999, '100000' => 10000000] as $value => $expected) {
            $this->assertSame($expected, BillingService::paise((string) $value));
        }
    }
}
