<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Illuminate\Support\Facades\Validator;

/**
 * ST-Admin (S11) — the ONLY supported way to create a platform superadmin.
 *
 * Superadmins are intentionally not creatable through any HTTP route (no
 * self-replication loophole if a session is ever compromised). This CLI is
 * run by the operator directly on the server.
 */
class MakeSuperadmin extends Command
{
    protected $signature = 'osms:make-superadmin
                            {email : The superadmin email address}
                            {--name= : Display name (defaults to the email)}';

    protected $description = 'Create (or promote) a platform superadmin account';

    public function handle(): int
    {
        $email = strtolower(trim($this->argument('email')));
        $name = $this->option('name') ?: $email;

        $validator = Validator::make(['email' => $email], ['email' => ['required', 'email']]);
        if ($validator->fails()) {
            $this->error('Invalid email address.');
            return self::FAILURE;
        }

        $existing = User::where('email', $email)->first();

        if ($existing) {
            if (! $this->confirm("A user with {$email} already exists. Promote them to superadmin?")) {
                return self::FAILURE;
            }
            $existing->update(['role' => 'superadmin', 'tenant_id' => null, 'email_verified_at' => now()]);
            $this->info("Promoted {$email} to superadmin.");
            return self::SUCCESS;
        }

        $password = $this->secret('Set a password (min 8 chars)');
        $confirm = $this->secret('Confirm password');

        if ($password !== $confirm) {
            $this->error('Passwords do not match.');
            return self::FAILURE;
        }

        $pwCheck = Validator::make(['password' => $password], ['password' => ['required', Password::min(8)]]);
        if ($pwCheck->fails()) {
            $this->error($pwCheck->errors()->first('password'));
            return self::FAILURE;
        }

        User::create([
            'name' => $name,
            'email' => $email,
            'password' => Hash::make($password),
            'role' => 'superadmin',
            'email_verified_at' => now(),
        ]);

        $this->info("Superadmin {$email} created.");
        return self::SUCCESS;
    }
}
