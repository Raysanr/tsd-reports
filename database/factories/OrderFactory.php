<?php

namespace Database\Factories;

use App\Models\Order;
use Illuminate\Database\Eloquent\Factories\Factory;

class OrderFactory extends Factory
{
    protected $model = Order::class;

    public function definition(): array
    {
        return [
            'pancake_order_id'   => (string) $this->faker->unique()->randomNumber(8),
            'team'                => null,
            'tsa_name'            => null,
            'disposition'         => null,
            'product'             => null,
            'amount'              => 0,
            'raw_tags'            => [],
            'is_upsell'           => false,
            'is_cancelled_upsell' => false,
            'is_returned_upsell'  => false,
            'status_code'         => 3,
            'pancake_created_at'  => now(),
            'synced_at'           => now(),
        ];
    }
}
