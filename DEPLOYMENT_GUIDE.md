# Sentry System — Deployment Guide

**Version:** Phase 2 Visitor Module (Logical Testing Complete)  
**Date:** 2026-08-05  
**Status:** ✅ Ready for Production Deployment

---

## Pre-Deployment Security Checklist

### ✅ Sensitive Data Protection

- [x] `.env` file is in `.gitignore` (no production secrets in git)
- [x] `.env.backup` and `.env.production` are ignored
- [x] `/credentials` directory excluded from git
- [x] `credentials/service-account.json` removed from git history (was tracked, now fixed)
- [x] No API keys, tokens, or passwords in code comments
- [x] No plaintext secrets in database seeders
- [x] `auth.json` (Composer auth) ignored
- [x] IDE configs (`.vscode`, `.idea`) ignored

### ✅ Code Quality

- [x] No hardcoded database credentials
- [x] No hardcoded API keys in routes or controllers
- [x] Environment variables used for all secrets
- [x] Configuration centralized in `config/` directory

### ✅ Git History

- [x] No sensitive files in current tracked files
- [x] Credentials removed from git history
- [x] Ready for public repository (if needed)

---

## Deployment Steps

### 1. Prepare Live Server Environment

#### 1.1 Directory Structure
```bash
# Create application directory
mkdir -p /var/www/sentry
cd /var/www/sentry

# Clone repository
git clone <your-repo-url> .

# Create required directories
mkdir -p storage/app/public
mkdir -p storage/logs
mkdir -p bootstrap/cache
mkdir -p credentials
mkdir -p documents
```

#### 1.2 Set File Permissions
```bash
# Set correct ownership
sudo chown -R www-data:www-data /var/www/sentry

# Set directory permissions
chmod -R 755 /var/www/sentry
chmod -R 775 /var/www/sentry/storage
chmod -R 775 /var/www/sentry/bootstrap/cache
chmod -R 775 /var/www/sentry/storage/logs
```

### 2. Configure Environment (.env)

#### 2.1 Copy Example and Configure
```bash
cp .env.example .env
```

#### 2.2 Production .env Settings
```env
# Application
APP_NAME="Sentry System"
APP_ENV=production
APP_DEBUG=false
APP_URL=https://yourdomain.com/sentry

# Database (configure for your live database)
DB_CONNECTION=mysql
DB_HOST=your-live-db-host
DB_PORT=3306
DB_DATABASE=your_production_db_name
DB_USERNAME=db_user
DB_PASSWORD=secure_password_here

# Cache & Session
CACHE_DRIVER=redis
SESSION_DRIVER=database
QUEUE_CONNECTION=sync

# Mail (if needed)
MAIL_MAILER=smtp
MAIL_HOST=smtp.mailtrap.io
MAIL_PORT=465
MAIL_USERNAME=your_email
MAIL_PASSWORD=your_password
MAIL_FROM_ADDRESS=noreply@yourdomain.com

# API Keys
SYNC_API_KEY=generate_secure_random_key_here
APP_KEY=generate_via_artisan_below

# Google Sheets Integration
SENTRY_VISITORS_ID=your_google_sheets_spreadsheet_id
```

#### 2.3 Generate APP_KEY
```bash
php artisan key:generate
```

### 3. Install Dependencies

```bash
# Install Composer dependencies
composer install --no-dev --optimize-autoloader

# Install Node dependencies (if needed for frontend builds)
npm install --production
```

### 4. Setup Google Credentials

#### 4.1 Upload Service Account JSON
```bash
# Place your service account JSON file
# (obtained from Google Cloud Console)
cp /path/to/service-account.json /var/www/sentry/credentials/

# Secure the file
chmod 600 /var/www/sentry/credentials/service-account.json
```

**⚠️ CRITICAL:** Never commit this file to git. It's in `.gitignore` and excluded from all commits.

#### 4.2 Verify Configuration
```bash
# Test that the configuration can load the file
php artisan tinker
> config('sentry.google.credentials_path')
> file_exists(config('sentry.google.credentials_path'))
```

