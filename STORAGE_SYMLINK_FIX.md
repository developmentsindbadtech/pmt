# Storage Symlink Fix - 404 Errors on Images

## Problem
Images uploaded to items are returning 404 errors on production:
- URL: `https://pmt.sindbad.tech/storage/item-attachments/11/QWwNsrFw.png`
- Error: `404 Not Found`

## Root Cause
The storage symlink is missing. Laravel stores files in `storage/app/public/` but serves them via `public/storage/`. A symlink must exist: `public/storage -> storage/app/public`

## Immediate Fix (Run on Production Server)

```bash
# SSH into production server
# Navigate to project directory
cd /var/www/pmt  # or your project path

# Create the storage symlink
php artisan storage:link

# Verify it was created
ls -la public/storage
# Should show: public/storage -> ../storage/app/public

# Set proper permissions
chmod -R 775 storage/app/public
chown -R www-data:www-data storage/app/public

# Verify files exist
ls -la storage/app/public/item-attachments/
```

## Verification

After running the fix:
1. Check if symlink exists: `ls -la public/storage`
2. Try accessing an uploaded image URL in browser
3. Check Laravel logs: `tail -f storage/logs/laravel.log`

## Prevention

The `php artisan storage:link` command has been added to all deployment documentation:
- `DEPLOYMENT.md`
- `GCP_DEPLOYMENT.md`
- `FINAL_DEPLOYMENT_READY.md`

Ensure this command runs during every deployment.

## Additional Notes

- The symlink only needs to be created once, but it should be included in deployment scripts
- If the symlink is deleted, files will still exist in `storage/app/public/` but won't be accessible via web
- For multi-instance deployments (Cloud Run), ensure the symlink is created in the Dockerfile or deployment script
