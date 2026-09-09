<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\User;
use App\Models\Vault;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class VaultTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_create_a_vault(): void
    {
        $user = User::factory()->create();
        $account = Account::factory()->create(['currency' => 'EUR']);
        $account->users()->attach($user->id, ['role' => 'owner']);

        Sanctum::actingAs($user);

        $response = $this->postJson("/api/accounts/{$account->id}/vaults", [
            'name' => 'Vacation Fund',
            'target_amount' => 1500.00,
            'currency' => 'EUR',
        ]);

        $response->assertStatus(201)
            ->assertJsonStructure([
                'message',
                'vault' => ['id', 'name', 'currency', 'target_amount'],
            ]);

        $this->assertDatabaseHas('vaults', [
            'account_id' => $account->id,
            'name' => 'Vacation Fund',
            'currency' => 'EUR',
            'target_amount' => 1500.0000,
            'spare_change_active' => false,
        ]);
    }

    public function test_user_can_deposit_funds_into_vault(): void
    {
        $user = User::factory()->create();
        $account = Account::factory()->create(['balance' => 500.0000, 'currency' => 'EUR']);
        $account->users()->attach($user->id, ['role' => 'owner']);

        $vault = Vault::factory()->create([
            'account_id' => $account->id,
            'balance' => 50.0000,
            'currency' => 'EUR',
        ]);

        Sanctum::actingAs($user);

        $response = $this->postJson("/api/vaults/{$vault->id}/deposit", [
            'amount' => 100.00,
        ]);

        $response->assertStatus(200);

        $this->assertEquals(400.0000, $account->fresh()->balance);
        $this->assertEquals(150.0000, $vault->fresh()->balance);

        $this->assertDatabaseHas('transactions', [
            'account_id' => $account->id,
            'type' => 'VAULT_FUNDING',
            'amount' => 100.0000,
        ]);
    }

    public function test_vault_deposit_fails_when_account_has_insufficient_balance(): void
    {
        $user = User::factory()->create();
        $account = Account::factory()->create(['balance' => 20.0000, 'currency' => 'EUR']);
        $account->users()->attach($user->id, ['role' => 'owner']);

        $vault = Vault::factory()->create([
            'account_id' => $account->id,
            'balance' => 0.0000,
            'currency' => 'EUR',
        ]);

        Sanctum::actingAs($user);

        $response = $this->postJson("/api/vaults/{$vault->id}/deposit", [
            'amount' => 100.00,
        ]);

        $response->assertStatus(422);
    }

    public function test_user_can_withdraw_funds_from_vault(): void
    {
        $user = User::factory()->create();
        $account = Account::factory()->create(['balance' => 100.0000, 'currency' => 'EUR']);
        $account->users()->attach($user->id, ['role' => 'owner']);

        $vault = Vault::factory()->create([
            'account_id' => $account->id,
            'balance' => 200.0000,
            'currency' => 'EUR',
        ]);

        Sanctum::actingAs($user);

        $response = $this->postJson("/api/vaults/{$vault->id}/withdraw", [
            'amount' => 50.00,
        ]);

        $response->assertStatus(200);

        $this->assertEquals(150.0000, $account->fresh()->balance);
        $this->assertEquals(150.0000, $vault->fresh()->balance);

        $this->assertDatabaseHas('transactions', [
            'account_id' => $account->id,
            'type' => 'VAULT_WITHDRAWAL',
            'amount' => 50.0000,
        ]);
    }

    public function test_user_can_toggle_spare_change(): void
    {
        $user = User::factory()->create();
        $account = Account::factory()->create();
        $account->users()->attach($user->id, ['role' => 'owner']);

        $vault = Vault::factory()->create([
            'account_id' => $account->id,
            'spare_change_active' => false,
        ]);

        Sanctum::actingAs($user);

        // Turn on
        $response = $this->patchJson("/api/vaults/{$vault->id}/spare-change");
        $response->assertStatus(200)
            ->assertJson(['spare_change_active' => true]);
        $this->assertTrue($vault->fresh()->spare_change_active);

        // Turn off
        $response = $this->patchJson("/api/vaults/{$vault->id}/spare-change");
        $response->assertStatus(200)
            ->assertJson(['spare_change_active' => false]);
        $this->assertFalse($vault->fresh()->spare_change_active);
    }

    public function test_user_can_list_all_my_vaults(): void
    {
        $user = User::factory()->create();
        $account = Account::factory()->create();
        $account->users()->attach($user->id, ['role' => 'owner']);

        Vault::factory()->create(['account_id' => $account->id, 'name' => 'Vault A']);
        Vault::factory()->create(['account_id' => $account->id, 'name' => 'Vault B']);

        Sanctum::actingAs($user);

        $response = $this->getJson('/api/vaults/my-vaults');

        $response->assertStatus(200)
            ->assertJson([
                'total_vaults' => 2,
            ]);
    }
}
