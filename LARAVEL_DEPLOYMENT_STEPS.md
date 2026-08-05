# Complete Laravel Deployment Guide (Local to Live Server)

**For:** Sentry System (Laravel 12)  
**Tested Method:** Git clone → Composer install → Configuration → Database → Web server

---

## Pre-Deployment Checklist

- [ ] Domain registered and DNS pointing to server
- [ ] Live server SSH access confirmed
- [ ] Database server ready (MySQL 8.0+)
- [ ] PHP 8.3+ installed on server
- [ ] Composer installed on server
- [ ] Git access configured (if private repo)
- [ ] SSL certificate obtained (for HTTPS)
- [ ] Backups of any existing data created

---

## Step-by-Step Deployment Process

### Phase 1: Server Preparation

#### Step 1.1: Connect to Live Server
```bash
ssh user@your-live-server.com
# Or: ssh -i /path/to/key.pem user@your-live-server.com
```

#### Step 1.2: Create Application Directory
```bash
# Create directory for Laravel app
sudo mkdir -p /var/www/sentry

# Change ownership to your user
sudo chown -R $USER:$USER /var/www/sentry

# Navigate to directory
cd /var/www/sentry
```

#### Step 1.3: Verify Prerequisites
```bash
# Check PHP version (should be 8.3+)
php --version

# Check MySQL/MariaDB
mysql --version

# Check Composer
composer --version

# Check Git
git --version
```

---

### Phase 2: Deploy Application Code

#### Step 2.1: Clone Repository
```bash
# If public repository
git clone https://github.com/yourorg/sentry.git .

# If private repository (requires SSH key setup)
git clone git@github.com:yourorg/sentry.git .

# Verify clone succeeded
ls -la
# Should see: app/, config/, database/, routes/, .env.example, etc.
```

#### Step 2.2: Install Dependencies
```bash
# Install PHP dependencies
composer install --no-dev --optimize-autoloader

# This will:
# - Install all packages from composer.lock
# - Optimize autoloader for production
# - Download ~80-100 packages

# Wait for completion (may take 2-5 minutes)
```

#### Step 2.3: Verify Installation
```bash
# Check that vendor directory exists
ls -la vendor/ | head -5

# Check Laravel installation
php artisan --version
```

---

### Phase 3: Environment Configuration

#### Step 3.1: Create .env File
```bash
# Copy example to .env
cp .env.example .env

# Edit configuration
nano .env
# Or use: vi .env
```

#### Step 3.2: Configure .env Settings
Edit these critical settings:

```env
# Application
APP_NAME="Sentry System"
APP_ENV=production          # MUST BE 'production'
APP_DEBUG=false             # MUST BE 'false'
APP_URL=https://yourdomain.com/sentry
APP_KEY=                    # Leave empty for now (generated next step)

# Database
DB_CONNECTION=mysql
DB_HOST=localhost           # Or your DB server IP
DB_PORT=3306
DB_DATABASE=sentry_prod     # Your production database name
DB_USERNAME=sentry_user     # Your DB user
DB_PASSWORD=STRONG_PASSWORD_HERE  # 20+ characters!

# Cache & Session (Optional - adjust based on your setup)
CACHE_DRIVER=file           # Or: redis, memcached
SESSION_DRIVER=file         # Or: redis, database
QUEUE_CONNECTION=sync       # Keep as 'sync' unless using queues

# Mail (if needed)
MAIL_MAILER=smtp
MAIL_HOST=smtp.mailtrap.io
MAIL_PORT=465
MAIL_USERNAME=your_email
MAIL_PASSWORD=your_password
MAIL_FROM_ADDRESS=noreply@yourdomain.com
MAIL_FROM_NAME="Sentry System"

# Google Sheets Integration
SYNC_API_KEY=generate_strong_random_string_here_32_chars
SENTRY_VISITORS_ID=your_google_spreadsheet_id_here
```

#### Step 3.3: Generate Application Key
```bash
# This generates a unique encryption key for your app
php artisan key:generate

# Verify it was added to .env
grep APP_KEY .env
# Should show: APP_KEY=base64:xxx...
```

#### Step 3.4: Secure .env File
```bash
# Restrict .env to owner only (no one else can read secrets)
chmod 600 .env

# Verify permissions
ls -l .env
# Should show: -rw------- (600)
```

