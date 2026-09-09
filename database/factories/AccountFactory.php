<?php

namespace Database\Factories;

use App\Models\Account;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Account>
 */
class AccountFactory extends Factory
{
    protected $model = Account::class;

    public function definition(): array
    {
        $randomDigits = fake()->numerify('###############');
        $accountNumber = 'PT50' . str_pad($randomDigits, 21, '0', STR_PAD_LEFT);

        return [
            'account_number' => $accountNumber,
            'balance' => 0.0000,
            'currency' => 'EUR',
        ];
    }
}
