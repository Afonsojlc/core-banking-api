<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AccountTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_create_an_account(): void
    {
        Http::fake([
            'https://api.frankfurter.app/currencies' => Http::response(['EUR' => 'Euro', 'USD' => 'US Dollar'], 200),
        ]);

        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/accounts', [
            'currency' => 'EUR',
        ]);

        $response->assertStatus(201)
            ->assertJsonStructure([
                'message',
                'account' => ['id', 'account_number', 'currency', 'balance'],
                'owner' => ['id', 'name', 'nif'],
            ]);

        $this->assertDatabaseHas('accounts', [
            'currency' => 'EUR',
            'balance' => 0.0000,
        ]);

        $this->assertDatabaseHas('account_user', [
            'user_id' => $user->id,
            'role' => 'owner',
        ]);
    }

    public function test_user_can_view_account_balance(): void
    {
        $user = User::factory()->create();
        $account = Account::factory()->create([
            'balance' => 1500.5000,
            'currency' => 'EUR',
        ]);
        $account->users()->attach($user->id, ['role' => 'owner']);

        Sanctum::actingAs($user);

        $response = $this->getJson("/api/accounts/{$account->id}/balance");

        $response->assertStatus(200)
            ->assertJson([
                'account_id' => $account->id,
                'account_number' => $account->account_number,
                'currency' => 'EUR',
                'balance' => 1500.5000,
            ]);
    }

    public function test_user_cannot_view_balance_of_another_users_account(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();

        $account = Account::factory()->create();
        $account->users()->attach($otherUser->id, ['role' => 'owner']);

        Sanctum::actingAs($user);

        $response = $this->getJson("/api/accounts/{$account->id}/balance");

        $response->assertStatus(403);
    }

    public function test_balance_returns_404_for_non_existent_account(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $response = $this->getJson('/api/accounts/99999/balance');

        $response->assertStatus(404);
    }

    public function test_user_can_list_all_their_accounts(): void
    {
        $user = User::factory()->create();
        $account1 = Account::factory()->create(['currency' => 'EUR']);
        $account2 = Account::factory()->create(['currency' => 'USD']);

        $account1->users()->attach($user->id, ['role' => 'owner']);
        $account2->users()->attach($user->id, ['role' => 'owner']);

        Sanctum::actingAs($user);

        $response = $this->getJson('/api/accounts/my-accounts');

        $response->assertStatus(200)
            ->assertJson([
                'total_accounts' => 2,
            ]);
    }
}