### 5. Setup Database

#### 5.1 Run Migrations
```bash
php artisan migrate --force
```

#### 5.2 Run Seeders (populate reference data)
```bash
# Seed all Phase 1 data
php artisan db:seed --force

# Or run specific seeders
php artisan db:seed --class=PermissionSeeder --force
php artisan db:seed --class=IdentityTypeSeeder --force
php artisan db:seed --class=VisitorTypeSeeder --force
```

### 6. Setup Storage & Public Disk

#### 6.1 Create Storage Link
```bash
# Link public disk for file access
php artisan storage:link
```

This creates `public/storage` → `storage/app/public` symlink.

#### 6.2 Verify File Structure
```bash
ls -l public/storage  # Should be a symlink
```

### 7. Setup Web Server Configuration

#### 7.1 Apache Configuration
```apache
<VirtualHost *:443>
    ServerName yourdomain.com
    ServerAlias www.yourdomain.com
    
    DocumentRoot /var/www/sentry/public
    
    <Directory /var/www/sentry/public>
        AllowOverride All
        Require all granted
        
        # Laravel routing
        <IfModule mod_rewrite.c>
            RewriteEngine On
            RewriteCond %{REQUEST_FILENAME} !-f
            RewriteCond %{REQUEST_FILENAME} !-d
            RewriteRule ^ index.php [L]
        </IfModule>
    </Directory>
    
    <FilesMatch \.php$>
        SetHandler application/x-httpd-php-source
    </FilesMatch>
    
    # Security headers
    Header always set X-Frame-Options "SAMEORIGIN"
    Header always set X-Content-Type-Options "nosniff"
    Header always set X-XSS-Protection "1; mode=block"
    
    # SSL/TLS
    SSLEngine on
    SSLCertificateFile /path/to/certificate.crt
    SSLCertificateKeyFile /path/to/private.key
    SSLCertificateChainFile /path/to/chain.crt
</VirtualHost>

# Redirect HTTP to HTTPS
<VirtualHost *:80>
    ServerName yourdomain.com
    ServerAlias www.yourdomain.com
    Redirect permanent / https://yourdomain.com/
</VirtualHost>
```

#### 7.2 Nginx Configuration
```nginx
server {
    listen 80;
    server_name yourdomain.com www.yourdomain.com;
    return 301 https://$server_name$request_uri;
}

server {
    listen 443 ssl http2;
    server_name yourdomain.com www.yourdomain.com;
    
    root /var/www/sentry/public;
    index index.php;
    
    ssl_certificate /path/to/certificate.crt;
    ssl_certificate_key /path/to/private.key;
    ssl_protocols TLSv1.2 TLSv1.3;
    ssl_ciphers HIGH:!aNULL:!MD5;
    
    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }
    
    location ~ \.php$ {
        fastcgi_pass unix:/var/run/php/php-fpm.sock;
        fastcgi_index index.php;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        include fastcgi_params;
    }
    
    # Security headers
    add_header X-Frame-Options "SAMEORIGIN" always;
    add_header X-Content-Type-Options "nosniff" always;
    add_header X-XSS-Protection "1; mode=block" always;
}
```

### 8. Setup Cron Jobs (if needed)

```bash
# Edit crontab
crontab -e

# Add Laravel scheduler
* * * * * cd /var/www/sentry && php artisan schedule:run >> /dev/null 2>&1
```

### 9. Setup Monitoring & Logging

#### 9.1 Log Rotation
```bash
# Configure logrotate for Laravel logs
sudo tee /etc/logrotate.d/sentry > /dev/null <<EOF
/var/www/sentry/storage/logs/*.log {
    daily
    rotate 14
    compress
    delaycompress
    notifempty
    create 0640 www-data www-data
    sharedscripts
    postrotate
        /usr/lib/php/sessionclean >/dev/null 2>&1 || true
    endscript
}
EOF
```

