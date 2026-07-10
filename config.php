<?php
// Custom SQLite CMS Configuration File

// Site Branding
define('SITE_NAME', 'Custom SQLite CMS');
define('SITE_DESCRIPTION', 'A fast, lightweight, SQLite-backed custom blog CMS.');
define('SITE_DOMAIN', 'example.com'); // Put your production domain here

// Security
define('ADMIN_PASSWORD', 'admin123'); // Change this password!

// Paths & Files
define('DB_FILE', __DIR__ . '/cms.db');
define('UPLOAD_DIR', __DIR__ . '/images/uploads/');

// Modular Editor Config
// Supported values:
// - 'tinymce'  : Rich Text visual WYSIWYG editor (TinyMCE from CDN)
// - 'markdown' : Markdown text editor with live side-by-side preview (EasyMDE from CDN)
// - 'textarea' : Plain Text / HTML editor (Standard HTML textarea)
define('CMS_EDITOR', 'tinymce');
