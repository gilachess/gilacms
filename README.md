# WordPress to Custom PHP SQLite CMS Framework

This is a lightweight, responsive, and performance-optimized custom CMS backed by **SQLite**. It includes a dynamic theme toggle (Dark/Light mode), SEO best practices (Sitemap, RSS, metadata), clean front-controller URL routing, and a modular editor workspace.

It features a WordPress REST API migration scraper to import categories, tags, post content, and download media assets directly into the SQLite structure.

## 🚀 Key Features
- **SQLite Database:** Zero configuration, high performance, self-contained single file database.
- **Dynamic Routing:** Clean SEO-friendly URLs (`/category/name/`, `/post-slug/`) with Nginx and Apache (`.htaccess`) support.
- **Dynamic Dark/Light Mode:** Instant, persistent theme switching using CSS Variables and JS localStorage.
- **Modular Admin Editor:** Support for three pluggable editors:
  - **TinyMCE:** Full-featured rich text WYSIWYG editor.
  - **EasyMDE (Markdown):** Markdown editor with live preview side-by-side.
  - **Plain Text:** Lightweight HTML/Plain Text raw textarea editor.
- **WP Importer Script:** Imports posts, categories, tags, and downloads attachments into local directories.

---

## 🛠️ Installation & Setup

### 1. Clone/Download the Codebase
Extract this codebase into your web server directory (e.g. `/var/www/yoursite.com`).

### 2. Configure Settings
Open `config.php` and configure your site settings:
```php
define('SITE_NAME', 'My Custom CMS');
define('SITE_DESCRIPTION', 'A lightweight SQLite blog CMS.');
define('SITE_DOMAIN', 'yoursite.com');

define('ADMIN_PASSWORD', 'your-secure-password'); // Admin dashboard password

// Define modular editor type: 'tinymce', 'markdown', or 'textarea'
define('CMS_EDITOR', 'tinymce'); 
```

### 3. Migrate from WordPress
Edit `import_wp.php` configuration block to target your source WordPress API details:
```php
define('API_BASE_URL', 'https://your-wordpress-site.com/wp-json/wp/v2');
define('OLD_ASSET_URL_PATTERN', '/https?:\/\/your-wordpress-site\.com\/wp-content\/uploads\//i');
```

Then run the migration script:
```bash
# 1. Run a Dry Run to test connectivity and parse posts (doesn't modify DB or download files)
php import_wp.php

# 2. Execute the import (downloads attachments and creates database cms.db)
php import_wp.php --execute
```

---

## 🖥️ Local Development

### 1. Web Server Setup

#### Nginx Configuration
Add a local location block to route pretty URLs to `index.php`:
```nginx
location /wp-php-sqlite-cms/ {
    index  index.php index.html index.htm;
    try_files $uri $uri/ /wp-php-sqlite-cms/index.php?$args;
}
```

#### Apache Setup
Apache reads `.htaccess` automatically. Ensure `AllowOverride All` is set for your directory.

#### PHP Built-in Server
You can also run locally using PHP's built-in router:
```bash
php -S localhost:8000 index.php
```

---

## 🔒 Admin Dashboard
Access the dashboard at `/admin/index.php` using the password defined in your `config.php`.

---

## 🚀 Production Deployment
Configure `sync.sh` with your production server details:
```bash
REMOTE_USER="root"
REMOTE_HOST="your-production-server"
REMOTE_DIR="/var/www/yoursite.com/"
```
Deploy code changes dynamically by running:
```bash
./sync.sh
```
*Note: SQLite databases and image uploads are excluded during file synchronizations to prevent overwriting production content.*

---

## 📄 License
This codebase is open-source and free for customization.