#### 9.2 Application Monitoring
- Monitor error logs: `tail -f storage/logs/laravel.log`
- Monitor database logs
- Setup application monitoring (New Relic, Datadog, etc.)

### 10. Post-Deployment Verification

```bash
# Clear and cache configuration
php artisan config:cache
php artisan route:cache
php artisan view:cache

# Verify application is running
curl https://yourdomain.com/login

# Check health endpoint (if configured)
curl https://yourdomain.com/health

# Test database connectivity
php artisan tinker
> DB::connection()->getPdo()

# Verify face-api library is loaded
# (Check browser console for CDN loads)

# Test Google Sheets configuration
php artisan tinker
> (new \App\Services\GoogleSheets\GoogleSheetsClient())->appendRow(...)
```

### 11. Backup Strategy

#### 11.1 Database Backups
```bash
# Daily automated backup
0 2 * * * mysqldump -u user -p'password' database_name > /backups/sentry-$(date +\%Y\%m\%d).sql
```

#### 11.2 Application Code Backup
```bash
# Weekly backup of application directory
0 3 * * 0 tar -czf /backups/sentry-app-$(date +\%Y\%m\%d).tar.gz /var/www/sentry
```

#### 11.3 Storage Backup
```bash
# Weekly backup of uploaded files (face photos, kiosk photos, etc.)
0 4 * * 0 tar -czf /backups/sentry-storage-$(date +\%Y\%m\%d).tar.gz /var/www/sentry/storage/app/public
```

---

## Rollback Procedure

If issues occur after deployment:

### 1. Revert to Previous Version
```bash
cd /var/www/sentry
git log --oneline | head -5  # Find previous stable commit
git reset --hard <commit-hash>
```

### 2. Restore Database from Backup
```bash
mysql -u user -p'password' database_name < /backups/sentry-YYYYMMDD.sql
```

### 3. Clear Application Caches
```bash
php artisan cache:clear
php artisan config:clear
php artisan view:clear
```

### 4. Verify Application
```bash
php artisan tinker
> DB::connection()->getPdo()  # Verify DB connection
```

---

## Security Hardening Checklist

### Application Level
- [ ] APP_DEBUG set to `false` in production
- [ ] APP_KEY generated and unique
- [ ] CSRF protection enabled (default in Laravel)
- [ ] SQL injection prevention (using query builder/Eloquent)
- [ ] XSS prevention (Blade templates escape by default)
- [ ] Rate limiting configured on API endpoints
- [ ] Input validation enforced on all routes
- [ ] Permission middleware enforced on protected routes

### Server Level
- [ ] SSL/TLS certificate installed and valid
- [ ] Firewall configured (block unnecessary ports)
- [ ] Only SSH key authentication (no password SSH)
- [ ] Fail2ban installed and configured
- [ ] Regular security updates applied
- [ ] Web server running as non-root user
- [ ] File permissions locked down (755 for dirs, 644 for files)
- [ ] No world-writable directories

### Secrets Management
- [ ] `.env` file never committed to git
- [ ] Service account JSON never committed to git
- [ ] API keys rotated regularly
- [ ] Database password is strong (20+ characters)
- [ ] All secrets stored in environment variables

### Monitoring
- [ ] Error logging configured
- [ ] Failed login attempts logged
- [ ] API call logging enabled (api_logs table)
- [ ] Database queries monitored (slow query log)
- [ ] Regular security scans configured

---

## Troubleshooting

### Issue: "Migrations not found"
```bash
# Ensure migrations directory exists
ls database/migrations/

# Run migrations with verbose output
php artisan migrate --verbose
```

### Issue: "Storage link already exists"
```bash
# Remove existing link
rm public/storage

# Recreate link
php artisan storage:link
```

### Issue: "Face-api.js not loading"
```bash
# Check browser console for CDN errors
# Ensure HTTPS is properly configured
# Test CDN URL directly: https://cdn.jsdelivr.net/npm/face-api.js@0.22.2/dist/face-api.min.js
```