---

### Phase 4: Database Setup

#### Step 4.1: Create Database
```bash
# Login to MySQL
mysql -u root -p

# Create database
CREATE DATABASE sentry_prod CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

# Create database user
CREATE USER 'sentry_user'@'localhost' IDENTIFIED BY 'STRONG_PASSWORD_HERE';

# Grant permissions
GRANT ALL PRIVILEGES ON sentry_prod.* TO 'sentry_user'@'localhost';

# Apply changes
FLUSH PRIVILEGES;

# Exit MySQL
EXIT;
```

#### Step 4.2: Run Migrations
```bash
# This creates all database tables
php artisan migrate --force

# Output should show:
# Migration table created successfully
# Migrating: ...
# Migrated: ... (should be 8+ migrations)
```

#### Step 4.3: Run Seeders (Populate Reference Data)
```bash
# Seed all reference data (roles, permissions, identity types, etc.)
php artisan db:seed --force

# Or seed specific seeders
php artisan db:seed --class=PermissionSeeder --force
php artisan db:seed --class=IdentityTypeSeeder --force
php artisan db:seed --class=VisitorTypeSeeder --force
```

#### Step 4.4: Verify Database Setup
```bash
# Login to check tables
mysql -u sentry_user -p sentry_prod

# Show tables
SHOW TABLES;
# Should show: users, roles, permissions, farms, kiosks, identity_types, etc.

# Count records
SELECT COUNT(*) FROM roles;
SELECT COUNT(*) FROM permissions;

EXIT;
```

---

### Phase 5: File & Directory Permissions

#### Step 5.1: Set Directory Permissions
```bash
# Navigate to app directory
cd /var/www/sentry

# Set ownership to web server user (www-data for Apache/Nginx)
sudo chown -R www-data:www-data /var/www/sentry

# Set directory permissions (755 = rwxr-xr-x)
sudo chmod -R 755 /var/www/sentry

# Set storage directory permissions (775 = rwxrwxr-x)
sudo chmod -R 775 /var/www/sentry/storage

# Set bootstrap cache permissions
sudo chmod -R 775 /var/www/sentry/bootstrap/cache

# Verify permissions
ls -l /var/www/sentry/ | head -10
```

#### Step 5.2: Create Storage Link
```bash
# This creates symlink: public/storage → storage/app/public
php artisan storage:link

# Verify link was created
ls -l public/storage
# Should show: storage -> ../storage/app/public

# Test that files are accessible
touch storage/app/public/test.txt
curl http://localhost/storage/test.txt
rm storage/app/public/test.txt
```

---

### Phase 6: Google Credentials Setup

#### Step 6.1: Upload Service Account JSON
```bash
# On your LOCAL machine, transfer the file:
scp credentials/service-account.json user@your-live-server.com:/var/www/sentry/credentials/

# Or manually:
# 1. Copy credentials/service-account.json locally
# 2. SSH into server
# 3. mkdir -p /var/www/sentry/credentials
# 4. nano /var/www/sentry/credentials/service-account.json
# 5. Paste entire JSON content
# 6. Save (Ctrl+O, Enter, Ctrl+X)
```

#### Step 6.2: Secure the Credentials File
```bash
# Restrict permissions to owner only
chmod 600 /var/www/sentry/credentials/service-account.json

# Verify
ls -l /var/www/sentry/credentials/
# Should show: -rw------- (600)
```

#### Step 6.3: Verify Credentials
```bash
# Test that Laravel can find the file
php artisan tinker

# Inside tinker:
> config('sentry.google.credentials_path')
# Should output: "credentials/service-account.json"

> file_exists(config('sentry.google.credentials_path'))
# Should output: true

> exit
```

---

### Phase 7: Cache Configuration (Performance)

#### Step 7.1: Cache Configuration Files
```bash
# This pre-compiles config for faster loading
php artisan config:cache

# Output: Configuration cache cleared!
#         Configuration cached successfully!
```

#### Step 7.2: Cache Routes
```bash
# This pre-compiles routes
php artisan route:cache

# Output: Route cache cleared!
#         Routes cached successfully!
```

