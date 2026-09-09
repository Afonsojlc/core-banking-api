<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Vault;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TransactionTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_deposit_funds_same_currency(): void
    {
        $user = User::factory()->create();
        $account = Account::factory()->create([
            'balance' => 100.0000,
            'currency' => 'EUR',
        ]);
        $account->users()->attach($user->id, ['role' => 'owner']);

        Sanctum::actingAs($user);

        $response = $this->postJson("/api/accounts/{$account->id}/deposit", [
            'amount' => 50.00,
            'currency' => 'EUR',
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'type' => 'DEPOSIT',
                'new_balance' => 150.0000,
            ]);

        $this->assertDatabaseHas('transactions', [
            'account_id' => $account->id,
            'type' => 'DEPOSIT',
            'amount' => 50.0000,
            'balance_after' => 150.0000,
        ]);
    }

    public function test_user_can_withdraw_funds_with_valid_pin(): void
    {
        $user = User::factory()->create([
            'pin_code' => Hash::make('1234'),
        ]);
        $account = Account::factory()->create([
            'balance' => 200.0000,
            'currency' => 'EUR',
        ]);
        $account->users()->attach($user->id, ['role' => 'owner']);

        Sanctum::actingAs($user);

        $response = $this->postJson("/api/accounts/{$account->id}/withdraw", [
            'amount' => 50.00,
            'pin_code' => '1234',
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'type' => 'WITHDRAWAL',
                'new_balance' => 150.0000,
            ]);

        $this->assertDatabaseHas('transactions', [
            'account_id' => $account->id,
            'type' => 'WITHDRAWAL',
            'amount' => 50.0000,
            'balance_after' => 150.0000,
        ]);
    }

    public function test_withdrawal_fails_with_invalid_pin(): void
    {
        $user = User::factory()->create([
            'pin_code' => Hash::make('1234'),
        ]);
        $account = Account::factory()->create([
            'balance' => 200.0000,
            'currency' => 'EUR',
        ]);
        $account->users()->attach($user->id, ['role' => 'owner']);

        Sanctum::actingAs($user);

        $response = $this->postJson("/api/accounts/{$account->id}/withdraw", [
            'amount' => 50.00,
            'pin_code' => '9999',
        ]);

        $response->assertStatus(401);
    }

    public function test_withdrawal_fails_with_insufficient_balance(): void
    {
        $user = User::factory()->create([
            'pin_code' => Hash::make('1234'),
        ]);
        $account = Account::factory()->create([
            'balance' => 30.0000,
            'currency' => 'EUR',
        ]);
        $account->users()->attach($user->id, ['role' => 'owner']);

        Sanctum::actingAs($user);

        $response = $this->postJson("/api/accounts/{$account->id}/withdraw", [
            'amount' => 50.00,
            'pin_code' => '1234',
        ]);

        $response->assertStatus(422)
            ->assertJson(['error' => 'Unprocessable Entity']);
    }

    public function test_transfer_between_accounts_same_currency(): void
    {
        $sourceUser = User::factory()->create(['pin_code' => Hash::make('1234')]);
        $destUser = User::factory()->create();

        $sourceAccount = Account::factory()->create(['balance' => 500.0000, 'currency' => 'EUR']);
        $sourceAccount->users()->attach($sourceUser->id, ['role' => 'owner']);

        $destAccount = Account::factory()->create(['balance' => 100.0000, 'currency' => 'EUR']);
        $destAccount->users()->attach($destUser->id, ['role' => 'owner']);

        Sanctum::actingAs($sourceUser);

        $response = $this->postJson('/api/transfers', [
            'source_account_id' => $sourceAccount->id,
            'destination_account_id' => $destAccount->id,
            'amount' => 200.00,
            'pin_code' => '1234',
        ]);

        $response->assertStatus(200);

        $this->assertEquals(300.0000, $sourceAccount->fresh()->balance);
        $this->assertEquals(300.0000, $destAccount->fresh()->balance);

        $this->assertDatabaseHas('transactions', [
            'account_id' => $sourceAccount->id,
            'type' => 'TRANSFER_OUT',
            'amount' => 200.0000,
            'balance_after' => 300.0000,
        ]);

        $this->assertDatabaseHas('transactions', [
            'account_id' => $destAccount->id,
            'type' => 'TRANSFER_IN',
            'amount' => 200.0000,
            'balance_after' => 300.0000,
        ]);
    }

    public function test_card_payment_with_spare_change_auto_rounding(): void
    {
        $user = User::factory()->create(['pin_code' => Hash::make('1234')]);
        $account = Account::factory()->create(['balance' => 50.0000, 'currency' => 'EUR']);
        $account->users()->attach($user->id, ['role' => 'owner']);

        $vault = Vault::factory()->create([
            'account_id' => $account->id,
            'balance' => 10.0000,
            'currency' => 'EUR',
            'spare_change_active' => true,
        ]);

        Sanctum::actingAs($user);

        // Payment of 4.20 EUR -> ceil is 5.00 -> spare change is 0.80 EUR
        $response = $this->postJson("/api/accounts/{$account->id}/payment", [
            'amount' => 4.20,
            'pin_code' => '1234',
        ]);

        $response->assertStatus(200);

        // Account debited 4.20 + 0.80 = 5.00 => balance 50 - 5 = 45.00
        $this->assertEquals(45.0000, $account->fresh()->balance);
        // Vault credited 0.80 => 10.00 + 0.80 = 10.80
        $this->assertEquals(10.8000, $vault->fresh()->balance);

        $this->assertDatabaseHas('transactions', [
            'account_id' => $account->id,
            'type' => 'PAYMENT',
            'amount' => 4.2000,
        ]);

        $this->assertDatabaseHas('transactions', [
            'account_id' => $account->id,
            'type' => 'SPARE_CHANGE',
            'amount' => 0.8000,
        ]);
    }

    public function test_user_can_view_transaction_history(): void
    {
        $user = User::factory()->create();
        $account = Account::factory()->create();
        $account->users()->attach($user->id, ['role' => 'owner']);

        Transaction::factory()->create([
            'account_id' => $account->id,
            'user_id' => $user->id,
            'type' => 'DEPOSIT',
            'amount' => 100.0000,
        ]);

        Sanctum::actingAs($user);

        $response = $this->getJson("/api/accounts/{$account->id}/transactions");

        $response->assertStatus(200)
            ->assertJsonStructure(['data']);
    }
}
