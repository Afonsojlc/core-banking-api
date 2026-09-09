<?php

namespace Database\Factories;

use App\Models\Account;
use App\Models\Vault;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Vault>
 */
class VaultFactory extends Factory
{
    protected $model = Vault::class;

    public function definition(): array
    {
        return [
            'account_id' => Account::factory(),
            'name' => fake()->word() . ' Vault',
            'currency' => 'EUR',
            'balance' => 0.0000,
            'target_amount' => 1000.0000,
            'spare_change_active' => false,
        ];
    }
}
