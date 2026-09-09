<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $demoUser = User::create([
            'name' => 'Demo Customer',
            'email' => 'demo@bank.com',
            'password' => 'password123',
            'nif' => '123456789',
            'birth_date' => '1995-05-15',
            'pin_code' => '1234',
        ]);

        $secondUser = User::create([
            'name' => 'Jane Smith',
            'email' => 'jane@bank.com',
            'password' => 'password123',
            'nif' => '987654321',
            'birth_date' => '1998-10-20',
            'pin_code' => '1234',
        ]);

        // EUR Main Account
        $eurAccount = \App\Models\Account::create([
            'account_number' => 'PT50000000001234567890123',
            'balance' => 5000.0000,
            'currency' => 'EUR',
        ]);
        $eurAccount->users()->attach($demoUser->id, ['role' => 'owner']);

        // USD Secondary Account
        $usdAccount = \App\Models\Account::create([
            'account_number' => 'PT50000000009876543210987',
            'balance' => 2500.0000,
            'currency' => 'USD',
        ]);
        $usdAccount->users()->attach($demoUser->id, ['role' => 'owner']);

        // Jane's Account (for transfer tests)
        $janeAccount = \App\Models\Account::create([
            'account_number' => 'PT50000000005555555555555',
            'balance' => 1200.0000,
            'currency' => 'EUR',
        ]);
        $janeAccount->users()->attach($secondUser->id, ['role' => 'owner']);

        // Active Savings Vault with Spare Change
        \App\Models\Vault::create([
            'account_id' => $eurAccount->id,
            'name' => 'Emergency Fund',
            'currency' => 'EUR',
            'balance' => 450.0000,
            'target_amount' => 2000.0000,
            'spare_change_active' => true,
        ]);

        // Initial Ledger Transactions
        \App\Models\Transaction::create([
            'account_id' => $eurAccount->id,
            'user_id' => $demoUser->id,
            'reference' => 'DEP-INIT-001',
            'type' => 'DEPOSIT',
            'amount' => 5000.0000,
            'original_amount' => 5000.0000,
            'original_currency' => 'EUR',
            'balance_after' => 5000.0000,
        ]);

        \App\Models\Transaction::create([
            'account_id' => $usdAccount->id,
            'user_id' => $demoUser->id,
            'reference' => 'DEP-INIT-002',
            'type' => 'DEPOSIT',
            'amount' => 2500.0000,
            'original_amount' => 2500.0000,
            'original_currency' => 'USD',
            'balance_after' => 2500.0000,
        ]);
    }
}
