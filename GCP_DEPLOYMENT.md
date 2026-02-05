# GCP Deployment Guide - PMT Application

## Overview
This application runs on **Google Cloud Platform (GCP)** with the following infrastructure:
- **Staging**: `pmt.alladintechstg.buzz`
- **Production**: `pmt.sindbad.tech`

## GCP-Specific Configuration

### Critical Environment Variables for GCP

```env
# REQUIRED: Trust GCP Load Balancer headers
TRUSTED_PROXIES=*

# HTTPS Configuration
APP_URL=https://pmt.alladintechstg.buzz  # staging
APP_URL=https://pmt.sindbad.tech  # production
SESSION_SECURE_COOKIE=true

# Session Driver (Required for multi-instance scaling)
SESSION_DRIVER=database  # or redis
# Database sessions work across multiple instances
# Redis sessions require GCP Memorystore Redis

# Cache Driver (Recommended: Redis via Memorystore)
CACHE_DRIVER=redis
REDIS_HOST=10.x.x.x  # Memorystore Redis internal IP
REDIS_PASSWORD=your-redis-auth-string
REDIS_PORT=6379

# Microsoft SSO
MICROSOFT_CLIENT_ID=your_client_id
MICROSOFT_CLIENT_SECRET=your_client_secret
MICROSOFT_REDIRECT_URI=https://pmt.alladintechstg.buzz/auth/microsoft/callback  # staging
MICROSOFT_REDIRECT_URI=https://pmt.sindbad.tech/auth/microsoft/callback  # production
MICROSOFT_TENANT_ID=common
```

## Why TRUSTED_PROXIES=* is Critical

When your Laravel app runs behind GCP's HTTP(S) Load Balancer:
- The load balancer adds `X-Forwarded-For`, `X-Forwarded-Proto`, `X-Forwarded-Host` headers
- Laravel needs to trust these headers to:
  - Detect HTTPS correctly (for secure cookies, CSRF)
  - Get correct client IP addresses
  - Generate correct URLs

**Without `TRUSTED_PROXIES=*`:**
- Sessions may not work correctly
- CSRF tokens may fail
- URLs may be generated incorrectly
- HTTPS detection may fail

## GCP Deployment Options

### Option 1: Compute Engine VM

**Deployment Steps:**
```bash
# SSH into VM
gcloud compute ssh your-vm-name --zone=your-zone

# Navigate to project directory
cd /path/to/pmt

# Pull latest code
git pull origin main

# Install dependencies
composer install --no-dev --optimize-autoloader
npm ci && npm run build

# Run migrations
php artisan migrate --force

# Clear and rebuild cache
php artisan cache:clear
php artisan config:cache
php artisan route:cache
php artisan view:cache

# Restart services
sudo systemctl restart php8.2-fpm
sudo systemctl restart nginx
```

**Environment Variables:**
- Set in `.env` file on the VM
- Or use GCP Secret Manager and load into `.env`

### Option 2: Cloud Run

**Deployment Steps:**
```bash
# Build and deploy
gcloud builds submit --tag gcr.io/your-project/pmt
gcloud run deploy pmt \
  --image gcr.io/your-project/pmt \
  --platform managed \
  --region us-central1 \
  --set-env-vars "APP_URL=https://pmt.sindbad.tech,TRUSTED_PROXIES=*" \
  --set-secrets "MICROSOFT_CLIENT_ID=microsoft-client-id:latest,MICROSOFT_CLIENT_SECRET=microsoft-secret:latest"
```

**Environment Variables:**
- Set via Cloud Run service configuration
- Use Secret Manager for sensitive values
- Ensure `TRUSTED_PROXIES=*` is set

### Option 3: App Engine

**Deployment Steps:**
```bash
# Deploy
gcloud app deploy

# Set environment variables in app.yaml or via:
gcloud app deploy --set-env-vars "TRUSTED_PROXIES=*,APP_URL=https://pmt.sindbad.tech"
```

## GCP Memorystore Redis Setup

### Why Use Memorystore Redis?
- **Performance**: Faster than database caching
- **Scalability**: Works across multiple instances
- **Session Storage**: Shared sessions across instances
- **Photo Caching**: Critical for 20+ users performance

### Setup Steps:

1. **Create Memorystore Redis Instance:**
```bash
gcloud redis instances create pmt-redis \
  --size=1 \
  --region=us-central1 \
  --network=projects/your-project/global/networks/default \
  --redis-version=REDIS_7_0
```