#### Step 7.3: Cache Views (Blade Templates)
```bash
# Pre-compile Blade templates
php artisan view:cache

# Output: View cache cleared!
#         Blade templates cached successfully!
```

**⚠️ Important:** If you make changes to code, run these commands again:
```bash
php artisan config:clear
php artisan route:clear
php artisan view:clear
```

---

### Phase 8: Web Server Configuration

#### Phase 8A: Apache Configuration

##### Step 8A.1: Create Virtual Host File
```bash
sudo nano /etc/apache2/sites-available/sentry.conf
```

##### Step 8A.2: Paste Configuration
```apache
<VirtualHost *:80>
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
    
    # Redirect HTTP to HTTPS
    Redirect permanent / https://yourdomain.com/
    
    ErrorLog ${APACHE_LOG_DIR}/sentry-error.log
    CustomLog ${APACHE_LOG_DIR}/sentry-access.log combined
</VirtualHost>

# HTTPS Configuration
<VirtualHost *:443>
    ServerName yourdomain.com
    ServerAlias www.yourdomain.com
    DocumentRoot /var/www/sentry/public
    
    <Directory /var/www/sentry/public>
        AllowOverride All
        Require all granted
        
        <IfModule mod_rewrite.c>
            RewriteEngine On
            RewriteCond %{REQUEST_FILENAME} !-f
            RewriteCond %{REQUEST_FILENAME} !-d
            RewriteRule ^ index.php [L]
        </IfModule>
    </Directory>
    
    # Security headers
    Header always set X-Frame-Options "SAMEORIGIN"
    Header always set X-Content-Type-Options "nosniff"
    Header always set X-XSS-Protection "1; mode=block"
    
    # SSL Certificate
    SSLEngine on
    SSLCertificateFile /path/to/your/certificate.crt
    SSLCertificateKeyFile /path/to/your/private.key
    SSLCertificateChainFile /path/to/your/chain.crt
    
    ErrorLog ${APACHE_LOG_DIR}/sentry-error.log
    CustomLog ${APACHE_LOG_DIR}/sentry-access.log combined
</VirtualHost>
```

##### Step 8A.3: Enable Site
```bash
# Enable the virtual host
sudo a2ensite sentry.conf

# Enable required modules
sudo a2enmod rewrite
sudo a2enmod headers
sudo a2enmod ssl

# Test configuration
sudo apache2ctl configtest
# Should output: Syntax OK

# Restart Apache
sudo systemctl restart apache2
```

#### Phase 8B: Nginx Configuration

##### Step 8B.1: Create Nginx Config
```bash
sudo nano /etc/nginx/sites-available/sentry
```

##### Step 8B.2: Paste Configuration
```nginx
# Redirect HTTP to HTTPS
server {
    listen 80;
    server_name yourdomain.com www.yourdomain.com;
    return 301 https://$server_name$request_uri;
}

# HTTPS Server
server {
    listen 443 ssl http2;
    server_name yourdomain.com www.yourdomain.com;
    
    root /var/www/sentry/public;
    index index.php index.html;
    
    # SSL Certificate
    ssl_certificate /path/to/your/certificate.crt;
    ssl_certificate_key /path/to/your/private.key;
    ssl_protocols TLSv1.2 TLSv1.3;
    ssl_ciphers HIGH:!aNULL:!MD5;
    
    # Logging
    access_log /var/log/nginx/sentry-access.log;
    error_log /var/log/nginx/sentry-error.log;
    
    # Laravel routing
    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }
    
    # PHP-FPM
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
    
    # Deny access to hidden files
    location ~ /\. {
        deny all;
    }
}
```

##### Step 8B.3: Enable Site
```bash
# Create symlink to enable site
sudo ln -s /etc/nginx/sites-available/sentry /etc/nginx/sites-enabled/

# Test configuration
sudo nginx -t
# Should output: configuration file ... syntax is ok

# Restart Nginx
sudo systemctl restart nginx
```

---

### Phase 9: SSL/HTTPS Setup (if not already done)

#### Step 9.1: Install SSL Certificate
```bash
# Option A: Using Let's Encrypt (free)
sudo apt-get install certbot python3-certbot-apache
# Or for Nginx:
sudo apt-get install certbot python3-certbot-nginx

# Generate certificate
sudo certbot certonly --standalone -d yourdomain.com -d www.yourdomain.com

# Certificate path: /etc/letsencrypt/live/yourdomain.com/
```

