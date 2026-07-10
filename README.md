# GilaCMS

**GilaCMS** is a lightweight, responsive, and performance-optimized custom blogging CMS backed by **SQLite**. It includes a dynamic theme toggle (Dark/Light mode), SEO best practices (dynamic XML Sitemap, RSS feeds, schema.org tags), clean URL routing, and a pluggable modular admin editor.

It features an automated WordPress REST API migration scraper to pull posts, categories, tags, and download media assets directly into the SQLite database.

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

### 1. Configure Settings
Open `config.php` and configure your site settings:
```php
define('SITE_NAME', 'GilaCMS Portal');
define('SITE_DESCRIPTION', 'A lightweight SQLite blog CMS.');
define('SITE_DOMAIN', 'yoursite.com');

define('ADMIN_PASSWORD', 'your-secure-password'); // Admin dashboard password

// Define modular editor type: 'tinymce', 'markdown', or 'textarea'
define('CMS_EDITOR', 'tinymce'); 
```

### 2. Migrate from WordPress
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
location /gilacms/ {
    index  index.php index.html index.htm;
    try_files $uri $uri/ /gilacms/index.php?$args;
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
