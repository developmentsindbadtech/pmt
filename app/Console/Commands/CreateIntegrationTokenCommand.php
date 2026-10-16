<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class CreateIntegrationTokenCommand extends Command
{
    protected $signature = 'pmt:integration-token
                            {--email=integration@pmt.sindbad.tech : Integration user email}
                            {--name=Transcript Integration : Integration user display name}
                            {--fresh : Revoke existing tokens and issue a new one}
                            {--ensure : CI/deploy mode — create user + token only if missing; never print the token}';

    protected $description = 'Create or reuse the PMT integration user and print (or ensure) a Sanctum bearer token.';

    private const TOKEN_FILE = 'pmt-integration-token';

    public function handle(): int
    {
        $email = (string) $this->option('email');
        $name = (string) $this->option('name');
        $ensure = (bool) $this->option('ensure');

        $user = User::query()->where('email', $email)->first();

        if (! $user) {
            $user = User::create([
                'name' => $name,
                'email' => $email,
                'password' => Hash::make(Str::random(40)),
                'is_admin' => false,
            ]);
            $this->info("Created integration user: {$email}");
        } else {
            $this->info("Reusing integration user: {$email}");
        }

        if ($this->option('fresh')) {
            $user->tokens()->delete();
            if (Storage::disk('local')->exists(self::TOKEN_FILE)) {
                Storage::disk('local')->delete(self::TOKEN_FILE);
            }
        }

        if ($ensure && $user->tokens()->exists()) {
            $this->info('Integration token already exists; skipping create.');

            return self::SUCCESS;
        }

        $plainTextToken = $user->createToken('transcript-analyzer', ['integration:tickets'])->plainTextToken;

        if ($ensure) {
            // Persist once on the private disk for the analyzer/ops — never echo into Jenkins logs.
            Storage::disk('local')->put(self::TOKEN_FILE, $plainTextToken);
            $path = Storage::disk('local')->path(self::TOKEN_FILE);
            @chmod($path, 0600);
            $this->info('Wrote new integration token to storage/app/private/'.self::TOKEN_FILE.' (not printed).');

            return self::SUCCESS;
        }

        // Interactive / manual mode: print once to STDOUT only.
        $this->newLine();
        $this->line('Bearer token (store securely; it will not be shown again):');
        $this->line($plainTextToken);
        $this->newLine();
        $this->line('Example:');
        $this->line('  curl -X POST '.rtrim((string) config('app.url'), '/').'/api/integrations/tickets \\');
        $this->line('    -H "Authorization: Bearer <token>" \\');
        $this->line('    -H "Accept: application/json" \\');
        $this->line('    -H "Content-Type: application/json" \\');
        $this->line('    -d @payload.json');

        return self::SUCCESS;
    }
}