2. **Get Connection Details:**
```bash
gcloud redis instances describe pmt-redis --region=us-central1
```

3. **Configure .env:**
```env
CACHE_DRIVER=redis
SESSION_DRIVER=redis
REDIS_HOST=10.x.x.x  # Internal IP from step 2
REDIS_PASSWORD=your-auth-string  # If AUTH enabled
REDIS_PORT=6379
```

4. **Test Connection:**
```bash
php artisan tinker
>>> Cache::put('test', 'value', 60);
>>> Cache::get('test');
```

## Load Balancer Configuration

### SSL Certificates
- Ensure SSL certificates are configured for both domains
- Staging: `pmt.alladintechstg.buzz`
- Production: `pmt.sindbad.tech`

### Health Checks
- Configure health check endpoint: `/` or `/health`
- Ensure health checks pass before traffic routing

### Backend Configuration
- Point load balancer to your Compute Engine instances or Cloud Run service
- Ensure backend services are healthy

## Database Configuration

### Cloud SQL (PostgreSQL/MySQL)
- Use Cloud SQL for production database
- Configure connection via `.env`:
```env
DB_CONNECTION=pgsql  # or mysql
DB_HOST=/cloudsql/project:region:instance  # Cloud SQL socket
DB_DATABASE=pmt
DB_USERNAME=your_user
DB_PASSWORD=your_password
```

## Monitoring & Logging

### Cloud Logging
- Application logs: `storage/logs/laravel.log`
- View in GCP Console: Logging → Logs Explorer
- Filter by: `resource.type="gce_instance"` or `resource.type="cloud_run_revision"`

### Error Monitoring
- Check for Microsoft Graph API errors
- Monitor cache hit rates
- Watch for 404s on photo endpoints

### Performance Monitoring
- Use Cloud Monitoring for:
  - Response times
  - Request rates
  - Error rates
  - Cache performance

## Security Considerations

### Secret Management
- Use **GCP Secret Manager** for sensitive values:
  - `MICROSOFT_CLIENT_SECRET`
  - Database passwords
  - Redis passwords

### IAM Roles
- Ensure service account has necessary permissions:
  - Cloud SQL Client (for database)
  - Secret Manager Secret Accessor (for secrets)
  - Cloud Storage (if using for file storage)

### Network Security
- Use VPC for internal communication
- Configure firewall rules appropriately
- Use private IPs for Memorystore Redis

## Troubleshooting GCP-Specific Issues

### Issue: Sessions Not Working
**Solution:**
- Verify `TRUSTED_PROXIES=*` is set
- Check `SESSION_DRIVER=database` or `redis`
- Ensure `SESSION_SECURE_COOKIE=true` for HTTPS

### Issue: HTTPS Not Detected
**Solution:**
- Set `TRUSTED_PROXIES=*`
- Verify load balancer is forwarding `X-Forwarded-Proto` header
- Check `APP_URL` uses `https://`

### Issue: Cache Not Working Across Instances
**Solution:**
- Use `CACHE_DRIVER=redis` with Memorystore
- Or use `CACHE_DRIVER=database` (slower but works)
- Never use `CACHE_DRIVER=file` in production

### Issue: Photo Loading Slow
**Solution:**
- Verify Redis cache is working
- Check Memorystore Redis connection
- Monitor cache hit rates
- Ensure `CACHE_DRIVER=redis` is set

## Quick Deployment Checklist

### Pre-Deployment
- [ ] Code pushed to repository
- [ ] `.env` configured with GCP-specific variables
- [ ] `TRUSTED_PROXIES=*` set
- [ ] Memorystore Redis configured (or database cache)
- [ ] Azure redirect URIs added

### Deployment
- [ ] Pull latest code
- [ ] Install dependencies
- [ ] Run migrations
- [ ] Clear and rebuild cache
- [ ] Restart services

### Post-Deployment
- [ ] Test SSO login
- [ ] Verify profile pictures load
- [ ] Check sessions work
- [ ] Monitor logs for errors
- [ ] Test with multiple users

## Support Resources

- **GCP Documentation**: https://cloud.google.com/docs
- **Laravel on GCP**: https://laravel.com/docs/deployment
- **Memorystore Redis**: https://cloud.google.com/memorystore/docs/redis
- **Cloud Run**: https://cloud.google.com/run/docs
- **Compute Engine**: https://cloud.google.com/compute/docs
