<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class EmailSettingsController extends Controller
{
    public function index(Request $request): View
    {
        if (! $request->user()->is_admin) {
            abort(403, 'Only administrators can access email settings.');
        }

        $settings = DB::table('email_settings')->first();
        
        return view('admin.email-settings', [
            'settings' => $settings,
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        if (! $request->user()->is_admin) {
            abort(403, 'Only administrators can update email settings.');
        }

        $validated = $request->validate([
            'send_from_email' => 'required|email|max:255',
            'from_address' => 'nullable|email|max:255',
            'from_name' => 'nullable|string|max:255',
            'enabled' => 'boolean',
        ]);

        DB::table('email_settings')->update([
            'mailer' => 'microsoft_graph',
            'send_from_email' => $validated['send_from_email'],
            'from_address' => $validated['from_address'] ?? null,
            'from_name' => $validated['from_name'] ?? null,
            'enabled' => $validated['enabled'] ?? false,
            'updated_at' => now(),
        ]);

        return redirect()->route('admin.email-settings.index')
            ->with('success', 'Email settings updated successfully.');
    }

    public function test(Request $request): RedirectResponse
    {
        if (! $request->user()->is_admin) {
            abort(403, 'Only administrators can test email.');
        }

        $settings = DB::table('email_settings')->first();
        
        if (! $settings || ! $settings->enabled) {
            return redirect()->route('admin.email-settings.index')
                ->with('error', 'Email is not enabled. Please configure and enable email settings first.');
        }

        if (! $settings->send_from_email) {
            return redirect()->route('admin.email-settings.index')
                ->with('error', 'Send From Email is required. Please configure it first.');
        }

        try {
            // Validate Microsoft credentials are configured
            if (!config('services.microsoft.client_id') || !config('services.microsoft.client_secret')) {
                return redirect()->route('admin.email-settings.index')
                    ->with('error', 'Microsoft credentials not configured. Please set MICROSOFT_CLIENT_ID and MICROSOFT_CLIENT_SECRET in your .env file.');
            }

            $graphService = app(\App\Services\MicrosoftGraphMailService::class);
            $htmlBody = '<p>This is a test email from Project Management Tool. If you received this, your Microsoft Graph email configuration is working correctly!</p>';
            
            \Log::info('Attempting to send test email', [
                'to' => $request->user()->email,
                'from' => $settings->send_from_email,
            ]);
            
            $success = $graphService->sendEmail(
                $request->user()->email,
                'Test Email - PMT',
                $htmlBody,
                $settings->send_from_email
            );
            
            if ($success) {
                return redirect()->route('admin.email-settings.index')
                    ->with('success', 'Test email sent successfully via Microsoft Graph! Check your inbox at ' . $request->user()->email);
            } else {
                // Try to get more specific error from recent logs
                $logFile = storage_path('logs/laravel.log');
                $recentErrors = [];
                if (file_exists($logFile)) {
                    $logContent = file_get_contents($logFile);
                    $lines = explode("\n", $logContent);
                    $recentLines = array_slice($lines, -50); // Last 50 lines
                    foreach ($recentLines as $line) {
                        if (stripos($line, 'Microsoft Graph') !== false || 
                            stripos($line, 'Failed') !== false || 
                            stripos($line, 'Error') !== false) {
                            $recentErrors[] = $line;
                        }
                    }
                }
                
                $errorMessage = 'Failed to send test email via Microsoft Graph. ';
                if (!empty($recentErrors)) {
                    $errorMessage .= 'Recent errors: ' . implode(' | ', array_slice($recentErrors, -3));
                } else {
                    $errorMessage .= 'Common issues: 1) Send From Email does not exist in Azure AD, 2) Mail.Send permission not properly granted with admin consent, 3) Invalid credentials. Check Laravel logs (storage/logs/laravel.log) for details.';
                }
                
                return redirect()->route('admin.email-settings.index')
                    ->with('error', $errorMessage);
            }
        } catch (\Exception $e) {
            \Log::error('Exception in test email: ' . $e->getMessage(), [
                'exception' => $e,
                'trace' => $e->getTraceAsString(),
            ]);
            
            return redirect()->route('admin.email-settings.index')
                ->with('error', 'Failed to send test email: ' . $e->getMessage() . ' Check Laravel logs for more details.');
        }
    }
}
