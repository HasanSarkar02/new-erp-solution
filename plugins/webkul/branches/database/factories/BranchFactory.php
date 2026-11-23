<?php

namespace Webkul\Branch\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Webkul\Branch\Enums\BranchType;
use Webkul\Branch\Models\Branch;
use Webkul\Security\Models\User;
use Webkul\Support\Models\Company;

/**
 * @extends Factory<Branch>
 *
 * Note: this factory only builds the Branch row itself. A real branch
 * also needs its Partner and Warehouse, which BranchService::create()
 * provides. Prefer BranchService in tests that need a usable branch.
 */
class BranchFactory extends Factory
{
    protected $model = Branch::class;

    public function definition(): array
    {
        return [
            'company_id'      => Company::query()->value('id') ?? Company::factory(),
            'partner_id'      => null,
            'warehouse_id'    => null,
            'manager_user_id' => null,
            'code'            => strtoupper(fake()->unique()->lexify('BR???')),
            'name'            => fake()->unique()->company().' Branch',
            'type'            => BranchType::Sub->value,
            'is_main'         => false,
            'is_active'       => true,
            'is_demo'         => false,
            'phone'           => fake()->optional()->phoneNumber(),
            'mobile'          => fake()->optional()->phoneNumber(),
            'email'           => fake()->optional()->safeEmail(),
            'street1'         => fake()->optional()->streetAddress(),
            'city'            => fake()->optional()->city(),
            'zip'             => fake()->optional()->postcode(),
            'opening_balance' => 0,
            'creator_id'      => User::query()->value('id') ?? User::factory(),
        ];
    }

    public function main(): static
    {
        return $this->state(fn (array $attributes) => [
            'code'    => 'MAIN',
            'name'    => 'Main Branch',
            'type'    => BranchType::Main->value,
            'is_main' => true,
        ]);
    }

    public function service(): static
    {
        return $this->state(fn (array $attributes) => [
            'type'    => BranchType::Service->value,
            'is_main' => false,
        ]);
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => false,
        ]);
    }
}
