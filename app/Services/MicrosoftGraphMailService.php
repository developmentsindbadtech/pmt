<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class MicrosoftGraphMailService
{
    private string $clientId;
    private string $clientSecret;
    private string $tenantId;
    private bool $verifySsl;
    private ?string $accessToken = null;

    public function __construct()
    {
        $this->clientId = config('services.microsoft.client_id');
        $this->clientSecret = config('services.microsoft.client_secret');
        $this->tenantId = config('services.microsoft.tenant', 'common');
        $this->verifySsl = config('services.microsoft.verify_ssl', true);
    }
    
    /**
     * Get HTTP client with SSL verification settings
     */
    private function getHttpClient()
    {
        $client = Http::timeout(30);
        if (!$this->verifySsl) {
            $client = $client->withoutVerifying();
        }
        return $client;
    }

    /**
     * Get access token using client credentials flow
     * Cached for 50 minutes (tokens typically expire in 1 hour)
     */
    public function getAccessToken(): ?string
    {
        // Check instance cache first (for same request)
        if ($this->accessToken) {
            return $this->accessToken;
        }

        // Check Laravel cache (shared across requests)
        $cacheKey = 'microsoft_graph_access_token_' . md5($this->clientId . $this->tenantId);
        $cachedToken = Cache::get($cacheKey);
        if ($cachedToken) {
            $this->accessToken = $cachedToken;
            return $cachedToken;
        }

        try {
            $tenant = $this->tenantId === 'common' ? 'organizations' : $this->tenantId;
            $client = $this->getHttpClient();
            $response = $client->asForm()->post("https://login.microsoftonline.com/{$tenant}/oauth2/v2.0/token", [
                'client_id' => $this->clientId,
                'client_secret' => $this->clientSecret,
                'scope' => 'https://graph.microsoft.com/.default',
                'grant_type' => 'client_credentials',
            ]);

            if ($response->successful()) {
                $data = $response->json();
                $token = $data['access_token'] ?? null;
                
                if ($token) {
                    // Cache token for 50 minutes (3000 seconds) - tokens typically last 1 hour
                    // Subtract 10 minutes as safety margin
                    $expiresIn = ($data['expires_in'] ?? 3600) - 600;
                    Cache::put($cacheKey, $token, max(300, $expiresIn)); // Minimum 5 minutes cache
                    $this->accessToken = $token;
                    return $token;
                }
            }

            $errorBody = $response->body();
            $errorData = json_decode($errorBody, true);
            $errorMessage = $errorData['error_description'] ?? $errorData['error'] ?? $errorBody;
            
            Log::error('Failed to get Microsoft Graph access token', [
                'status' => $response->status(),
                'error' => $errorMessage,
                'full_response' => $errorBody,
            ]);
            return null;
        } catch (\Exception $e) {
            Log::error('Exception getting Microsoft Graph access token: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Send email via Microsoft Graph API
     */
    public function sendEmail(string $to, string $subject, string $htmlBody): bool
    {
        $token = $this->getAccessToken();
        if (! $token) {
            return false;
        }

        // Hardcoded sender email address
        $sendFromEmail = 'no-reply@sindbad.tech';

        try {
            // Get user ID from email
            $userId = $this->getUserIdByEmail($sendFromEmail);
            if (! $userId) {
                Log::error("User not found for email: {$sendFromEmail}");
                return false;
            }

            $client = $this->getHttpClient();
            $response = $client->withToken($token)
                ->post("https://graph.microsoft.com/v1.0/users/{$userId}/sendMail", [
                    'message' => [
                        'subject' => $subject,
                        'body' => [
                            'contentType' => 'HTML',
                            'content' => $htmlBody,
                        ],
                        'from' => [
                            'emailAddress' => [
                                'address' => $sendFromEmail,
                                'name' => 'Project Management Tool',
                            ],
                        ],
                        'toRecipients' => [
                            [
                                'emailAddress' => [
                                    'address' => $to,
                                ],
                            ],
                        ],
                    ],
                ]);

            if ($response->successful()) {
                Log::info('Email sent successfully via Microsoft Graph', [
                    'to' => $to,
                    'from' => $sendFromEmail,
                ]);
                return true;
            }

            $errorBody = $response->body();
            $errorData = json_decode($errorBody, true);
            $errorMessage = $errorData['error']['message'] ?? $errorData['error']['code'] ?? $errorBody;
            
            Log::error('Failed to send email via Microsoft Graph', [
                'status' => $response->status(),
                'to' => $to,
                'from' => $sendFromEmail,
                'error' => $errorMessage,
                'full_response' => $errorBody,
            ]);
            return false;
        } catch (\Exception $e) {
            Log::error('Exception sending email via Microsoft Graph: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Get user ID by email address
     */
    private function getUserIdByEmail(string $email): ?string
    {
        $token = $this->getAccessToken();
        if (! $token) {
            return null;
        }

        try {
            $client = $this->getHttpClient();
            $response = $client->withToken($token)
                ->get("https://graph.microsoft.com/v1.0/users/{$email}");

            if ($response->successful()) {
                $data = $response->json();
                return $data['id'] ?? null;
            }

            $errorBody = $response->body();
            $errorData = json_decode($errorBody, true);
            $errorMessage = $errorData['error']['message'] ?? $errorData['error']['code'] ?? $errorBody;
            
            Log::error("Failed to get user ID for email: {$email}", [
                'status' => $response->status(),
                'error' => $errorMessage,
                'full_response' => $errorBody,
            ]);
            
            return null;
        } catch (\Exception $e) {
            Log::error('Exception getting user ID: ' . $e->getMessage(), [
                'email' => $email,
                'exception' => $e,
            ]);
            return null;
        }
    }

    /**
     * Get user profile picture URL from Microsoft Graph
     */
    public function getUserPhotoUrl(string $email): ?string
    {
        $token = $this->getAccessToken();
        if (! $token) {
            return null;
        }

        try {
            $client = $this->getHttpClient();
            // Try to get photo metadata first
            $response = $client->withToken($token)
                ->get("https://graph.microsoft.com/v1.0/users/{$email}/photo");

            if ($response->successful()) {
                // Photo exists, return the URL endpoint
                return "https://graph.microsoft.com/v1.0/users/{$email}/photo/\$value";
            }

            return null;
        } catch (\Exception $e) {
            Log::debug('Exception getting user photo: ' . $e->getMessage(), [
                'email' => $email,
            ]);
            return null;
        }
    }

}
