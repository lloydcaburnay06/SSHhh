<?php

namespace Database\Factories;

use App\Models\User;
use App\Services\Crypto;
use App\Services\Totp;
use App\Support\Roles;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    protected $model = User::class;

    /** Plain password of every factory user. */
    public const PASSWORD = 'correct horse battery staple';

    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'password' => self::PASSWORD,
            'is_active' => true,
            'must_change_password' => false,
        ];
    }

    /** Google Authenticator linked with the given (or a new) secret. */
    public function withTwoFactor(?string $secret = null): static
    {
        return $this->afterCreating(function (User $user) use ($secret): void {
            $user->forceFill([
                'two_factor_secret' => app(Crypto::class)->encrypt($secret ?? Totp::generateSecret(), 'user-totp:'.$user->id),
                'two_factor_confirmed_at' => now(),
            ])->save();
        });
    }

    public function superAdmin(): static
    {
        return $this->afterCreating(fn (User $user) => $user->assignRole(Roles::SUPER_ADMIN));
    }

    public function regular(): static
    {
        return $this->afterCreating(fn (User $user) => $user->assignRole(Roles::USER));
    }
}
