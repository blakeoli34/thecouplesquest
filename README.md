# The Couple's Quest

A digital/hybrid card game designed for couples. Play with physical cards and use the app for scoring, or go fully digital with in-app card management.

## Features

- **Hybrid Mode**: Use physical cards with digital scoring and timers
- **Digital Mode**: Complete in-app experience with virtual cards
- **Real-time Notifications**: FCM push notifications for game events
- **Timer System**: Create and manage game timers with automatic expiration
- **Admin Panel**: Manage cards, wheel prizes, and game rules
- **Daily Wheel**: Spin for random rewards once per day
- **Score Tracking**: Animated scoring with history
- **PWA Support**: Install as a mobile app

## Requirements

### Server Requirements
- **PHP**: 8.0 or higher
- **MySQL**: 5.7+ or MariaDB 10.2+
- **Web Server**: Nginx (recommended) or Apache
- **SSL Certificate**: Required for PWA features and notifications

### PHP Extensions
- `pdo_mysql`
- `openssl`
- `curl`
- `json`

## Installation

### 1. Clone the Repository
```bash
git clone <repository-url>
cd couples-quest
```

### 2. Database Setup
Create a MySQL database and import the schema:

```sql
-- Create database
CREATE DATABASE couples_quest;

-- Create user (optional)
CREATE USER 'couples_quest'@'localhost' IDENTIFIED BY 'your_password';
GRANT ALL PRIVILEGES ON couples_quest.* TO 'couples_quest'@'localhost';
FLUSH PRIVILEGES;
```

### 3. Configure Database Connection
Edit `config.php` and update the database credentials:

```php
// Database configuration
private const DB_HOST = 'localhost';
private const DB_NAME = 'couples_quest';
private const DB_USER = 'couples_quest';
private const DB_PASS = 'your_password';
```

### 4. Set File Permissions
Ensure the web server can read/write to the application directory:

```bash
# Set ownership to web server user (adjust as needed)
sudo chown -R www-data:www-data /path/to/couples-quest

# Set appropriate permissions
sudo chmod -R 755 /path/to/couples-quest
sudo chmod -R 775 /path/to/couples-quest/tokens  # If tokens directory exists
```

### 5. Create Required Directories
```bash
mkdir -p tokens
chmod 775 tokens
```

### 6. Firebase Setup (Optional - for push notifications)

1. **Create Firebase Project**:
   - Go to [Firebase Console](https://console.firebase.google.com/)
   - Create a new project
   - Enable Cloud Messaging

2. **Get Service Account Key**:
   - Go to Project Settings → Service Accounts
   - Generate new private key
   - Save the JSON file securely on your server

3. **Update Configuration**:
   Edit `config.php` with your Firebase details:
   ```php
   const FCM_PROJECT_ID = 'your-project-id';
   const FCM_SERVICE_ACCOUNT_PATH = '/path/to/service-account.json';
   ```

4. **Update Web App Config**:
   In `game.php`, update the Firebase configuration:
   ```javascript
   const firebaseConfig = {
       apiKey: "your-api-key",
       authDomain: "your-project.firebaseapp.com",
       projectId: "your-project-id",
       storageBucket: "your-project.appspot.com",
       messagingSenderId: "your-sender-id",
       appId: "your-app-id"
   };
   ```

### 7. Web Server Configuration

#### Nginx Example
```nginx
server {
    listen 443 ssl;
    server_name yourdomain.com;
    
    ssl_certificate /path/to/certificate.crt;
    ssl_certificate_key /path/to/private.key;
    
    root /path/to/couples-quest;
    index index.php;
    
    location / {
        try_files $uri $uri/ =404;
    }
    
    location ~ \.php$ {
        fastcgi_pass unix:/var/run/php/php8.0-fpm.sock;
        fastcgi_index index.php;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        include fastcgi_params;
    }
    
    # Security headers for PWA
    add_header X-Content-Type-Options nosniff;
    add_header X-Frame-Options DENY;
    add_header X-XSS-Protection "1; mode=block";
}
```

### 8. Create Admin User
Create an admin user to access the admin panel at `/admin.php`:

```sql
INSERT INTO admin_users (username, password_hash) 
VALUES ('admin', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi');
-- Default password is 'password' - CHANGE THIS!
```

### 9. Set Up Cron Jobs (Optional)
Add cron jobs for automated tasks:

```bash
# Edit crontab
crontab -e

# Add these lines (adjust paths and intervals as needed):

# Check expired timers every minute
* * * * * /usr/bin/php /path/to/couples-quest/cron.php timers

# Check expiring cards every 5 minutes  
*/5 * * * * /usr/bin/php /path/to/couples-quest/cron.php cards

# Send daily notifications at 9 AM
0 9 * * * /usr/bin/php /path/to/couples-quest/cron.php daily

# Cleanup expired games daily at midnight
0 0 * * * /usr/bin/php /path/to/couples-quest/cron.php cleanup

# Run all tasks every minute (alternative)
* * * * * /usr/bin/php /path/to/couples-quest/cron.php all
```

## Usage

### Creating a Game
1. Access the admin panel at `/admin.php`
2. Generate an invite code
3. Share the invite code with players
4. Players register at the main URL using the invite code

### Game Modes
- **Hybrid**: Players use physical cards with the app for scoring and timers
- **Digital**: Complete digital experience with virtual card management

### Admin Features
- **Card Management**: Create and edit serve, chance, snap, dare, and spicy cards
- **Wheel Prizes**: Configure daily wheel rewards
- **Game Rules**: Update in-app rules content
- **Scheduled Themes**: Set special themes for specific dates

## File Structure

```
couples-quest/
├── index.php              # Player registration
├── game.php               # Main game interface
├── admin.php              # Admin panel
├── download.php            # PWA installation page
├── config.php             # Configuration and database
├── functions.php          # Core game functions
├── auth.php               # Authentication helpers
├── cron.php               # Background tasks
├── game.js                # Frontend JavaScript
├── game.css               # Styles
├── manifest.json          # PWA manifest
├── firebase-messaging-sw.js # Service worker for notifications
└── tokens/                # Firebase token cache (create manually)
```

## Security Considerations

1. **Change Default Admin Password**: Update the admin user password immediately
2. **Secure Firebase Keys**: Store service account JSON outside web root
3. **Use HTTPS**: Required for PWA and notification features
4. **Database Security**: Use strong passwords and limit database user permissions
5. **File Permissions**: Ensure web server can't write to PHP files

## Troubleshooting

### Common Issues

**Database Connection Errors**:
- Verify database credentials in `config.php`
- Ensure MySQL service is running
- Check user permissions

**Notification Issues**:
- Verify Firebase configuration
- Check service account file path and permissions
- Ensure HTTPS is enabled

**Timer Problems**:
- Verify cron jobs are running
- Check server timezone configuration
- Ensure PHP has permission to execute `at` command

**PWA Installation Issues**:
- Verify `manifest.json` is accessible
- Ensure HTTPS is properly configured
- Check service worker registration

### Debug Mode
Enable debug logging by adding to `config.php`:
```php
ini_set('display_errors', 1);
error_reporting(E_ALL);
```

## Development

### Local Development Setup
1. Use PHP's built-in server for testing:
   ```bash
   php -S localhost:8000
   ```
2. Set up local SSL for PWA testing (use tools like mkcert)
3. Use Firebase emulator for testing notifications

### Database Schema
The application will create required tables automatically on first run. Key tables include:
- `games` - Game instances
- `players` - Player data
- `cards` - Card definitions
- `player_cards` - Player hand management
- `timers` - Active timers
- `score_history` - Score tracking