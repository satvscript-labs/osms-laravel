<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * The platform superadmin, seeded from the environment so credentials never live
 * in code or git. Reads SUPERADMIN_NAME / SUPERADMIN_EMAIL / SUPERADMIN_PASSWORD
 * (set in .env.prod on the server).
 *
 * Idempotent: re-running promotes/updates the existing account rather than
 * creating duplicates, and it is safe to include in `migrate --seed`. If no
 * email/password is configured it does nothing (so CI and local test runs, which
 * have neither, are unaffected).
 *
 *   php artisan db:seed --class=Database\\Seeders\\SuperadminSeeder
 */
class SuperadminSeeder extends Seeder
{
    public function run(): void
    {
        $email = strtolower(trim((string) env('SUPERADMIN_EMAIL')));
        $password = (string) env('SUPERADMIN_PASSWORD');
        $name = trim((string) env('SUPERADMIN_NAME')) ?: $email;

        if ($email === '' || $password === '') {
            $this->command?->warn('SuperadminSeeder skipped — SUPERADMIN_EMAIL / SUPERADMIN_PASSWORD not set.');

            return;
        }

        $user = User::updateOrCreate(
            ['email' => $email],
            [
                'name' => $name,
                'password' => Hash::make($password),
                'role' => 'superadmin',
                'tenant_id' => null,
                'email_verified_at' => now(),
            ],
        );

        $this->command?->info("Superadmin ready: {$user->email}");
    }
}