#### Step 9.2: Setup Auto-Renewal
```bash
# Test renewal
sudo certbot renew --dry-run

# Add to crontab for auto-renewal
sudo crontab -e
# Add line:
0 12 * * * /usr/bin/certbot renew --quiet
```

---

### Phase 10: Post-Deployment Verification

#### Step 10.1: Test Web Server
```bash
# Test login page
curl https://yourdomain.com/login

# Should return HTML of login page (not error)
```

#### Step 10.2: Test Database Connection
```bash
php artisan tinker

# Inside tinker:
> DB::connection()->getPdo()
# Should return a PDO connection object (not error)

> DB::table('users')->count()
# Should return number of users

> exit
```

#### Step 10.3: Test API Endpoint
```bash
# Test without API key (should return 401)
curl -X POST https://yourdomain.com/api/v1/visitor/sync

# Should return: {"message":"Unauthenticated"} or 401 error

# This proves:
# - Web server is working
# - Route is accessible
# - Middleware is running
```

#### Step 10.4: Test Storage
```bash
# Verify storage link works
curl https://yourdomain.com/storage/

# Should return directory listing (or 404 if no files)
```

#### Step 10.5: Check Logs
```bash
# Watch error logs in real-time
tail -f storage/logs/laravel.log

# Visit your site in browser to generate logs
# You should see entries in the log

# Exit with: Ctrl+C
```

#### Step 10.6: Verify Security
```bash
# Check that .env is not accessible
curl https://yourdomain.com/.env
# Should return: 404 Not Found (good!)

# Check credentials are protected
curl https://yourdomain.com/credentials/service-account.json
# Should return: 404 Not Found (good!)
```

---

### Phase 11: Monitoring & Maintenance

#### Step 11.1: Setup Log Rotation
```bash
# Edit logrotate config
sudo nano /etc/logrotate.d/sentry

# Add:
/var/www/sentry/storage/logs/*.log {
    daily
    rotate 14
    compress
    delaycompress
    notifempty
    create 0640 www-data www-data
}
```

#### Step 11.2: Monitor Application
```bash
# Watch logs in real-time
tail -f /var/www/sentry/storage/logs/laravel.log

# Check disk usage
du -sh /var/www/sentry/

# Check database size
mysql -u sentry_user -p -e "SELECT table_name, ROUND(((data_length + index_length) / 1024 / 1024), 2) AS size_mb FROM information_schema.tables WHERE table_schema = 'sentry_prod' ORDER BY size_mb DESC;"

# Check Apache/Nginx error logs
tail -f /var/log/apache2/sentry-error.log
# Or for Nginx:
tail -f /var/log/nginx/sentry-error.log
```

#### Step 11.3: Setup Automated Backups
```bash
# Edit crontab
crontab -e

# Add backup job (daily at 2 AM):
0 2 * * * mysqldump -u sentry_user -pPASSWORD sentry_prod | gzip > /backups/sentry-$(date +\%Y\%m\%d).sql.gz

# Add storage backup (daily at 3 AM):
0 3 * * * tar -czf /backups/sentry-storage-$(date +\%Y\%m\%d).tar.gz /var/www/sentry/storage/app/public
```

---

## Quick Reference: Complete Deployment Summary

