<?php
/**
 * WordPress REST API to Custom PHP CMS Import Script (SQLite)
 *
 * This script pulls posts, categories, tags, and media from a live WordPress REST API,
 * downloads media to local folders, and imports posts into the custom CMS SQLite database.
 *
 * Usage: php import_wp.php [--execute]
 * Default mode is a DRY RUN. Pass --execute to save data and download assets.
 */

// --- CONFIGURATION ---
require_once __DIR__ . '/config.php';

define('TARGET_TABLE', 'posts');

// Customize these constants for your WordPress blog source:
define('API_BASE_URL', 'https://' . SITE_DOMAIN . '/wp-json/wp/v2');
define('OLD_ASSET_URL_PATTERN', '/https?:\/\/' . preg_quote(SITE_DOMAIN, '/') . '\/wp-content\/uploads\//i');
define('NEW_ASSET_PATH', '/images/');
define('LOCAL_IMAGES_DIR', __DIR__ . '/images');

// --- END CONFIGURATION ---

$execute = in_array('--execute', $argv);

if (!$execute) {
    echo "========================================================\n";
    echo "Running in DRY RUN mode. No database writes or downloads.\n";
    echo "To execute database writes & downloads, run:\n";
    echo "  php import_wp.php --execute\n";
    echo "========================================================\n\n";
}

// 1. Fetch Categories and Tags mapping
echo "Fetching categories mapping...\n";
$categories_json = @file_get_contents(API_BASE_URL . '/categories?per_page=100');
$categories_data = $categories_json ? json_decode($categories_json, true) : [];
$categories_map = [];
if (is_array($categories_data)) {
    foreach ($categories_data as $cat) {
        $categories_map[$cat['id']] = $cat['name'];
    }
}
echo "Mapped " . count($categories_map) . " categories.\n";

echo "Fetching tags mapping...\n";
$tags_json = @file_get_contents(API_BASE_URL . '/tags?per_page=100');
$tags_data = $tags_json ? json_decode($tags_json, true) : [];
$tags_map = [];
if (is_array($tags_data)) {
    foreach ($tags_data as $tag) {
        $tags_map[$tag['id']] = $tag['name'];
    }
}
echo "Mapped " . count($tags_map) . " tags.\n\n";

// 2. Database Connection (if executing)
$pdo = null;
if ($execute) {
    try {
        $dsn = "sqlite:" . DB_FILE;
        $pdo = new PDO($dsn);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        
        // Create posts table if it does not exist
        $create_table_sql = sprintf(
            "CREATE TABLE IF NOT EXISTS %s (
                id INTEGER PRIMARY KEY,
                title TEXT NOT NULL,
                slug TEXT UNIQUE NOT NULL,
                content TEXT,
                created_at TEXT,
                status TEXT,
                publish_at TEXT,
                categories TEXT,
                tags TEXT,
                meta_description TEXT,
                meta_image TEXT
            )",
            TARGET_TABLE
        );
        $pdo->exec($create_table_sql);
        echo "Connected to SQLite (" . basename(DB_FILE) . ") and verified schema.\n\n";
    } catch (PDOException $e) {
        die("Database Connection Error: " . $e->getMessage() . "\n");
    }
}

// Helper to slugify if needed
function slugify($text) {
    $text = preg_replace('~[^\pL\d]+~u', '-', $text);
    $text = iconv('utf-8', 'us-ascii//TRANSLIT', $text);
    $text = preg_replace('~[^-\w]+~', '', $text);
    $text = trim($text, '-');
    $text = preg_replace('~-+~', '-', $text);
    $text = strtolower($text);
    return empty($text) ? 'n-a' : $text;
}

// 3. Fetch WordPress posts
echo "Fetching posts from WordPress API...\n";
$posts_json = @file_get_contents(API_BASE_URL . '/posts?per_page=100');
$posts_data = $posts_json ? json_decode($posts_json, true) : null;

if (!is_array($posts_data)) {
    die("Error: Failed to fetch posts from " . API_BASE_URL . "/posts\n");
}

$success_count = 0;
$skipped_count = 0;

