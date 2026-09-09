<?php

namespace Database\Factories;

use App\Models\Account;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Transaction>
 */
class TransactionFactory extends Factory
{
    protected $model = Transaction::class;

    public function definition(): array
    {
        return [
            'account_id' => Account::factory(),
            'user_id' => User::factory(),
            'destination_account_id' => null,
            'reference' => 'TX-' . strtoupper(Str::random(8)) . '-' . time(),
            'type' => 'DEPOSIT',
            'amount' => 100.0000,
            'original_amount' => 100.0000,
            'original_currency' => 'EUR',
            'balance_after' => 100.0000,
        ];
    }
}