```bash
# 1. SSH into server
ssh user@your-live-server.com

# 2. Create directory & clone code
sudo mkdir -p /var/www/sentry
sudo chown -R $USER:$USER /var/www/sentry
cd /var/www/sentry
git clone https://github.com/yourorg/sentry.git .

# 3. Install dependencies
composer install --no-dev --optimize-autoloader

# 4. Create and configure .env
cp .env.example .env
nano .env
# Add: DB credentials, APP_KEY, SYNC_API_KEY, SENTRY_VISITORS_ID

# 5. Generate APP_KEY
php artisan key:generate

# 6. Setup database
mysql -u root -p
CREATE DATABASE sentry_prod;
CREATE USER 'sentry_user'@'localhost' IDENTIFIED BY 'password';
GRANT ALL ON sentry_prod.* TO 'sentry_user'@'localhost';
FLUSH PRIVILEGES;
EXIT;

# 7. Run migrations & seeders
php artisan migrate --force
php artisan db:seed --force

# 8. Setup storage & permissions
php artisan storage:link
sudo chown -R www-data:www-data /var/www/sentry
sudo chmod -R 775 /var/www/sentry/storage
sudo chmod -R 775 /var/www/sentry/bootstrap/cache

# 9. Upload credentials
scp credentials/service-account.json user@server:/var/www/sentry/credentials/
chmod 600 /var/www/sentry/credentials/service-account.json

# 10. Cache configuration
php artisan config:cache
php artisan route:cache
php artisan view:cache

# 11. Configure web server (Apache/Nginx)
# See Phase 8 above

# 12. Setup HTTPS (Let's Encrypt)
sudo certbot certonly --standalone -d yourdomain.com

# 13. Verify deployment
curl https://yourdomain.com/login
php artisan tinker
tail -f storage/logs/laravel.log
```

---

## Troubleshooting Common Issues

### Issue: 500 Error on First Visit
```bash
# Check error log
tail -f storage/logs/laravel.log

# Clear caches
php artisan config:clear
php artisan cache:clear

# Re-cache config
php artisan config:cache
```

### Issue: Database Connection Error
```bash
# Verify .env settings
grep DB_ .env

# Test MySQL connection
mysql -u sentry_user -p -h DB_HOST -e "SELECT 1;"

# Verify database exists
mysql -u sentry_user -p -e "SHOW DATABASES;"
```

### Issue: Permission Denied on Storage
```bash
# Check permissions
ls -l storage/

# Fix permissions
sudo chown -R www-data:www-data storage/
sudo chmod -R 775 storage/
```

### Issue: Migrations Not Running
```bash
# Check migration status
php artisan migrate:status

# Run with verbose output
php artisan migrate --force --verbose

# Manually check database
mysql -u sentry_user -p sentry_prod -e "SHOW TABLES;"
```

### Issue: API Key Not Working
```bash
# Verify .env
grep SYNC_API_KEY .env

# Test with correct key
curl -X POST https://yourdomain.com/api/v1/visitor/sync \
  -H "X-API-KEY: your-sync-api-key"
```

### Issue: Google Sheets Sync Failing
```bash
# Verify credentials file exists
ls -l credentials/service-account.json

# Test in tinker
php artisan tinker
> file_exists(config('sentry.google.credentials_path'))
> json_decode(file_get_contents(config('sentry.google.credentials_path')), true)
> exit
```

---

## Security Checklist Before Going Live

- [ ] APP_DEBUG=false in .env
- [ ] APP_ENV=production in .env
- [ ] APP_KEY is unique and generated
- [ ] Database password is strong (20+ characters)
- [ ] SYNC_API_KEY is strong and unique
- [ ] .env file permissions set to 600
- [ ] credentials/service-account.json permissions set to 600
- [ ] HTTPS/SSL certificate installed
- [ ] Firewall configured (only allow ports 80, 443, 22)
- [ ] All caches cleared and re-built
- [ ] Database backed up before first run
- [ ] Error logs monitored
- [ ] .env not accessible via web (test: curl https://yourdomain.com/.env → should return 404)
- [ ] credentials folder not accessible via web

---

## Final Checklist

After completing all steps, verify:

- [ ] Application loads at yourdomain.com
- [ ] Login page accessible
- [ ] Database connected and seeded
- [ ] HTTPS working (no certificate warnings)
- [ ] API endpoint responds to requests
- [ ] Storage symlink working (photos accessible)
- [ ] Error logs show no critical errors
- [ ] Performance acceptable (page loads <2 seconds)
- [ ] All backups configured
- [ ] Monitoring/alerts configured

---

## Going Forward

**Weekly Tasks:**
- Review error logs
- Check database size
- Verify backups completed

**Monthly Tasks:**
- Update dependencies: `composer update`
- Review security logs
- Performance audit

**Quarterly Tasks:**
- Rotate API keys
- Security audit
- Full backup test/restore

---

**Status:** ✅ Ready for deployment  
**Total Time:** 30-60 minutes  
**Complexity:** Medium  
**Risk Level:** Low (if following all steps)
