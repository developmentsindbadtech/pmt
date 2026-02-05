# Email Testing Debug Guide

## Step 1: Check Laravel Logs

Open your Laravel log file to see detailed error messages:
```
storage/logs/laravel.log
```

Look for entries containing:
- "Microsoft Graph"
- "Failed to send email"
- "Failed to get Microsoft Graph access token"
- "User not found for email"

## Step 2: Test via Browser Console

1. Open Email Settings page: `http://localhost:8000/admin/email-settings`
2. Open Browser Developer Tools (F12)
3. Go to "Network" tab
4. Click "Send Test Email" button
5. Look for the POST request to `/admin/email-settings/test`
6. Check the response - it should redirect with a success or error message

## Step 3: Common Issues

### Issue 1: "Send From Email" doesn't exist in Azure AD
- **Symptom**: Error in logs: "User not found for email"
- **Solution**: Make sure `no-reply@sindbad.tech` exists as a user in your Azure AD

### Issue 2: Access Token Failed
- **Symptom**: Error in logs: "Failed to get Microsoft Graph access token"
- **Solution**: 
  - Verify `MICROSOFT_CLIENT_ID`, `MICROSOFT_CLIENT_SECRET`, `MICROSOFT_TENANT_ID` in `.env`
  - Make sure `Mail.Send` permission has admin consent granted

### Issue 3: Permission Denied
- **Symptom**: Error: "Insufficient privileges to complete the operation"
- **Solution**: Ensure `Mail.Send` application permission is granted with admin consent

## Step 4: Manual Test via Tinker

Run this command to test directly:
```bash
php artisan tinker
```

Then run:
```php
$service = app(\App\Services\MicrosoftGraphMailService::class);
$result = $service->sendEmail(
    'your-email@sindbad.tech',  // Your email
    'Test Subject',
    '<p>Test body</p>',
    'no-reply@sindbad.tech'  // Send from email
);
var_dump($result);
```
