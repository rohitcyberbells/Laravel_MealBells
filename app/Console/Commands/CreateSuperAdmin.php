<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\Rules\Unique;

use function Laravel\Prompts\password as promptPassword;
use function Laravel\Prompts\text;

/**
 * Creates the first super admin on a fresh deployment.
 *
 * Both seeders refuse outside local and testing, so without this the only way
 * to get into a new production install was to run tinker on the server - not
 * repeatable, not reviewable, and easy to get subtly wrong.
 *
 * It is safe to run twice: an existing address is reported and left alone
 * rather than overwritten, so nobody can lock out a colleague by re-running it.
 */
class CreateSuperAdmin extends Command
{
    protected $signature = 'mealbells:create-super-admin
                            {--email= : The sign-in address}
                            {--name= : The person\'s name}
                            {--password= : Prompted for if omitted; prefer the prompt so it stays out of shell history}
                            {--generate-password : Generate one and print it once}
                            {--no-force-change : Do not require a password change on first sign-in}';

    protected $description = 'Create a super admin account, for the first sign-in to a new deployment';

    public function handle(): int
    {
        $email = strtolower(trim((string) ($this->option('email') ?: text(
            label: 'Sign-in address',
            required: true,
        ))));

        $existing = User::where('email', $email)->first();

        if ($existing) {
            // Idempotent on purpose: re-running must not reset a colleague's
            // password or silently change their role.
            $this->warn("An account already exists for {$email} (role: {$existing->role}).");
            $this->line('Nothing was changed. To reset its password, use the super admin screen or:');
            $this->line("  php artisan tinker --execute '…'   # see docs/deploy.md");

            return self::SUCCESS;
        }

        $name = trim((string) ($this->option('name') ?: text(
            label: 'Name',
            default: 'Platform Root',
            required: true,
        )));

        [$password, $generated] = $this->resolvePassword();

        if ($password === null) {
            return self::FAILURE;
        }

        $validator = validator(
            ['email' => $email, 'name' => $name, 'password' => $password],
            [
                'email' => ['required', 'email', 'max:255', (new Unique('users', 'email'))],
                'name' => ['required', 'string', 'max:255'],
                'password' => ['required', Password::defaults()],
            ],
        );

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }

        $user = User::create([
            'name' => $name,
            'email' => $email,
            'password' => Hash::make($password),
            'role' => 'super_admin',
            // On by default: the password above only has to survive being
            // typed once, whether it was generated or chosen here.
            'must_change_password' => ! $this->option('no-force-change'),
        ]);

        $this->newLine();
        $this->info("Super admin created: {$user->email}");

        if ($generated) {
            // The only time it is shown. It is hashed, so it cannot be recovered.
            $this->newLine();
            $this->line('Temporary password (shown once):');
            $this->line("  {$password}");
        }

        if ($user->must_change_password) {
            $this->newLine();
            $this->line('A password change is required on first sign-in.');
        }

        // Said plainly, because it is the one thing about this account that
        // cannot be fixed later without database access.
        $this->newLine();
        $this->warn('There is no recovery for a super admin who loses this password beyond');
        $this->warn('a reset link to this address, so make sure mail to it works - or create a second one.');

        return self::SUCCESS;
    }

    /**
     * @return array{0: ?string, 1: bool} the password, and whether we generated it
     */
    protected function resolvePassword(): array
    {
        if ($this->option('generate-password')) {
            // Mixed case and digits, so it satisfies the policy by construction.
            return [Str::random(10).random_int(10, 99), true];
        }

        if ($given = $this->option('password')) {
            return [(string) $given, false];
        }

        if (! $this->input->isInteractive()) {
            $this->error('No password given. Pass --password, or --generate-password when running unattended.');

            return [null, false];
        }

        $first = promptPassword(label: 'Password', required: true);
        $again = promptPassword(label: 'Confirm password', required: true);

        if ($first !== $again) {
            $this->error('Those passwords do not match.');

            return [null, false];
        }

        return [$first, false];
    }
}
