# Community Health Worker Visit Tracking System

PHP and MySQL project for household visit tracking, patient follow-up administration, CHW assignments, and reporting. The original public-facing community health pages remain available alongside the signed-in workspace.

## Run locally

You can serve it with PHP's built-in server:

```bash
cd c:/xampp/htdocs/community-health
php -S localhost:8000
```

Then open:

```text
http://localhost:8000
```

## Create the XAMPP database

1. Start **Apache** and **MySQL** in the XAMPP Control Panel.
2. Open `http://localhost/phpmyadmin`.
3. Select **Import**, choose `database/create_database.sql`, then select **Import**.

The script creates the `community_health` database and the tables for roles and permissions, users, CHWs, administrative locations, households, patients, visits, follow-ups, SMS notifications, audit logs, settings, and the existing `contact_inquiries` form. It is additive and does not drop existing data. It also seeds the four roles and their initial permissions. It does not create a login account.

The local XAMPP defaults are `127.0.0.1:3306`, user `root`, and a blank password. To use different credentials, set `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER`, and `DB_PASSWORD` in the PHP environment.

The PDO connection is in `includes/db.php`. The site can be served with PHP's built-in server, but MySQL must be running for database-backed pages and contact submissions.

## SMS configuration

The system defaults to demonstration mode so it can be tested locally without a live SMS provider. To activate a real gateway, copy `.env.example` to a real environment file or export the variables in the shell before starting PHP.

Example for Twilio:

```bash
set SMS_PROVIDER=twilio
set TWILIO_ACCOUNT_SID=your_account_sid
set TWILIO_AUTH_TOKEN=your_auth_token
set TWILIO_FROM_NUMBER=+15551234567
```

Example for a generic HTTP SMS gateway:

```bash
set SMS_PROVIDER=generic
set SMS_PROVIDER_ENDPOINT=https://api.example.com/send-sms
set SMS_PROVIDER_API_KEY=your_api_key
set SMS_PROVIDER_FROM=+15551234567
```

The SMS sending logic is in `includes/SmsService.php`. If no real provider is configured, the app records a simulated send and keeps the notification stored for review.

See [docs/system-design.md](docs/system-design.md) for the architecture, relationship explanation, ERD, security decisions, and design assumptions. The current implementation includes session security, login/logout, role-permission checks, location management, CHW assignments, household and patient management, visit logging, follow-up scheduling, notification review, and operational reporting with CSV export.

## Create the first administrator

After importing the database, use PowerShell from the project directory. The password is entered as a secure prompt and passed to PHP through a temporary environment variable:

```powershell
$secure = Read-Host "Choose an administrator password (12+ characters)" -AsSecureString
$env:ADMIN_PASSWORD = [System.Net.NetworkCredential]::new("", $secure).Password
& 'C:\xampp\php\php.exe' bin\create-admin.php admin@example.org "System Administrator"
Remove-Item Env:ADMIN_PASSWORD
```

Then open `http://localhost:8000/login.php`. After signing in, add the district/sector/cell/village hierarchy under **Village setup**, then register workers and select their village assignments under **CHW management**. From there, the operational workspace includes households, patients, visits, follow-ups, notifications, and reporting.
