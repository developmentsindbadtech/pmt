<?php

namespace App\Http\Controllers;

use App\Models\User;
use GuzzleHttp\Exception\ClientException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function showLogin(Request $request)
    {
        if (Auth::check()) {
            return redirect()->route('boards.index');
        }
        return view('auth.login');
    }

    public function redirectToMicrosoft(Request $request)
    {
        if (! config('services.microsoft.client_id') || ! config('services.microsoft.client_secret')) {
            return redirect()->route('login')
                ->withErrors(['microsoft' => 'Microsoft SSO is not configured.']);
        }

        $tenant = config('services.microsoft.tenant', 'common');
        $guzzle = ['tenant' => $tenant];
        if (! config('services.microsoft.verify_ssl', true)) {
            $guzzle['verify'] = false;
        }

        $provider = new \App\Socialite\MicrosoftProvider(
            $request,
            config('services.microsoft.client_id'),
            config('services.microsoft.client_secret'),
            config('services.microsoft.redirect'),
            $guzzle
        );
        $provider->setTenant($tenant);

        return $provider->redirect();
    }

    public function handleMicrosoftCallback(Request $request)
    {
        if ($request->has('error')) {
            return redirect()->route('login')
                ->withErrors(['microsoft' => 'Authentication failed: '.$request->get('error_description', $request->get('error'))]);
        }

        if (! $request->has('code')) {
            return redirect()->route('login')
                ->withErrors(['microsoft' => 'Authorization code not received. Please try again.']);
        }

        if (! config('services.microsoft.client_id') || ! config('services.microsoft.client_secret')) {
            return redirect()->route('login')
                ->withErrors(['microsoft' => 'Microsoft SSO is not configured.']);
        }

        $tenant = config('services.microsoft.tenant', 'common');
        $guzzle = ['tenant' => $tenant];
        if (! config('services.microsoft.verify_ssl', true)) {
            $guzzle['verify'] = false;
        }

        $provider = new \App\Socialite\MicrosoftProvider(
            $request,
            config('services.microsoft.client_id'),
            config('services.microsoft.client_secret'),
            config('services.microsoft.redirect'),
            $guzzle
        );
        $provider->setTenant($tenant);

        try {
            $microsoftUser = $provider->user();
        } catch (ClientException $e) {
            $body = $e->getResponse() ? $e->getResponse()->getBody()->getContents() : '';
            $data = json_decode($body, true);
            $msg = $data['error_description'] ?? $data['error'] ?? 'Authentication failed';
            return redirect()->route('login')->withErrors(['microsoft' => $msg]);
        }

        $email = $microsoftUser->getEmail();
        if (! $email) {
            return redirect()->route('login')
                ->withErrors(['microsoft' => 'Unable to retrieve email from Microsoft account.']);
        }

        $email = strtolower(trim($email));

        $raw = $microsoftUser->getRaw();
        $employeeType = isset($raw['employeeType']) ? trim((string) $raw['employeeType']) : '';
        $isAdmin = strtolower($employeeType) === 'admin';

        $user = User::whereRaw('LOWER(email) = ?', [$email])->first();

        if (! $user) {
            $user = User::create([
                'name' => $microsoftUser->getName() ?: $email,
                'email' => $email,
                'password' => Hash::make(bin2hex(random_bytes(16))),
                'is_admin' => $isAdmin,
            ]);
        } else {
            $user->update(['is_admin' => $isAdmin]);
        }

        Auth::login($user, true);
        $request->session()->regenerate();

        return redirect()->intended(route('boards.index'));
    }

    public function logout(Request $request)
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
        return redirect()->route('login');
    }
}
