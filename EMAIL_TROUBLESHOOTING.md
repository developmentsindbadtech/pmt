# Email Troubleshooting Guide

## Your .env File Configuration

Your `.env` file should have the Microsoft credentials configured:
```
MICROSOFT_CLIENT_ID=your-client-id-here
MICROSOFT_CLIENT_SECRET=your-client-secret-here
MICROSOFT_TENANT_ID=your-tenant-id-here
```

**✅ These are already set correctly - no need to add anything else to .env!**

## Common Issues & Solutions

### Issue 1: Azure App Missing Mail.Send Permission

**Symptom:** Test email fails silently or returns "Insufficient privileges"

**Solution:**
1. Go to [Azure Portal](https://portal.azure.com)
2. Navigate to **Azure Active Directory** → **App registrations**
3. Find your app (Client ID: `your-client-id-here`)
4. Go to **API permissions**
5. Click **+ Add a permission**
6. Select **Microsoft Graph**
7. Select **Application permissions** (NOT Delegated)
8. Search for and select **Mail.Send**
9. Click **Add permissions**
10. **IMPORTANT:** Click **Grant admin consent for [Your Organization]**
11. Wait 5-10 minutes for permissions to propagate

### Issue 2: "Send From Email" User Doesn't Exist in Azure AD

**Symptom:** Error in logs: "User not found for email: emmanuel.galleto@sindbad.tech"

**Solution:**
1. Verify the email `emmanuel.galleto@sindbad.tech` exists as a user in your Azure AD
2. Go to [Azure Portal](https://portal.azure.com) → **Azure Active Directory** → **Users**
3. Search for the email address
4. If it doesn't exist, create it or use a different email that exists

### Issue 3: Admin Consent Not Granted

**Symptom:** Access token fails or returns "insufficient privileges"

**Solution:**
1. Go to Azure Portal → **App registrations** → Your app
2. Go to **API permissions**
3. Look for **Mail.Send** permission
4. Check if it shows "Granted for [Your Organization]" with a green checkmark
5. If not, click **Grant admin consent**

### Issue 4: Wrong Permission Type

**Symptom:** Permission exists but still doesn't work

**Solution:**
- Make sure **Mail.Send** is added as an **Application permission** (not Delegated)
- Application permissions allow the app to send emails on behalf of any user
- Delegated permissions only work when a user is logged in

## How to Check What's Wrong

### Step 1: Check Laravel Logs

```bash
# Windows PowerShell
cd d:\Repository\SindbadTech\PMT\pmt
Get-Content storage/logs/laravel.log -Tail 100 | Select-String "Microsoft Graph|Failed|Error"
```

Look for:
- "Failed to get Microsoft Graph access token" - Credentials or permissions issue
- "User not found for email" - The send-from email doesn't exist
- "Failed to send email via Microsoft Graph" - Permission or API issue

### Step 2: Test via Browser Console

1. Open Email Settings page: `http://localhost:8000/admin/email-settings`
2. Open Developer Tools (F12)
3. Go to **Network** tab
4. Click **Send Test Email**
5. Look for the POST request to `/admin/email-settings/test`
6. Check the response - it will show success or error message

### Step 3: Verify Azure App Configuration

1. Go to [Azure Portal](https://portal.azure.com)
2. **Azure Active Directory** → **App registrations**
3. Find app with Client ID: `your-client-id-here`
4. Check **API permissions**:
   - Should have **Mail.Send** (Application permission)
   - Should show "Granted for [Your Organization]"
5. Check **Certificates & secrets**:
   - Verify the Client Secret matches your `.env` file
   - If expired, create a new one and update `.env`

## Quick Diagnostic Checklist

- [ ] `.env` has `MICROSOFT_CLIENT_ID`, `MICROSOFT_CLIENT_SECRET`, `MICROSOFT_TENANT_ID`
- [ ] Azure app has **Mail.Send** application permission
- [ ] Admin consent is granted for Mail.Send
- [ ] "Send From Email" (`emmanuel.galleto@sindbad.tech`) exists in Azure AD
- [ ] Email settings are enabled in the admin panel
- [ ] Check Laravel logs for specific error messages

## Still Not Working?

Run this command to see the latest errors:
```bash
cd d:\Repository\SindbadTech\PMT\pmt
Get-Content storage/logs/laravel.log -Tail 200 | Select-String -Pattern "Microsoft|Email|Failed|Error" -Context 3
```

Then check:
1. The exact error message in the logs
2. Azure Portal → App registrations → Your app → API permissions
3. Verify the "Send From Email" user exists in Azure AD
