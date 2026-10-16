<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class CreateIntegrationTokenCommand extends Command
{
    protected $signature = 'pmt:integration-token
                            {--email=integration@pmt.sindbad.tech : Integration user email}
                            {--name=Transcript Integration : Integration user display name}
                            {--fresh : Revoke existing tokens and issue a new one}';

    protected $description = 'Create or reuse the PMT integration user and print a Sanctum bearer token (once).';

    public function handle(): int
    {
        $email = (string) $this->option('email');
        $name = (string) $this->option('name');

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
        }

        $plainTextToken = $user->createToken('transcript-analyzer', ['integration:tickets'])->plainTextToken;

        // Print once to STDOUT only — never write the token to logs or files.
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
