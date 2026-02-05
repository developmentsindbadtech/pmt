@extends('layouts.app')

@section('title', 'Email Settings - PMT')

@section('content')
    <div class="mx-auto max-w-4xl px-4 sm:px-6 lg:px-8 py-4">
        <h1 class="text-xl font-semibold text-gray-900 mb-4">Email Settings</h1>
        <p class="text-sm text-gray-600 mb-6">Configure Microsoft Graph API email settings for @mention notifications. Emails will only be sent when enabled.</p>

        @if (session('success'))
            <div class="mb-3 rounded border border-green-200 bg-green-50 px-3 py-2 text-sm text-green-800">
                {{ session('success') }}
            </div>
        @endif

        @if (session('error'))
            <div class="mb-3 rounded border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-800">
                {{ session('error') }}
            </div>
        @endif

        <div class="rounded border border-gray-200 bg-white">
            <form action="{{ route('admin.email-settings.update') }}" method="POST" class="p-6">
                @csrf
                @method('PUT')
                
                <div class="mb-4">
                    <label class="flex items-center gap-2">
                        <input type="checkbox" name="enabled" value="1" id="enabled" {{ $settings->enabled ? 'checked' : '' }} class="rounded border-gray-300 text-blue-600 focus:ring-blue-500">
                        <span class="text-sm font-medium text-gray-700">Enable Email Notifications</span>
                    </label>
                    <p class="mt-1 text-xs text-gray-500">When enabled, users will receive email notifications when they are @mentioned.</p>
                </div>

                <div class="space-y-4">
                    <div class="rounded border border-blue-200 bg-blue-50 p-4">
                        <p class="text-sm text-blue-900 mb-2"><strong>Microsoft Graph API Configuration</strong></p>
                        <p class="text-xs text-blue-800 mb-4">Uses your existing Azure app credentials (MICROSOFT_CLIENT_ID, MICROSOFT_CLIENT_SECRET, MICROSOFT_TENANT_ID) and Mail.Send permission. This covers all users in your organization.</p>
                        
                        <div class="mb-4">
                            <label for="send_from_email" class="block text-sm font-medium text-gray-700 mb-1">
                                Send From Email <span class="text-red-500">*</span>
                            </label>
                            <input type="email" name="send_from_email" id="send_from_email" value="{{ old('send_from_email', $settings->send_from_email) }}" placeholder="user@yourdomain.com" required class="w-full rounded border border-gray-300 px-3 py-2 text-sm focus:border-blue-500 focus:ring-blue-500">
                            <p class="mt-1 text-xs text-gray-500">Email address of the user account to send emails from (must exist in your Azure AD)</p>
                        </div>

                        <div class="grid grid-cols-2 gap-4">
                            <div>
                                <label for="from_address" class="block text-sm font-medium text-gray-700 mb-1">From Email Address (Optional)</label>
                                <input type="email" name="from_address" id="from_address" value="{{ old('from_address', $settings->from_address) }}" placeholder="noreply@yourdomain.com" class="w-full rounded border border-gray-300 px-3 py-2 text-sm focus:border-blue-500 focus:ring-blue-500">
                                <p class="mt-1 text-xs text-gray-500">Display email address in email headers</p>
                            </div>
                            <div>
                                <label for="from_name" class="block text-sm font-medium text-gray-700 mb-1">From Name (Optional)</label>
                                <input type="text" name="from_name" id="from_name" value="{{ old('from_name', $settings->from_name) }}" placeholder="Project Management Tool" class="w-full rounded border border-gray-300 px-3 py-2 text-sm focus:border-blue-500 focus:ring-blue-500">
                                <p class="mt-1 text-xs text-gray-500">Display name in email headers</p>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="mt-6 flex gap-3">
                    <button type="submit" class="rounded bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700">
                        Save Settings
                    </button>
                    <form action="{{ route('admin.email-settings.test') }}" method="POST" class="inline" onsubmit="this.querySelector('button').disabled=true; this.querySelector('button').textContent='Sending...'; return true;">
                        @csrf
                        <button type="submit" class="rounded border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">
                            Send Test Email
                        </button>
                    </form>
                </div>
            </form>
        </div>

        <div class="mt-6 rounded border border-blue-200 bg-blue-50 p-4">
            <h3 class="text-sm font-semibold text-blue-900 mb-2">Microsoft Graph API Setup</h3>
            <div class="space-y-2 text-xs text-blue-800">
                <div><strong>Requirements:</strong></div>
                <ul class="list-disc list-inside ml-2 space-y-1">
                    <li>Azure app registered with <code class="bg-blue-100 px-1 rounded">Mail.Send</code> application permission</li>
                    <li>Admin consent granted for <code class="bg-blue-100 px-1 rounded">Mail.Send</code> permission</li>
                    <li><code class="bg-blue-100 px-1 rounded">MICROSOFT_CLIENT_ID</code>, <code class="bg-blue-100 px-1 rounded">MICROSOFT_CLIENT_SECRET</code>, and <code class="bg-blue-100 px-1 rounded">MICROSOFT_TENANT_ID</code> configured in <code class="bg-blue-100 px-1 rounded">.env</code></li>
                    <li>Send From Email must be a valid user email in your Azure AD</li>
                </ul>
            </div>
        </div>
    </div>

    <script>
        // Console logging for debugging
        console.log('%cEmail Settings Page Loaded', 'color: #3b82f6; font-weight: bold; font-size: 14px;');
        console.log('Page URL:', window.location.href);
        console.log('Timestamp:', new Date().toISOString());

        // Log session messages if present
        @if (session('success'))
            console.log('%c✓ Success:', 'color: #10b981; font-weight: bold;', '{{ session('success') }}');
        @endif

        @if (session('error'))
            console.error('%c✗ Error:', 'color: #ef4444; font-weight: bold;', '{{ session('error') }}');
        @endif

        // Log current settings
        console.log('Current Email Settings:', {
            enabled: {{ $settings->enabled ? 'true' : 'false' }},
            sendFromEmail: '{{ $settings->send_from_email ?? 'Not set' }}',
            fromAddress: '{{ $settings->from_address ?? 'Not set' }}',
            fromName: '{{ $settings->from_name ?? 'Not set' }}'
        });

        // Form submission logging
        const saveForm = document.querySelector('form[action*="email-settings.update"]');
        if (saveForm) {
            saveForm.addEventListener('submit', function(e) {
                const formData = new FormData(this);
                console.log('%c📝 Saving Email Settings...', 'color: #3b82f6; font-weight: bold;');
                console.log('Form Data:', {
                    enabled: formData.get('enabled') === '1',
                    send_from_email: formData.get('send_from_email'),
                    from_address: formData.get('from_address'),
                    from_name: formData.get('from_name')
                });
            });
        }

        // Test email button logging
        const testForm = document.querySelector('form[action*="email-settings.test"]');
        if (testForm) {
            testForm.addEventListener('submit', function(e) {
                console.log('%c📧 Sending Test Email...', 'color: #8b5cf6; font-weight: bold;');
                console.log('Test email will be sent to your account');
                
                const button = this.querySelector('button');
                const originalText = button.textContent;
                
                // Monitor for errors
                window.addEventListener('error', function(e) {
                    console.error('JavaScript Error:', e.error);
                });
            });
        }

        // Log any JavaScript errors
        window.addEventListener('error', function(e) {
            console.error('%cJavaScript Error:', 'color: #ef4444; font-weight: bold;', {
                message: e.message,
                filename: e.filename,
                lineno: e.lineno,
                colno: e.colno,
                error: e.error
            });
        });

        // Log unhandled promise rejections
        window.addEventListener('unhandledrejection', function(e) {
            console.error('%cUnhandled Promise Rejection:', 'color: #ef4444; font-weight: bold;', e.reason);
        });

        console.log('%cConsole debugging enabled. Check this console for form submissions and errors.', 'color: #6b7280; font-style: italic;');
    </script>
@endsection
