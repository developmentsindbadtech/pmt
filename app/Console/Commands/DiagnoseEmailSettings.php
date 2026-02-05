<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use App\Services\MicrosoftGraphMailService;

class DiagnoseEmailSettings extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'email:diagnose';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Diagnose email settings and Microsoft Graph API configuration';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('🔍 Diagnosing Email Settings Configuration...');
        $this->newLine();

        $issues = [];
        $warnings = [];

        // Check .env configuration
        $this->info('1. Checking .env configuration...');
        $clientId = config('services.microsoft.client_id');
        $clientSecret = config('services.microsoft.client_secret');
        $tenantId = config('services.microsoft.tenant');

        if (empty($clientId)) {
            $issues[] = 'MICROSOFT_CLIENT_ID is not set in .env';
            $this->error('   ❌ MICROSOFT_CLIENT_ID is missing');
        } else {
            $this->info("   ✅ MICROSOFT_CLIENT_ID: {$clientId}");
        }

        if (empty($clientSecret)) {
            $issues[] = 'MICROSOFT_CLIENT_SECRET is not set in .env';
            $this->error('   ❌ MICROSOFT_CLIENT_SECRET is missing');
        } else {
            $this->info('   ✅ MICROSOFT_CLIENT_SECRET: Set (hidden)');
        }

        if (empty($tenantId)) {
            $warnings[] = 'MICROSOFT_TENANT_ID is not set, using "common"';
            $this->warn('   ⚠️  MICROSOFT_TENANT_ID is missing (using "common")');
        } else {
            $this->info("   ✅ MICROSOFT_TENANT_ID: {$tenantId}");
        }

        $this->newLine();

        // Check database settings
        $this->info('2. Checking database email settings...');
        $settings = DB::table('email_settings')->first();
        
        if (!$settings) {
            $issues[] = 'Email settings not found in database';
            $this->error('   ❌ No email settings found in database');
        } else {
            $this->info('   ✅ Email settings found');
            $this->info("   - Enabled: " . ($settings->enabled ? 'Yes' : 'No'));
            $this->info("   - Send From Email: " . ($settings->send_from_email ?? 'Not set'));
            
            if (!$settings->enabled) {
                $warnings[] = 'Email notifications are disabled';
                $this->warn('   ⚠️  Email notifications are disabled');
            }
            
            if (empty($settings->send_from_email)) {
                $issues[] = 'Send From Email is not configured';
                $this->error('   ❌ Send From Email is not set');
            }
        }

        $this->newLine();

        // Test Microsoft Graph API access token
        $this->info('3. Testing Microsoft Graph API access token...');
        try {
            $tenant = $tenantId === 'common' ? 'organizations' : $tenantId;
            $response = Http::asForm()->post("https://login.microsoftonline.com/{$tenant}/oauth2/v2.0/token", [
                'client_id' => $clientId,
                'client_secret' => $clientSecret,
                'scope' => 'https://graph.microsoft.com/.default',
                'grant_type' => 'client_credentials',
            ]);

            if ($response->successful()) {
                $data = $response->json();
                if (isset($data['access_token'])) {
                    $this->info('   ✅ Successfully obtained access token');
                    $token = $data['access_token'];
                } else {
                    $issues[] = 'Access token not in response';
                    $this->error('   ❌ Access token not found in response');
                    $token = null;
                }
            } else {
                $errorBody = $response->body();
                $errorData = json_decode($errorBody, true);
                $errorMessage = $errorData['error_description'] ?? $errorData['error'] ?? $errorBody;
                $issues[] = "Failed to get access token: {$errorMessage}";
                $this->error("   ❌ Failed to get access token: {$errorMessage}");
                $token = null;
            }
        } catch (\Exception $e) {
            $issues[] = "Exception getting access token: " . $e->getMessage();
            $this->error("   ❌ Exception: " . $e->getMessage());
            $token = null;
        }

        $this->newLine();

        // Test user lookup if we have token and send_from_email
        if ($token && $settings && !empty($settings->send_from_email)) {
            $this->info('4. Testing user lookup for Send From Email...');
            try {
                $verifySsl = config('services.microsoft.verify_ssl', true);
                $httpClient = Http::timeout(30);
                if (!$verifySsl) {
                    $httpClient = $httpClient->withoutVerifying();
                }
                
                $userEmail = $settings->send_from_email;
                $response = $httpClient->withToken($token)
                    ->get("https://graph.microsoft.com/v1.0/users/{$userEmail}");

                if ($response->successful()) {
                    $userData = $response->json();
                    $userId = $userData['id'] ?? null;
                    if ($userId) {
                        $this->info("   ✅ User found: {$userEmail} (ID: {$userId})");
                    } else {
                        $issues[] = "User ID not found in response for {$userEmail}";
                        $this->error("   ❌ User ID not found in response");
                    }
                } else {
                    $errorBody = $response->body();
                    $errorData = json_decode($errorBody, true);
                    $errorMessage = $errorData['error']['message'] ?? $errorData['error']['code'] ?? $errorBody;
                    $issues[] = "User not found: {$errorMessage}";
                    $this->error("   ❌ User not found: {$errorMessage}");
                    $this->warn("   💡 Make sure '{$userEmail}' exists in your Azure AD");
                }
            } catch (\Exception $e) {
                $issues[] = "Exception looking up user: " . $e->getMessage();
                $this->error("   ❌ Exception: " . $e->getMessage());
            }
        } else {
            $this->warn('   ⏭️  Skipping user lookup (missing token or send_from_email)');
        }

        $this->newLine();

        // Summary
        $this->info('📋 Summary:');
        if (empty($issues)) {
            $this->info('   ✅ No critical issues found!');
            if (!empty($warnings)) {
                $this->warn('   ⚠️  Warnings:');
                foreach ($warnings as $warning) {
                    $this->warn("      - {$warning}");
                }
            }
            $this->newLine();
            $this->info('💡 If emails still don\'t work, check:');
            $this->info('   1. Azure Portal → App registrations → API permissions');
            $this->info('   2. Ensure Mail.Send (Application permission) is granted with admin consent');
            $this->info('   3. Check Laravel logs: storage/logs/laravel.log');
        } else {
            $this->error('   ❌ Issues found:');
            foreach ($issues as $issue) {
                $this->error("      - {$issue}");
            }
            $this->newLine();
            $this->info('💡 Next steps:');
            $this->info('   1. Fix the issues listed above');
            $this->info('   2. Check EMAIL_TROUBLESHOOTING.md for detailed solutions');
            $this->info('   3. Verify Azure app has Mail.Send permission with admin consent');
        }

        return empty($issues) ? 0 : 1;
    }
}
