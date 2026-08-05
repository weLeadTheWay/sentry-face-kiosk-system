# Database Cleanup Guide

Choose the option that best fits your needs.

---

## Option 1: Quick Clean (Recommended for Testing) ⭐

**Clears only visitor data, keeps admin/reference data**

### SSH Into Server
```bash
ssh user@your-server.com
cd /var/www/sentry
```

### Use Laravel Tinker
```bash
php artisan tinker

# Clear all visitor-related data
> \App\Models\VisitorRequest::truncate();
> \App\Models\VisitorSession::truncate();
> \App\Models\VisitorEntryLog::truncate();
> \App\Models\FaceProfile::truncate();
> \App\Models\UserDirectory::whereHas('visitorType')->delete();

# Clear API logs
> \App\Models\ApiLog::truncate();

# Verify
> \App\Models\UserDirectory::count()
> \App\Models\VisitorRequest::count()
> \App\Models\FaceProfile::count()

> exit
```

**Result:** Clean slate for testing, all admin data preserved ✅

---

## Option 2: Full Database Reset (Nuclear Option) 💣

**Drops entire database and recreates from scratch**

### Method A: Via MySQL CLI

```bash
ssh user@your-server.com

# Login to MySQL
mysql -u root -p

# Drop the entire database
DROP DATABASE sentry_prod;

# Recreate it
CREATE DATABASE sentry_prod CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

# Grant permissions
GRANT ALL PRIVILEGES ON sentry_prod.* TO 'sentry_user'@'localhost';
FLUSH PRIVILEGES;

EXIT;
```

### Method B: Via Laravel Artisan

```bash
cd /var/www/sentry

# Run migrations fresh (drops all tables and recreates)
php artisan migrate:fresh --force

# Seed reference data
php artisan db:seed --force
```

**Result:** Complete database reset with all fresh tables ✅

---

## Option 3: Selective Table Cleanup

**Clear specific tables only**

```bash
php artisan tinker

# Option A: Clear visitor requests
> \App\Models\VisitorRequest::truncate();

# Option B: Clear face profiles
> \App\Models\FaceProfile::truncate();

# Option C: Clear kiosk sessions
> \App\Models\VisitorSession::truncate();

# Option D: Clear entry logs
> \App\Models\VisitorEntryLog::truncate();

# Option E: Clear API logs
> \App\Models\ApiLog::truncate();

# Option F: Clear ALL user directories
> \App\Models\UserDirectory::truncate();

> exit
```

---

## Option 4: Reset with Backup (Safest)

**Backup first, then reset**

### Step 1: Backup Database
```bash
ssh user@your-server.com

# Create backup
mysqldump -u sentry_user -p sentry_prod > /home/user/sentry-backup-$(date +%Y%m%d-%H%M%S).sql

# Or gzip it
mysqldump -u sentry_user -p sentry_prod | gzip > /home/user/sentry-backup-$(date +%Y%m%d-%H%M%S).sql.gz
```

### Step 2: Reset Database
```bash
cd /var/www/sentry

# Fresh migrations
php artisan migrate:fresh --force

# Reseed
php artisan db:seed --force
```

### Step 3: Verify
```bash
php artisan tinker
> \App\Models\Role::count()
> \App\Models\User::count()
> exit
```

---

## Option 5: Delete Old Uploaded Files

**Clear storage (photos uploaded during registration/kiosk)**

```bash
ssh user@your-server.com
cd /var/www/sentry

# Clear face photos
rm -rf storage/app/public/face-photos/*

# Clear kiosk photos
rm -rf storage/app/public/kiosk-photos/*

# Verify symlink still works
ls -l public/storage
```

---

## Complete Fresh Start (All-In-One)

**Database + files + cache**

```bash
ssh user@your-server.com
cd /var/www/sentry

# 1. Backup database
mysqldump -u sentry_user -p sentry_prod | gzip > sentry-backup-$(date +%Y%m%d).sql.gz

# 2. Reset database
php artisan migrate:fresh --force
php artisan db:seed --force

# 3. Clear storage files
rm -rf storage/app/public/face-photos/*
rm -rf storage/app/public/kiosk-photos/*

# 4. Clear Laravel caches
php artisan cache:clear
php artisan config:clear
php artisan view:clear

# 5. Re-cache configuration (optional, for production)
php artisan config:cache
php artisan route:cache

# 6. Verify
php artisan tinker
> \App\Models\User::count()
> \App\Models\UserDirectory::count()
> \App\Models\VisitorRequest::count()
> exit
```

---

## What Gets Cleared & What Stays

### Cleared (Testing Data)
- ❌ Visitor requests (all)
- ❌ Face profiles (all)
- ❌ Visitor sessions (all)
- ❌ Entry logs (all)
- ❌ User directories (if not admin)
- ❌ API logs
- ❌ Uploaded photos

### Preserved (Reference Data)
- ✅ Roles (Admin, Manager, etc.)
- ✅ Permissions (all)
- ✅ Admin users
- ✅ Farms
- ✅ Kiosk devices
- ✅ Identity types
- ✅ Employee types
- ✅ Biosecurity rules
- ✅ Farm aliases

---

## Recommended Workflow for Testing

```bash
# 1. Start fresh
php artisan migrate:fresh --force
php artisan db:seed --force

# 2. Create test visitor via tinker
php artisan tinker
> $service = app('App\Services\VisitorSyncService');
> $result = $service->syncApprovedRequest([
    'first_name' => 'John',
    'last_name' => 'Doe',
    'email' => 'john@example.com',
    'farm' => 'MADERA',
    'host_name' => 'Manager',
    'purpose' => 'Test',
    'visit_datetime' => now(),
    'departure_datetime' => now()->addHours(8),
    'visitor_id' => 'TEST_001',
    'qr_url' => 'https://example.com/qr.png'
  ]);
> $result['registration_token']
> exit

# 3. Test registration with that token
# https://yourdomain.com/register/visitor?token=REG_XXXXX

# 4. Test kiosk
# https://yourdomain.com/kiosk/your-kiosk-id

# 5. Clean and repeat if needed
php artisan migrate:fresh --force
php artisan db:seed --force
```

---

## Command Reference

| Task | Command |
|------|---------|
| **Reset Everything** | `php artisan migrate:fresh --force` |
| **Reseed Reference Data** | `php artisan db:seed --force` |
| **Clear Specific Table** | `php artisan tinker` then `\App\Models\Table::truncate();` |
| **Backup Database** | `mysqldump -u user -p dbname > backup.sql` |
| **Restore Database** | `mysql -u user -p dbname < backup.sql` |
| **Clear All Caches** | `php artisan cache:clear && php artisan config:clear` |
| **Clear Uploaded Files** | `rm -rf storage/app/public/*` |

---

## Safety Checklist

Before resetting:
- [ ] Do you have a backup? (if important data)
- [ ] Are you on the right server? (not production!)
- [ ] Is this a test/staging environment?
- [ ] Do you have admin credentials saved?

---

## Recommended for Your Situation

**Since you're testing:**

```bash
# Go with Option 1 + Option 5
php artisan tinker
> \App\Models\VisitorRequest::truncate();
> \App\Models\VisitorSession::truncate();
> \App\Models\VisitorEntryLog::truncate();
> \App\Models\FaceProfile::truncate();
> \App\Models\UserDirectory::whereHas('visitorType')->delete();
> \App\Models\ApiLog::truncate();
> exit

# Clear photos
rm -rf storage/app/public/face-photos/*
rm -rf storage/app/public/kiosk-photos/*
```

**Then create a fresh test user and try again!**

---

**Choose an option above and let me know if you need help executing it!**