foreach ($posts_data as $wp_post) {
    $wp_post_id = $wp_post['id'];
    $title = html_entity_decode($wp_post['title']['rendered'], ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $title = str_replace(['–', '—'], '-', $title);
    $slug = !empty($wp_post['slug']) ? $wp_post['slug'] : slugify($title);
    $status = ($wp_post['status'] === 'publish') ? 'published' : $wp_post['status'];
    $created_at = date('Y-m-d H:i:s', strtotime($wp_post['date']));
    $publish_at = ($status === 'future') ? date('Y-m-d H:i:s', strtotime($wp_post['date'])) : null;
    
    // Map categories
    $post_cats = [];
    if (!empty($wp_post['categories'])) {
        foreach ($wp_post['categories'] as $cat_id) {
            if (isset($categories_map[$cat_id])) {
                $post_cats[] = $categories_map[$cat_id];
            }
        }
    }
    $categories_str = implode(', ', $post_cats);

    // Map tags
    $post_tags = [];
    if (!empty($wp_post['tags'])) {
        foreach ($wp_post['tags'] as $tag_id) {
            if (isset($tags_map[$tag_id])) {
                $post_tags[] = $tags_map[$tag_id];
            }
        }
    }
    $tags_str = implode(', ', $post_tags);

    // Clean content body
    $content_raw = $wp_post['content']['rendered'];
    $content_cleaned = preg_replace(OLD_ASSET_URL_PATTERN, NEW_ASSET_PATH, $content_raw);
    
    // Extract metadata
    $excerpt = strip_tags($wp_post['excerpt']['rendered']);
    $meta_description = mb_strimwidth($excerpt, 0, 160, '...');
    
    // Fallback featured media
    $meta_image = '';
    if (!empty($wp_post['featured_media'])) {
        // Fetch featured media URL
        $media_url = API_BASE_URL . '/media/' . $wp_post['featured_media'];
        $media_json = @file_get_contents($media_url);
        if ($media_json) {
            $media_data = json_decode($media_json, true);
            if (!empty($media_data['source_url'])) {
                $meta_image = preg_replace(OLD_ASSET_URL_PATTERN, NEW_ASSET_PATH, $media_data['source_url']);
            }
        }
    }

    $post_data = [
        'id'               => $wp_post_id,
        'title'            => $title,
        'slug'             => $slug,
        'content'          => $content_cleaned,
        'created_at'       => $created_at,
        'status'           => $status,
        'publish_at'       => $publish_at,
        'categories'       => $categories_str,
        'tags'             => $tags_str,
        'meta_description' => $meta_description,
        'meta_image'       => $meta_image
    ];

    echo sprintf("[%02d] Title: %s\n     Slug:       %s\n     Date:       %s\n     Status:     %s\n     Categories: %s\n", 
        $wp_post_id, 
        mb_strimwidth($title, 0, 50, '...'), 
        $slug, 
        $created_at, 
        $status,
        $categories_str
    );

    if ($execute && $pdo) {
        try {
            $sql = "INSERT INTO posts (id, title, slug, content, created_at, status, publish_at, categories, tags, meta_description, meta_image)
                    VALUES (:id, :title, :slug, :content, :created_at, :status, :publish_at, :categories, :tags, :meta_description, :meta_image)
                    ON CONFLICT(id) DO UPDATE SET
                        title = excluded.title,
                        slug = excluded.slug,
                        content = excluded.content,
                        created_at = excluded.created_at,
                        status = excluded.status,
                        publish_at = excluded.publish_at,
                        categories = excluded.categories,
                        tags = excluded.tags,
                        meta_description = excluded.meta_description,
                        meta_image = excluded.meta_image";
            $stmt = $pdo->prepare($sql);
            $stmt->execute($post_data);
            echo "     Status: Saved successfully.\n";
            $success_count++;
        } catch (PDOException $e) {
            echo "     Status: Error inserting: " . $e->getMessage() . "\n";
            $skipped_count++;
        }
    } else {
        echo "     Status: Read/Parsed successfully (Dry Run).\n";
        $success_count++;
    }
    echo "\n";
}

// 4. Fetch and download all media
echo "Fetching media from WordPress API...\n";
$page = 1;
while (true) {
    $media_url = API_BASE_URL . '/media?per_page=100&page=' . $page;
    $media_json = @file_get_contents($media_url);
    if (!$media_json) {
        break;
    }
    $media_data = json_decode($media_json, true);
    if (!is_array($media_data) || empty($media_data)) {
        break;
    }
    
    echo "Found " . count($media_data) . " media items on page $page.\n";
    foreach ($media_data as $media) {
        $source_url = $media['source_url'];
        
        // Parse the path to get relative path (e.g. YYYY/MM/filename.ext)
        if (preg_match('/wp-content\/uploads\/(.*)$/i', $source_url, $matches)) {
            $relative_path = $matches[1];
            $local_path = LOCAL_IMAGES_DIR . '/' . $relative_path;
            
            echo "Media: " . $source_url . " -> images/" . $relative_path . "\n";
            
            if ($execute) {
                $dir = dirname($local_path);
                if (!is_dir($dir)) {
                    mkdir($dir, 0755, true);
                }
                
                // Only download if it doesn't exist
                if (!file_exists($local_path)) {
                    $img_data = @file_get_contents($source_url);
                    if ($img_data) {
                        file_put_contents($local_path, $img_data);
                        echo "  [Downloaded]\n";
                    } else {
                        echo "  [Download Failed]\n";
                    }
                } else {
                    echo "  [Already Exists]\n";
                }
            }
        }
    }
    $page++;
}

echo "\n================ SUMMARY ================\n";
echo "Total posts processed: " . ($success_count + $skipped_count) . "\n";
echo "Successful:            " . $success_count . "\n";
echo "Failed/Skipped:        " . $skipped_count . "\n";
echo "=========================================\n";