### Issue: "Google Sheets sync failing"
```bash
# Verify service account JSON path
php artisan tinker
> file_exists(config('sentry.google.credentials_path'))

# Test Google API credentials
> (new \Google_Client())->setAuthConfig(config('sentry.google.credentials_path'))->authorize()
```

### Issue: "Database connection refused"
```bash
# Check credentials in .env
cat .env | grep DB_

# Verify database is running
mysql -h DB_HOST -u DB_USERNAME -p'DB_PASSWORD' -e "SELECT 1;"

# Test Laravel connection
php artisan tinker
> DB::connection()->getPdo()
```

---

## Performance Optimization

### Database Optimization
```bash
# Analyze all tables
php artisan optimize

# Rebuild indexes (after large data load)
php artisan tinker
> DB::statement('ANALYZE TABLE user_directory;')
> DB::statement('OPTIMIZE TABLE visitor_session;')
```

### Application Caching
```bash
# Enable configuration caching
php artisan config:cache

# Cache routes
php artisan route:cache

# Cache views (Blade compilation)
php artisan view:cache
```

### Server Optimization
- Enable gzip compression
- Enable browser caching (Cache-Control headers)
- Use CDN for static assets (face-api.js already on CDN)
- Monitor database slow queries
- Configure PHP opcache

---

## Monitoring Checklist (After Deployment)

- [ ] Application loads without errors (check error logs)
- [ ] Login page accessible and functional
- [ ] Admin dashboard accessible to authorized users
- [ ] Database accessible and responding
- [ ] Google Sheets credentials loaded successfully
- [ ] API endpoint `/api/v1/visitor/sync` returns 401 without API key
- [ ] Kiosk page loads and initializes
- [ ] Face-api.js CDN loads (check browser console)
- [ ] Public storage symlink working (check `public/storage` exists)
- [ ] Visitor registration flow accessible at `/register/visitor`
- [ ] SSL/TLS certificate valid (check in browser)
- [ ] Security headers present (check browser dev tools)
- [ ] No sensitive data in git logs (verify with `git log -p | grep -i password`)

---

## Support & Maintenance

### Regular Maintenance Tasks
- [ ] Monitor error logs daily
- [ ] Review API logs for anomalies
- [ ] Check Google Sheets sync success rate
- [ ] Verify kiosk photo storage usage
- [ ] Update dependencies monthly (`composer update`, `npm update`)
- [ ] Review audit logs for unauthorized access attempts
- [ ] Test backup/restore procedures monthly

### Security Updates
- [ ] Subscribe to Laravel security announcements
- [ ] Apply security patches immediately
- [ ] Review and rotate API keys quarterly
- [ ] Audit file permissions monthly

---

## Deployment Completion Checklist

After completing all steps above:

- [ ] Environment configured (.env set correctly)
- [ ] Database migrations run
- [ ] Database seeders executed
- [ ] Storage link created
- [ ] Google credentials placed in `/credentials/`
- [ ] Web server configured (Apache/Nginx)
- [ ] SSL/TLS certificate installed
- [ ] Application caches cleared
- [ ] Permissions verified
- [ ] Post-deployment verification passed
- [ ] Backups configured
- [ ] Monitoring active
- [ ] Team trained on deployment process

---

## Git Repository Status

**Current Commits:**
```
0f60416 - Add comprehensive logical testing report
55a0286 - Fix: Add GET route for visitor capture page
15fa449 - Security: Update .gitignore and remove credentials
c766aa7 - Phase 1 foundation complete
342d81d - Initial commit
```

**Last Deployment-Ready Commit:** `0f60416` (after logical testing complete)

**Files Excluded from Repository:**
- `.env` (all variants)
- `/credentials/**`
- `/documents/**`
- All IDE/OS configs
- `node_modules/`, `vendor/`
- Cache and log files

---

**Status: ✅ READY FOR PRODUCTION DEPLOYMENT**

For questions or issues during deployment, refer to the Laravel documentation at https://laravel.com/docs or check the application-specific logs at `storage/logs/laravel.log`.
