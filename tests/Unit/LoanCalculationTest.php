<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class LoanCalculationTest extends TestCase
{
    public function test_french_amortization_monthly_payment_formula(): void
    {
        $principal = 10000.0;
        $termMonths = 12;
        $annualRate = 12.0; // 12% annually => 1% monthly

        $monthlyRate = ($annualRate / 100) / 12; // 0.01

        $monthlyPayment = $principal * ($monthlyRate * pow(1 + $monthlyRate, $termMonths)) /
            (pow(1 + $monthlyRate, $termMonths) - 1);

        // Expected monthly payment for 10000 at 12% for 12 months is approx 888.49
        $this->assertEqualsWithDelta(888.49, $monthlyPayment, 0.02);
    }
}
