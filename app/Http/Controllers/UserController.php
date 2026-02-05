<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\MicrosoftGraphMailService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;

class UserController extends Controller
{
    /**
     * Get user profile picture from Microsoft Graph (proxied with auth)
     * Optimized for performance with caching and error handling
     */
    public function getPhoto(Request $request, User $user): Response
    {
        if (!config('services.microsoft.client_id') || !config('services.microsoft.client_secret')) {
            abort(404);
        }

        if (!$user->email) {
            abort(404);
        }

        // Cache the photo for 1 hour (3600 seconds)
        // Use user ID and email hash to ensure cache invalidation if email changes
        $cacheKey = "user_photo_{$user->id}_" . md5($user->email);
        $photoData = Cache::remember($cacheKey, 3600, function () use ($user) {
            try {
                $graphService = app(MicrosoftGraphMailService::class);
                $token = $graphService->getAccessToken();
                
                if (!$token) {
                    \Log::debug("No access token available for user photo: {$user->email}");
                    return null;
                }

                $client = \Illuminate\Support\Facades\Http::timeout(10);
                if (!config('services.microsoft.verify_ssl', true)) {
                    $client = $client->withoutVerifying();
                }

                // Try to fetch photo
                $response = $client->withToken($token)
                    ->get("https://graph.microsoft.com/v1.0/users/{$user->email}/photo/\$value");

                if ($response->successful() && $response->body()) {
                    $contentType = $response->header('Content-Type', 'image/jpeg');
                    // Validate it's actually an image
                    if (strpos($contentType, 'image/') === 0) {
                        return [
                            'content' => $response->body(),
                            'contentType' => $contentType,
                        ];
                    }
                }
                
                // If photo doesn't exist (404), cache null for 24 hours to avoid repeated API calls
                if ($response->status() === 404) {
                    \Log::debug("Photo not found for user: {$user->email}");
                    // Cache null result for 24 hours to reduce API calls
                    Cache::put("user_photo_missing_{$user->id}", true, 86400);
                }
            } catch (\Exception $e) {
                \Log::debug('Failed to fetch user photo: ' . $e->getMessage(), [
                    'user_id' => $user->id,
                    'email' => $user->email,
                ]);
            }
            
            return null;
        });

        if (!$photoData) {
            // Return 404 with proper headers
            abort(404);
        }

        return response($photoData['content'], 200)
            ->header('Content-Type', $photoData['contentType'])
            ->header('Cache-Control', 'public, max-age=3600')
            ->header('ETag', md5($photoData['content'])); // Add ETag for better caching
    }
}
