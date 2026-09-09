<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class LoanTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_simulate_loan_amortization_schedule(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/loans/simulate', [
            'amount' => 10000,
            'term_months' => 12,
            'interest_rate' => 5.0,
            'currency' => 'EUR',
        ]);

        $response->assertStatus(200)
            ->assertJsonStructure([
                'simulation_summary' => [
                    'loan_amount',
                    'term_months',
                    'annual_interest_rate_percent',
                    'monthly_payment',
                    'total_interest_paid',
                    'total_amount_to_pay',
                ],
                'amortization_schedule' => [
                    '*' => [
                        'month',
                        'monthly_payment',
                        'interest_paid',
                        'principal_paid',
                        'remaining_balance',
                    ],
                ],
            ]);

        $schedule = $response->json('amortization_schedule');
        $this->assertCount(12, $schedule);
        // The last month's remaining balance must be 0.00 EUR
        $this->assertEquals('0.00 EUR', $schedule[11]['remaining_balance']);
    }

    public function test_loan_simulation_fails_with_invalid_parameters(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/loans/simulate', [
            'amount' => -100, // Invalid: must be gt:0
            'term_months' => 0, // Invalid: must be gt:0
            'interest_rate' => -5, // Invalid: must be min:0
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['amount', 'term_months', 'interest_rate']);
    }
}
