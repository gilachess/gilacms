<?php
// WordPress Custom PHP CMS Frontend
require_once __DIR__ . '/config.php';
session_start();

// Check if admin is logged in (session set by admin/index.php)
$is_admin = isset($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'] === true;

header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");

// Dynamically determine the base URL and path to handle local subdirectories and production domain roots
$protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https://' : 'http://';
$host = $_SERVER['HTTP_HOST'] ?? SITE_DOMAIN;

if (strpos($host, 'localhost') !== false || strpos($host, '127.0.0.1') !== false) {
    // Local development subfolder environment (uses directory name dynamically)
    $base_url = $protocol . $host . '/' . basename(__DIR__) . '/';
} else {
    // Production domain root environment
    $base_url = $protocol . $host . '/';
}

$base_path = parse_url($base_url, PHP_URL_PATH) ?? '/';
if (substr($base_path, -1) !== '/') {
    $base_path .= '/';
}
$img_prefix = $base_path . 'images/';

// Immediately redirect legacy query parameters to clean SEO URLs (failsafe check)
if (isset($_GET['slug']) && !empty($_GET['slug'])) {
    header("HTTP/1.1 301 Moved Permanently");
    header("Location: " . $base_url . trim($_GET['slug'], '/') . '/');
    exit();
}
if (isset($_GET['category']) && !empty($_GET['category'])) {
    header("HTTP/1.1 301 Moved Permanently");
    header("Location: " . $base_url . 'category/' . urlencode(trim($_GET['category'], '/')) . '/');
    exit();
}
if (isset($_GET['tag']) && !empty($_GET['tag'])) {
    header("HTTP/1.1 301 Moved Permanently");
    header("Location: " . $base_url . 'tag/' . urlencode(trim($_GET['tag'], '/')) . '/');
    exit();
}

// Parse request URI path into variables for clean URL routing
$request_path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
// Normalize local directory base if running on localhost
if ($base_path !== '/' && strpos($request_path, $base_path) === 0) {
    $request_path = '/' . substr($request_path, strlen($base_path));
}
$path = trim($request_path, '/');

// Route RSS feeds and Sitemap requests directly (SEO preservation)
if (!empty($path)) {
    if (preg_match('/^(feed|feed\/rss|feed\/rss2|feed\/atom|wp-rss\.php|wp-rss2\.php|wp-atom\.php|wp-rdf\.php)$/i', $path)) {
        require __DIR__ . '/feed.php';
        exit();
    }
    if ($path === 'sitemap.xml') {
        require __DIR__ . '/sitemap.php';
        exit();
    }
}

$db_file = DB_FILE;
$pdo = null;
$error = null;

try {
    if (!file_exists($db_file)) {
        throw new Exception("SQLite database file not found. Please run the import script first.");
    }
    $pdo = new PDO("sqlite:" . $db_file);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // Auto-migrate schema: add columns if they don't exist
    $columns_to_add = [
        'publish_at'       => 'TEXT',
        'categories'       => 'TEXT',
        'tags'             => 'TEXT',
        'meta_description' => 'TEXT',
        'meta_image'       => 'TEXT'
    ];
    
    foreach ($columns_to_add as $col => $type) {
        try {
            $pdo->query("SELECT $col FROM posts LIMIT 1");
        } catch (PDOException $e) {
            $pdo->exec("ALTER TABLE posts ADD COLUMN $col $type");
        }
    }
} catch (Exception $e) {
    $error = $e->getMessage();
}

// Get Filters & Pagination Parameters
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$category = isset($_GET['category']) ? trim($_GET['category']) : '';
$tag = isset($_GET['tag']) ? trim($_GET['tag']) : '';
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$page_size = 9; // Show 9 cards per page (3x3 grid)
$offset = ($page - 1) * $page_size;

$posts = [];
$total_posts = 0;
$total_pages = 0;
$active_post = null;
$active_id = isset($_GET['id']) ? (int)$_GET['id'] : null;
$active_slug = isset($_GET['slug']) ? trim($_GET['slug']) : null;
$year_filter = null;
$month_filter = null;

// Parse request URI path into variables for clean URL routing
if (!empty($path)) {
    // Category match: category/Name
    if (preg_match('/^category\/([^\/]+)$/i', $path, $matches)) {
        $category = urldecode($matches[1]);
    }
    // Tag match: tag/Name
    elseif (preg_match('/^tag\/([^\/]+)$/i', $path, $matches)) {
        $tag = urldecode($matches[1]);
    }
    // Date archive match: YYYY/MM (e.g. 2025/08)
    elseif (preg_match('/^([0-9]{4})\/([0-9]{2})$/', $path, $matches)) {
        $year_filter = (int)$matches[1];
        $month_filter = (int)$matches[2];
    }
    // Post slug match: anything that doesn't contain a slash and doesn't end with .php
    elseif (strpos($path, '/') === false && !preg_match('/\.php$/i', $path)) {
        $active_slug = $path;
    }
}

// Downstream redirects handled at top of file

// 301 Redirect old /?id= urls to clean SEO slugs
if ($active_id && !$active_slug && $pdo) {
    try {
        $now = date('Y-m-d H:i:s');
        $redirect_stmt = $pdo->prepare("SELECT slug FROM posts WHERE id = :id AND status = 'published' AND (publish_at IS NULL OR publish_at <= :now)");
        $redirect_stmt->execute([':id' => $active_id, ':now' => $now]);
        $redirect_post = $redirect_stmt->fetch(PDO::FETCH_ASSOC);
        if ($redirect_post && !empty($redirect_post['slug'])) {
            header("HTTP/1.1 301 Moved Permanently");
            header("Location: " . $base_url . trim($redirect_post['slug'], '/') . '/');
            exit();
        }
    } catch (Exception $e) {
        // Fall back gracefully
    }
}

// Build dynamic pagination prefix
$pagination_prefix = '';
if (!empty($category)) {
    $pagination_prefix = 'category/' . urlencode($category) . '/';
} elseif (!empty($tag)) {
    $pagination_prefix = 'tag/' . urlencode($tag) . '/';
} elseif ($year_filter && $month_filter) {
    $pagination_prefix = sprintf('%04d/%02d/', $year_filter, $month_filter);
}

// Categories and Tags list for filter options
$all_categories = [];
$all_tags = [];

if ($pdo) {
    try {
        $now = date('Y-m-d H:i:s');
        
        // Build SQL Where Clauses
        $where_clauses = ["status = 'published' AND (publish_at IS NULL OR publish_at <= :now)"];
        $params = [':now' => $now];
        
        if (!empty($search)) {
            $where_clauses[] = "(title LIKE :search OR content LIKE :search)";
            $params[':search'] = '%' . $search . '%';
        }
        if (!empty($category)) {
            $where_clauses[] = "categories LIKE :category";
            $params[':category'] = '%' . $category . '%';
        }
        if (!empty($tag)) {
            $where_clauses[] = "tags LIKE :tag";
            $params[':tag'] = '%' . $tag . '%';
        }
        if ($year_filter && $month_filter) {
            $where_clauses[] = "strftime('%Y-%m', created_at) = :date_filter";
            $params[':date_filter'] = sprintf('%04d-%02d', $year_filter, $month_filter);
        }
        
        $where_sql = implode(' AND ', $where_clauses);
        
        // Count total posts
        $count_stmt = $pdo->prepare("SELECT COUNT(*) FROM posts WHERE $where_sql");
        $count_stmt->execute($params);
        $total_posts = (int)$count_stmt->fetchColumn();
        $total_pages = ceil($total_posts / $page_size);
        
        // Fetch paginated posts (include full content for extracting thumbnails & excerpts)
        $query_sql = "SELECT * FROM posts 
                      WHERE $where_sql 
                      ORDER BY created_at DESC 
                      LIMIT :limit OFFSET :offset";
        
        $stmt = $pdo->prepare($query_sql);
        $stmt->bindValue(':limit', $page_size, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        foreach ($params as $key => $val) {
            $stmt->bindValue($key, $val);
        }
        $stmt->execute();
        $posts = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Fetch Active Single Post Details
        if ($active_slug) {
            $stmt = $pdo->prepare("SELECT * FROM posts WHERE slug = :slug AND status = 'published' AND (publish_at IS NULL OR publish_at <= :now)");
            $stmt->execute([':slug' => $active_slug, ':now' => $now]);
            $active_post = $stmt->fetch(PDO::FETCH_ASSOC);
        } elseif ($active_id) {
            $stmt = $pdo->prepare("SELECT * FROM posts WHERE id = :id AND status = 'published' AND (publish_at IS NULL OR publish_at <= :now)");
            $stmt->execute([':id' => $active_id, ':now' => $now]);
            $active_post = $stmt->fetch(PDO::FETCH_ASSOC);
        }
        
        // Next / Prev Post Links (Single post view)
        $next_post = null;
        $prev_post = null;
        if ($active_post) {
            // Next post (older)
            $next_stmt = $pdo->prepare("SELECT id, title, slug FROM posts 
                                        WHERE status = 'published' AND (publish_at IS NULL OR publish_at <= :now) 
                                        AND created_at < :created_at 
                                        ORDER BY created_at DESC LIMIT 1");
            $next_stmt->execute([':now' => $now, ':created_at' => $active_post['created_at']]);
            $next_post = $next_stmt->fetch(PDO::FETCH_ASSOC);
            
            // Prev post (newer)
            $prev_stmt = $pdo->prepare("SELECT id, title, slug FROM posts 
                                        WHERE status = 'published' AND (publish_at IS NULL OR publish_at <= :now) 
                                        AND created_at > :created_at 
                                        ORDER BY created_at ASC LIMIT 1");
            $prev_stmt->execute([':now' => $now, ':created_at' => $active_post['created_at']]);
            $prev_post = $prev_stmt->fetch(PDO::FETCH_ASSOC);
        }
        
        // Collect categories and tags for filter links
        $cat_stmt = $pdo->query("SELECT DISTINCT categories, tags FROM posts WHERE status = 'published'");
        while ($row = $cat_stmt->fetch(PDO::FETCH_ASSOC)) {
            if (!empty($row['categories'])) {
                foreach (explode(',', $row['categories']) as $c) {
                    $c = trim($c);
                    if (!empty($c)) $all_categories[$c] = true;
                }
            }
            if (!empty($row['tags'])) {
                foreach (explode(',', $row['tags']) as $t) {
                    $t = trim($t);
                    if (!empty($t)) $all_tags[$t] = true;
                }
            }
        }
        $all_categories = array_keys($all_categories);
        $all_tags = array_keys($all_tags);
        sort($all_categories);
        sort($all_tags);
        
    } catch (PDOException $e) {
        $error = "Query failed: " . $e->getMessage();
    }
}

// Extract post thumbnail URL
function get_post_thumbnail($post, $img_prefix = 'images/') {
    $img = '';
    if (!empty($post['meta_image'])) {
        $img = $post['meta_image'];
        // If meta_image starts with uploads/ instead of images/uploads/, prepend images/
        if (strpos($img, 'uploads/') === 0) {
            $img = 'images/' . $img;
        }
    } elseif (preg_match('/<img[^>]+src=["\']([^"\']+)["\']/i', $post['content'], $matches)) {
        $img = $matches[1];
    }
    
    if (!empty($img)) {
        // If it's a data URI or absolute http/https URL, return it directly
        if (strpos($img, 'data:') === 0 || preg_match('/^https?:\/\//i', $img)) {
            return $img;
        }
        
        // Strip any relative prefixes (like ../ or ../../) or absolute slashes at the beginning
        $img = preg_replace('/^(\.\.\/|\/)+/', '', $img);
        // Normalize: if it starts with images/, strip it so we can append $img_prefix uniformly
        if (strpos($img, 'images/') === 0) {
            $img = substr($img, 7);
        }
        return $img_prefix . $img;
    }
    return ''; // Fallback gradient indicator
}

// Clean and sanitize excerpt text
function clean_excerpt($content, $length = 110) {
    // 1. Strip HTML tags
    $text = strip_tags($content);
    // 2. Decode HTML entities (e.g. &nbsp; becomes real space, &amp; becomes &, etc.)
    $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    // 3. Replace any multibyte non-breaking spaces with normal spaces
    $text = str_replace("\xc2\xa0", ' ', $text);
    // 4. Collapse multiple whitespaces/newlines/tabs into a single space
    $text = preg_replace('/\s+/', ' ', $text);
    // 5. Trim leading/trailing spacing
    $text = trim($text);
    // 6. Truncate using multibyte string width
    return mb_strimwidth($text, 0, $length, '...');
}

// Clean content image paths for local display
if ($active_post) {
    // Replace any relative or absolute image path prefixes with the dynamic $img_prefix
    $content_clean = preg_replace('/(\.\.\/|\/)+images\//i', $img_prefix, $active_post['content']);
    $active_post['display_content'] = $content_clean;
}

// View mode: 'single' if active post is set, otherwise 'index'
$view_mode = $active_post ? 'single' : 'index';

// Initialize Meta & Title parameters
$meta_desc = SITE_DESCRIPTION;
$meta_title = SITE_NAME;
$meta_img = '';
$meta_url = $base_url;
$meta_type = 'website';
$canonical_path = '';

if ($active_post) {
    $meta_desc = !empty($active_post['meta_description']) 
        ? trim($active_post['meta_description']) 
        : mb_strimwidth(trim(preg_replace('/\s+/', ' ', strip_tags($active_post['content']))), 0, 155, '...');
    $meta_title = $active_post['title'] . ' | ' . SITE_NAME;
    $meta_img = get_post_thumbnail($active_post, $img_prefix);
    $meta_url = $base_url . htmlspecialchars($active_post['slug']) . '/';
    $meta_type = 'article';
} else {
    // Index/Archive canonical with paginated pages self-referencing
    if (!empty($category)) {
        $canonical_path = 'category/' . urlencode($category) . '/';
        $meta_title = htmlspecialchars($category) . ' | ' . SITE_NAME;
        $meta_desc = 'Browse all articles under the ' . htmlspecialchars($category) . ' category on ' . SITE_NAME . '.';
    } elseif (!empty($tag)) {
        $canonical_path = 'tag/' . urlencode($tag) . '/';
        $meta_title = '#' . htmlspecialchars($tag) . ' | ' . SITE_NAME;
        $meta_desc = 'Browse all articles tagged with #' . htmlspecialchars($tag) . ' on ' . SITE_NAME . '.';
    } elseif ($year_filter && $month_filter) {
        $canonical_path = sprintf('%04d/%02d/', $year_filter, $month_filter);
        $month_name = date('F Y', mktime(0, 0, 0, $month_filter, 10, $year_filter));
        $meta_title = $month_name . ' Archive | ' . SITE_NAME;
        $meta_desc = 'Browse all articles from ' . $month_name . ' on ' . SITE_NAME . '.';
    }
    
    // Adjust title for pagination
    if ($page > 1) {
        $meta_title .= ' - Page ' . $page;
    }

    $meta_url = $base_url . $canonical_path;
    if ($page > 1) {
        $meta_url .= '?page=' . $page;
    }
    if (!empty($search)) {
        $meta_url .= ($page > 1 ? '&' : '?') . 'search=' . urlencode($search);
    }
}

// Prepare absolute URL for og:image
$og_img_url = '';
if (!empty($meta_img)) {
    if (strpos($meta_img, 'data:') === 0) {
        // Social platforms do not support base64 data URIs for og:image.
        // Fallback to default logo.
        $og_img_url = $base_url . 'images/uploads/2026/06/gilachess_logo_new-1280x720.png';
    } elseif (preg_match('/^https?:\/\//i', $meta_img)) {
        $og_img_url = $meta_img;
    } else {
        $og_img_url = $base_url . ltrim($meta_img, '/');
    }
} else {
    // Fallback default banner for the brand
    $og_img_url = $base_url . 'images/uploads/2026/06/gilachess_logo_new-1280x720.png';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($meta_title); ?></title>
    <base href="<?php echo htmlspecialchars($base_url); ?>">
    
    <meta name="description" content="<?php echo htmlspecialchars($meta_desc); ?>">
    <link rel="canonical" href="<?php echo htmlspecialchars($meta_url); ?>">
    <?php if ($view_mode === 'index' && $total_pages > 1): ?>
        <?php if ($page > 1): ?>
            <link rel="prev" href="<?php echo htmlspecialchars($base_url . $canonical_path . ($page - 1 == 1 ? '' : '?page=' . ($page - 1))); ?>">
        <?php endif; ?>
        <?php if ($page < $total_pages): ?>
            <link rel="next" href="<?php echo htmlspecialchars($base_url . $canonical_path . '?page=' . ($page + 1)); ?>">
        <?php endif; ?>
    <?php endif; ?>
    <meta property="og:title" content="<?php echo htmlspecialchars($meta_title); ?>">
    <meta property="og:description" content="<?php echo htmlspecialchars($meta_desc); ?>">
    <meta property="og:type" content="<?php echo $meta_type; ?>">
    <meta property="og:url" content="<?php echo $meta_url; ?>">
    <?php if (!empty($og_img_url)): ?>
        <meta property="og:image" content="<?php echo htmlspecialchars($og_img_url); ?>">
    <?php endif; ?>
    <meta name="twitter:card" content="summary_large_image">
    
    <!-- JSON-LD Structured Data -->
    <script type="application/ld+json">
    <?php
    $schema = [];
    if ($active_post) {
        $schema = [
            '@context' => 'https://schema.org',
            '@type' => 'BlogPosting',
            'headline' => $active_post['title'],
            'description' => $meta_desc,
            'datePublished' => date('c', strtotime($active_post['created_at'])),
            'dateModified' => date('c', strtotime($active_post['created_at'])),
            'mainEntityOfPage' => [
                '@type' => 'WebPage',
                '@id' => $meta_url
            ],
            'author' => [
                '@type' => 'Person',
                'name' => SITE_NAME . ' Editor'
            ],
            'publisher' => [
                '@type' => 'Organization',
                'name' => SITE_NAME,
                'logo' => [
                    '@type' => 'ImageObject',
                    'url' => $base_url . 'favicon/favicon-96x96.png'
                ]
            ]
        ];
        if (!empty($og_img_url)) {
            $schema['image'] = [$og_img_url];
        }
    } else {
        $schema = [
            '@context' => 'https://schema.org',
            '@graph' => [
                [
                    '@type' => 'Blog',
                    'name' => SITE_NAME,
                    'description' => $meta_desc,
                    'url' => $base_url,
                    'publisher' => [
                        '@type' => 'Organization',
                        'name' => SITE_NAME
                    ]
                ],
                [
                    '@type' => 'WebSite',
                    'name' => SITE_NAME,
                    'url' => $base_url,
                    'potentialAction' => [
                        '@type' => 'SearchAction',
                        'target' => [
                            '@type' => 'EntryPoint',
                            'urlTemplate' => $base_url . '?search={search_term_string}'
                        ],
                        'query-input' => 'required name=search_term_string'
                    ]
                ]
            ]
        ];
    }
    echo json_encode($schema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    ?>
    </script>

    <?php if ($active_post || !empty($category) || !empty($tag)): ?>
    <script type="application/ld+json">
    <?php
    $breadcrumbs = [
        '@context' => 'https://schema.org',
        '@type' => 'BreadcrumbList',
        'itemListElement' => [
            [
                '@type' => 'ListItem',
                'position' => 1,
                'name' => 'Home',
                'item' => $base_url
            ]
        ]
    ];
    
    if ($active_post) {
        $post_categories = !empty($active_post['categories']) ? explode(',', $active_post['categories']) : [];
        $pos = 2;
        if (!empty($post_categories)) {
            $first_cat = trim($post_categories[0]);
            $breadcrumbs['itemListElement'][] = [
                '@type' => 'ListItem',
                'position' => $pos++,
                'name' => $first_cat,
                'item' => $base_url . 'category/' . urlencode($first_cat) . '/'
            ];
        }
        $breadcrumbs['itemListElement'][] = [
            '@type' => 'ListItem',
            'position' => $pos,
            'name' => $active_post['title'],
            'item' => $meta_url
        ];
    } elseif (!empty($category)) {
        $breadcrumbs['itemListElement'][] = [
            '@type' => 'ListItem',
            'position' => 2,
            'name' => $category,
            'item' => $base_url . 'category/' . urlencode($category) . '/'
        ];
    } elseif (!empty($tag)) {
        $breadcrumbs['itemListElement'][] = [
            '@type' => 'ListItem',
            'position' => 2,
            'name' => '#' . $tag,
            'item' => $base_url . 'tag/' . urlencode($tag) . '/'
        ];
    }
    echo json_encode($breadcrumbs, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    ?>
    </script>
    <?php endif; ?>
    <link rel="icon" type="image/png" href="/favicon/favicon-96x96.png" sizes="96x96" />
<link rel="icon" type="image/svg+xml" href="/favicon/favicon.svg" />
<link rel="shortcut icon" href="/favicon/favicon.ico" />
<link rel="apple-touch-icon" sizes="180x180" href="/favicon/apple-touch-icon.png" />
<link rel="manifest" href="/favicon/site.webmanifest" />
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --bg-dark: #0b0f19;
            --panel-bg: rgba(17, 24, 39, 0.7);
            --card-bg: rgba(31, 41, 55, 0.4);
            --border-color: rgba(255, 255, 255, 0.08);
            --accent-color: #6366f1;
            --accent-glow: rgba(99, 102, 241, 0.15);
            --text-primary: #f3f4f6;
            --text-secondary: #9ca3af;
            --badge-bg: rgba(16, 185, 129, 0.15);
            --badge-text: #34d399;
        }

        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        body {
            font-family: 'Outfit', sans-serif;
            background-color: var(--bg-dark);
            color: var(--text-primary);
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            background-image: 
                radial-gradient(at 0% 0%, rgba(99, 102, 241, 0.08) 0px, transparent 50%),
                radial-gradient(at 100% 100%, rgba(236, 72, 153, 0.04) 0px, transparent 50%);
            overflow-x: hidden;
        }

        /* Navigation Header */
        .header-nav {
            background: var(--panel-bg);
            backdrop-filter: blur(12px);
            border-bottom: 1px solid var(--border-color);
            padding: 16px 24px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            position: sticky;
            top: 0;
            z-index: 100;
        }

        .header-nav h1 {
            font-size: 20px;
            font-weight: 600;
            background: linear-gradient(135deg, #a78bfa, #6366f1);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }

        .header-actions {
            display: flex;
            gap: 16px;
            align-items: center;
        }

        .search-form {
            display: flex;
            align-items: center;
        }

        .search-input {
            background: rgba(0, 0, 0, 0.3);
            border: 1px solid var(--border-color);
            padding: 8px 16px;
            border-radius: 20px;
            color: var(--text-primary);
            font-family: inherit;
            font-size: 13px;
            outline: none;
            width: 220px;
            transition: all 0.3s;
        }

        .search-input:focus {
            border-color: var(--accent-color);
            box-shadow: 0 0 8px var(--accent-glow);
            width: 300px;
        }

        .btn-link {
            font-size: 13px;
            font-weight: 500;
            color: var(--text-secondary);
            padding: 6px 12px;
            border-radius: 6px;
            text-decoration: none;
            transition: all 0.2s;
        }

        .btn-link:hover {
            color: #fff;
            background: rgba(255, 255, 255, 0.05);
        }

        <?php if ($view_mode === 'index'): ?>
        /* Hero banner for Homepage */
        .hero-banner {
            padding: 64px 24px 32px;
            text-align: center;
            max-width: 800px;
            margin: 0 auto;
        }

        .hero-banner h2 {
            font-size: 38px;
            font-weight: 700;
            color: #fff;
            margin-bottom: 12px;
            letter-spacing: -0.02em;
        }

        .hero-banner p {
            color: var(--text-secondary);
            font-size: 16px;
            line-height: 1.5;
        }

        /* Pill Filters Bar */
        .filters-bar {
            display: flex;
            justify-content: center;
            flex-wrap: wrap;
            gap: 10px;
            max-width: 1000px;
            margin: 0 auto 32px;
            padding: 0 24px;
        }

        .pill-filter {
            font-size: 13px;
            font-weight: 500;
            background: rgba(255, 255, 255, 0.03);
            border: 1px solid var(--border-color);
            color: var(--text-secondary);
            padding: 8px 16px;
            border-radius: 20px;
            text-decoration: none;
            transition: all 0.25s ease;
        }

        .pill-filter:hover, .pill-filter.active {
            background: var(--accent-color);
            color: #fff;
            border-color: var(--accent-color);
            box-shadow: 0 4px 12px var(--accent-glow);
        }

        .pill-clear {
            background: rgba(239, 68, 68, 0.1);
            color: #fca5a5;
            border-color: rgba(239, 68, 68, 0.2);
        }

        .pill-clear:hover {
            background: rgba(239, 68, 68, 0.2);
            color: #fff;
            border-color: rgba(239, 68, 68, 0.3);
        }

        .back-home-link {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            font-size: 14px;
            font-weight: 500;
            color: var(--accent-color);
            text-decoration: none;
            margin-bottom: 24px;
            transition: all 0.2s;
        }

        .back-home-link:hover {
            color: #fff;
            transform: translateX(-4px);
        }

        /* Blog Grid Layout */
        .blog-grid-container {
            max-width: 1100px;
            width: 100%;
            margin: 0 auto;
            padding: 0 24px 64px;
        }

        .blog-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(310px, 1fr));
            gap: 28px;
        }

        /* Blog Card Styling */
        .blog-card {
            background: var(--card-bg);
            backdrop-filter: blur(12px);
            -webkit-backdrop-filter: blur(12px);
            border: 1px solid var(--border-color);
            border-radius: 16px;
            overflow: hidden;
            display: flex;
            flex-direction: column;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.15);
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            text-decoration: none;
            color: inherit;
        }

        .blog-card:hover {
            transform: translateY(-6px);
            border-color: var(--accent-color);
            box-shadow: 0 10px 25px rgba(99, 102, 241, 0.15);
        }

        .card-thumbnail-container {
            height: 180px;
            width: 100%;
            overflow: hidden;
            background: #090d16;
            border-bottom: 1px solid var(--border-color);
        }

        .card-thumbnail {
            width: 100%;
            height: 100%;
            object-fit: cover;
            transition: transform 0.5s;
        }

        .blog-card:hover .card-thumbnail {
            transform: scale(1.05);
        }

        .card-thumbnail-fallback {
            width: 100%;
            height: 100%;
            background: linear-gradient(135deg, #1e1b4b, #311042);
            display: flex;
            align-items: center;
            justify-content: center;
            position: relative;
        }

        .card-thumbnail-fallback::before {
            content: "MCF NEWS";
            font-size: 16px;
            font-weight: 700;
            letter-spacing: 0.15em;
            color: rgba(255, 255, 255, 0.15);
        }

        .card-content {
            padding: 20px;
            flex: 1;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
        }

        .card-meta {
            display: flex;
            gap: 10px;
            align-items: center;
            margin-bottom: 12px;
            flex-wrap: wrap;
        }

        .card-badge {
            font-size: 10px;
            font-weight: 600;
            padding: 3px 8px;
            border-radius: 9999px;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }

        .card-badge-date {
            background: rgba(255, 255, 255, 0.06);
            color: var(--text-secondary);
        }

        .card-badge-category {
            background: var(--badge-bg);
            color: var(--badge-text);
        }

        .card-title {
            font-size: 17px;
            font-weight: 600;
            line-height: 1.35;
            color: #fff;
            margin-bottom: 8px;
            display: -webkit-box;
            -webkit-line-clamp: 2;
            -webkit-box-orient: vertical;
            overflow: hidden;
            height: 46px;
        }

        .card-excerpt {
            font-size: 13.5px;
            color: var(--text-secondary);
            line-height: 1.45;
            display: -webkit-box;
            -webkit-line-clamp: 2;
            -webkit-box-orient: vertical;
            overflow: hidden;
            margin-bottom: 16px;
            height: 38px;
        }

        .card-footer {
            font-size: 12px;
            font-weight: 600;
            color: var(--accent-color);
            display: flex;
            align-items: center;
            gap: 4px;
        }

        .card-footer span {
            transition: transform 0.2s;
        }

        .blog-card:hover .card-footer span {
            transform: translateX(3px);
        }

        <?php else: ?>
        /* Single Post Reader Layout */
        .reader-layout {
            display: grid;
            grid-template-columns: 1fr 300px;
            gap: 40px;
            max-width: 1100px;
            width: 100%;
            margin: 0 auto;
            padding: 40px 24px 64px;
        }

        @media (max-width: 900px) {
            .reader-layout {
                grid-template-columns: 1fr;
            }
        }

        .post-card {
            background: var(--card-bg);
            backdrop-filter: blur(12px);
            -webkit-backdrop-filter: blur(12px);
            border: 1px solid var(--border-color);
            border-radius: 20px;
            padding: 40px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.2);
        }

        .post-header {
            margin-bottom: 32px;
            border-bottom: 1px solid var(--border-color);
            padding-bottom: 24px;
        }

        .post-meta-badges {
            display: flex;
            gap: 12px;
            align-items: center;
            margin-bottom: 16px;
            flex-wrap: wrap;
        }

        .post-title {
            font-size: 32px;
            font-weight: 700;
            line-height: 1.25;
            color: #fff;
            margin-bottom: 8px;
        }

        .post-slug {
            font-size: 14px;
            color: var(--accent-color);
            font-family: monospace;
        }

        .post-body {
            line-height: 1.7;
            font-size: 16px;
            color: #d1d5db;
        }

        .post-body p {
            margin-bottom: 20px;
        }

        .post-body a {
            color: var(--accent-color);
            text-decoration: none;
            border-bottom: 1px dashed var(--accent-color);
            transition: all 0.2s;
        }

        .post-body a:hover {
            color: #fff;
            border-bottom-style: solid;
        }

        .post-body ul, .post-body ol {
            margin-left: 24px;
            margin-bottom: 20px;
        }

        .post-body li {
            margin-bottom: 8px;
        }

        .post-body img {
            max-width: 100%;
            height: auto;
            border-radius: 12px;
            margin: 24px 0;
            box-shadow: 0 8px 24px rgba(0, 0, 0, 0.3);
            border: 1px solid var(--border-color);
        }

        .post-body figure {
            margin: 24px 0;
            text-align: center;
        }

        .post-body figure figcaption {
            font-size: 13px;
            color: var(--text-secondary);
            margin-top: 8px;
        }

        .wp-block-embed__wrapper {
            position: relative;
            padding-bottom: 56.25%;
            height: 0;
            overflow: hidden;
            border-radius: 12px;
            box-shadow: 0 8px 24px rgba(0, 0, 0, 0.3);
            border: 1px solid var(--border-color);
            margin: 24px 0;
        }

        .wp-block-embed__wrapper iframe {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            border: 0;
        }

        /* Post Navigation */
        .post-navigation {
            display: flex;
            justify-content: space-between;
            margin-top: 40px;
            padding-top: 32px;
            border-top: 1px solid var(--border-color);
            gap: 20px;
        }

        .nav-item {
            width: 50%;
            text-decoration: none;
            color: inherit;
            display: flex;
            flex-direction: column;
            transition: all 0.2s;
        }

        .nav-item-label {
            font-size: 12px;
            font-weight: 500;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            color: var(--text-secondary);
            margin-bottom: 6px;
        }

        .nav-item-title {
            font-size: 14px;
            font-weight: 600;
            color: #fff;
            transition: color 0.2s;
        }

        .nav-item:hover .nav-item-title {
            color: var(--accent-color);
        }

        .nav-prev { align-items: flex-start; text-align: left; }
        .nav-next { align-items: flex-end; text-align: right; }

        /* Reader Sidebar widgets */
        .reader-sidebar {
            display: flex;
            flex-direction: column;
            gap: 24px;
        }

        .sidebar-widget {
            background: var(--panel-bg);
            backdrop-filter: blur(12px);
            -webkit-backdrop-filter: blur(12px);
            border: 1px solid var(--border-color);
            border-radius: 16px;
            padding: 20px;
        }

        .widget-title {
            font-size: 14px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            color: #fff;
            margin-bottom: 16px;
            border-bottom: 1px solid var(--border-color);
            padding-bottom: 8px;
        }

        .recent-post-link {
            display: flex;
            gap: 12px;
            text-decoration: none;
            color: inherit;
            margin-bottom: 12px;
            padding: 6px;
            border-radius: 8px;
            transition: all 0.2s;
        }

        .recent-post-link:hover {
            background: rgba(255, 255, 255, 0.03);
        }

        .recent-thumbnail-container {
            width: 60px;
            height: 45px;
            background: #090d16;
            border-radius: 4px;
            overflow: hidden;
            flex-shrink: 0;
        }

        .recent-thumbnail {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .recent-post-title {
            font-size: 13px;
            font-weight: 500;
            line-height: 1.3;
            color: var(--text-primary);
            display: -webkit-box;
            -webkit-line-clamp: 2;
            -webkit-box-orient: vertical;
            overflow: hidden;
        }

        <?php endif; ?>

        <?php if ($view_mode === 'index'): ?>
        /* Generic Pagination */
        .pagination-bar {
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 12px;
            margin-top: 40px;
        }

        .btn-pagination {
            font-size: 13px;
            font-weight: 500;
            color: var(--text-primary);
            text-decoration: none;
            background: rgba(255, 255, 255, 0.05);
            border: 1px solid var(--border-color);
            padding: 8px 16px;
            border-radius: 20px;
            transition: all 0.2s;
        }

        .btn-pagination:hover:not(.disabled) {
            background: var(--accent-color);
            border-color: var(--accent-color);
            color: #fff;
        }

        .btn-pagination.disabled {
            opacity: 0.3;
            cursor: not-allowed;
            pointer-events: none;
        }

        <?php endif; ?>

        /* Footer */
        .site-footer {
            background: var(--panel-bg);
            border-top: 1px solid var(--border-color);
            padding: 48px 24px 24px;
            font-size: 14px;
            color: var(--text-secondary);
            margin-top: auto;
        }

        .footer-columns {
            display: grid;
            grid-template-columns: 2fr 1fr 1fr;
            gap: 40px;
            max-width: 1100px;
            margin: 0 auto 32px;
        }

        @media (max-width: 768px) {
            .footer-columns {
                grid-template-columns: 1fr;
                gap: 24px;
            }
        }

        .footer-column h4 {
            color: var(--text-primary);
            font-size: 15px;
            font-weight: 600;
            margin-bottom: 16px;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }

        .footer-column p {
            line-height: 1.6;
            font-size: 13px;
        }

        .footer-column ul {
            list-style: none;
            padding: 0;
            margin: 0;
            display: flex;
            flex-direction: column;
            gap: 10px;
        }

        .footer-column ul li a {
            color: var(--text-secondary);
            text-decoration: none;
            transition: color 0.2s;
            font-size: 13px;
        }

        .footer-column ul li a:hover {
            color: var(--accent-color);
        }

        .footer-bottom {
            text-align: center;
            border-top: 1px solid var(--border-color);
            padding-top: 24px;
            font-size: 12px;
            color: rgba(255, 255, 255, 0.4);
            max-width: 1100px;
            margin: 0 auto;
        }

        .visually-hidden {
            position: absolute;
            width: 1px;
            height: 1px;
            padding: 0;
            margin: -1px;
            overflow: hidden;
            clip: rect(0, 0, 0, 0);
            white-space: nowrap;
            border: 0;
        }
    </style>
</head>
<body>

    <!-- Header Navigation -->
    <header class="header-nav">
        <a href="./" style="text-decoration: none; color: inherit; display: flex; align-items: center; gap: 12px;">
            <div style="background: linear-gradient(135deg, var(--accent-color) 0%, #4f46e5 100%); width: 36px; height: 36px; border-radius: 8px; display: flex; align-items: center; justify-content: center; box-shadow: 0 4px 12px var(--accent-glow);">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#ffffff" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M19 22H5c0-1.5 1.5-3 3-3h8c1.5 0 3 1.5 3 3z"/>
                    <path d="M7 19v-2c0-2.8 2.2-5 5-5h2c1.7 0 3-1.3 3-3V5c0-1.7-1.3-3-3-3H9.5C7 2 5 4 5 6.5C5 8.5 6 9.5 7 11V19"/>
                    <path d="M12 5h.01"/>
                </svg>
            </div>
            <?php $logo_tag = ($view_mode === 'index') ? 'h1' : 'div'; ?>
            <<?php echo $logo_tag; ?> style="font-size: 20px; font-weight: 700; background: linear-gradient(to right, #ffffff, #d1d5db); -webkit-background-clip: text; -webkit-text-fill-color: transparent; letter-spacing: -0.5px; margin: 0;">Gila<span style="color: var(--accent-color); -webkit-text-fill-color: initial;">Chess</span></<?php echo $logo_tag; ?>>
        </a>
        <div class="header-actions">
            <form method="GET" action="./" class="search-form">
                <input type="text" name="search" class="search-input" placeholder="Search news..." value="<?php echo htmlspecialchars($search); ?>">
                <?php if (!empty($category)): ?>
                    <input type="hidden" name="category" value="<?php echo htmlspecialchars($category); ?>">
                <?php endif; ?>
            </form>
            <a href="feed.php" target="_blank" class="btn-link">RSS Feed</a>
        </div>
    </header>

    <?php if ($view_mode === 'index'): ?>
        <!-- ================= BLOG GRID INDEX VIEW ================= -->
        <h1 class="visually-hidden">Malaysia's Premier Chess Blog & Tournament Coverage</h1>
        
        <section class="hero-banner">
            <?php if (!empty($category)): ?>
                <h2>Category: <?php echo htmlspecialchars($category); ?></h2>
                <p>Browsing all articles under the "<?php echo htmlspecialchars($category); ?>" category.</p>
            <?php elseif (!empty($tag)): ?>
                <h2>Tag: #<?php echo htmlspecialchars($tag); ?></h2>
                <p>Browsing all articles tagged with "#<?php echo htmlspecialchars($tag); ?>".</p>
            <?php elseif ($year_filter && $month_filter): ?>
                <h2>Archive: <?php echo htmlspecialchars($meta_title); ?></h2>
                <p>Browsing all articles published in <?php echo htmlspecialchars($month_name ?? ''); ?>.</p>
            <?php else: ?>
                <h2><?php echo htmlspecialchars(SITE_NAME); ?></h2>
                <p><?php echo htmlspecialchars(SITE_DESCRIPTION); ?></p>
            <?php endif; ?>
        </section>

        <!-- Categories & Filters -->
        <div class="filters-bar">
            <a href="./" class="pill-filter <?php echo empty($category) && empty($tag) && empty($search) ? 'active' : ''; ?>">All Articles</a>
            
            <?php foreach ($all_categories as $cat): ?>
                <a href="category/<?php echo urlencode($cat); ?>/" class="pill-filter <?php echo $category === $cat ? 'active' : ''; ?>"><?php echo htmlspecialchars($cat); ?></a>
            <?php endforeach; ?>

            <?php if (!empty($category) || !empty($tag) || !empty($search)): ?>
                <a href="./" class="pill-filter pill-clear">Clear Filters ✕</a>
            <?php endif; ?>
        </div>

        <div class="blog-grid-container">
            <?php if (empty($posts)): ?>
                <div style="text-align: center; padding: 64px 24px; background: var(--panel-bg); border-radius: 16px; border: 1px solid var(--border-color);">
                    <p style="color: var(--text-secondary); font-size: 16px;">No articles found matching your query.</p>
                </div>
            <?php else: ?>
                <div class="blog-grid">
                    <?php foreach ($posts as $post): 
                        $thumb = get_post_thumbnail($post, $img_prefix);
                        $excerpt = clean_excerpt($post['content'], 110);
                    ?>
                        <a href="<?php echo htmlspecialchars($post['slug']); ?>/" class="blog-card">
                            <div class="card-thumbnail-container">
                                <?php if (!empty($thumb)): ?>
                                    <img src="<?php echo htmlspecialchars($thumb); ?>" class="card-thumbnail" alt="<?php echo htmlspecialchars($post['title']); ?>" loading="lazy">
                                <?php else: ?>
                                    <div class="card-thumbnail-fallback"></div>
                                <?php endif; ?>
                            </div>
                            <div class="card-content">
                                <div>
                                    <div class="card-meta">
                                        <span class="card-badge card-badge-date"><?php echo date('M d, Y', strtotime($post['created_at'])); ?></span>
                                        <?php if (!empty($post['categories'])): ?>
                                            <?php foreach (explode(',', $post['categories']) as $cat): ?>
                                                <span class="card-badge card-badge-category"><?php echo htmlspecialchars(trim($cat)); ?></span>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    </div>
                                    <h3 class="card-title"><?php echo htmlspecialchars($post['title']); ?></h3>
                                    <p class="card-excerpt"><?php echo htmlspecialchars($excerpt); ?></p>
                                </div>
                                <div class="card-footer">
                                    Read Full Article <span>→</span>
                                </div>
                            </div>
                        </a>
                    <?php endforeach; ?>
                </div>

                <!-- Pagination Bar -->
                <?php if ($total_pages > 1): ?>
                    <div class="pagination-bar">
                        <?php if ($page > 1): ?>
                            <a href="<?php echo $pagination_prefix; ?>?page=<?php echo $page - 1; ?><?php echo !empty($search) ? '&search='.urlencode($search) : ''; ?>" class="btn-pagination">← Previous</a>
                        <?php else: ?>
                            <div style="width: 100px;"></div> <!-- spacer -->
                        <?php endif; ?>
                        
                        <span style="font-size: 14px; color: var(--text-secondary);">Page <?php echo $page; ?> of <?php echo $total_pages; ?></span>
                        
                        <?php if ($page < $total_pages): ?>
                            <a href="<?php echo $pagination_prefix; ?>?page=<?php echo $page + 1; ?><?php echo !empty($search) ? '&search='.urlencode($search) : ''; ?>" class="btn-pagination">Next →</a>
                        <?php else: ?>
                            <div style="width: 100px;"></div> <!-- spacer -->
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        </div>

    <?php else: ?>
        <!-- ================= SINGLE ARTICLE VIEW ================= -->
        
        <div class="reader-layout">
            <!-- Left Side: Article Content -->
            <main>
                <article class="post-card">
                    <a href="./" class="back-home-link">← Back to Articles</a>
                    <header class="post-header">
                        <div class="post-meta-badges">
                            <span class="badge badge-date"><?php echo date('F d, Y @ H:i', strtotime($active_post['created_at'])); ?></span>

                            <?php if (!empty($active_post['categories'])): ?>
                                <?php foreach (explode(',', $active_post['categories']) as $cat): ?>
                                    <a href="category/<?php echo urlencode(trim($cat)); ?>/" class="badge badge-category" style="text-decoration: none;"><?php echo htmlspecialchars(trim($cat)); ?></a>
                                <?php endforeach; ?>
                            <?php endif; ?>

                            <?php if (!empty($active_post['tags'])): ?>
                                <?php foreach (explode(',', $active_post['tags']) as $tg): ?>
                                    <a href="tag/<?php echo urlencode(trim($tg)); ?>/" class="badge badge-tag" style="text-decoration: none;">#<?php echo htmlspecialchars(trim($tg)); ?></a>
                                <?php endforeach; ?>
                            <?php endif; ?>

                            <?php if ($is_admin): ?>
                                <a href="admin/index.php?action=edit&id=<?php echo $active_post['id']; ?>" class="badge" style="background: rgba(59,130,246,0.2); color: #60a5fa; border: 1px solid rgba(59,130,246,0.3); text-decoration: none; font-weight: 600;">✏️ Edit</a>
                            <?php endif; ?>
                        </div>
                        <h1 class="post-title" style="font-size: 32px; font-weight: 800; line-height: 1.2; color: #fff; margin-top: 12px; margin-bottom: 8px;"><?php echo htmlspecialchars($active_post['title']); ?></h1>
                    </header>
                    
                    <div class="post-body">
                        <?php echo $active_post['display_content']; ?>
                    </div>

                    <!-- Article Navigation -->
                    <?php if ($next_post || $prev_post): ?>
                        <div class="post-navigation">
                            <?php if ($next_post): ?>
                                    <a href="<?php echo htmlspecialchars($next_post['slug']); ?>/" class="nav-item nav-prev">
                                        <span class="nav-item-label">← Previous Article</span>
                                        <span class="nav-item-title"><?php echo htmlspecialchars($next_post['title']); ?></span>
                                    </a>
                                <?php else: ?>
                                    <div style="width: 50%;"></div>
                                <?php endif; ?>
                                
                                <?php if ($prev_post): ?>
                                    <a href="<?php echo htmlspecialchars($prev_post['slug']); ?>/" class="nav-item nav-next">
                                        <span class="nav-item-label">Next Article →</span>
                                        <span class="nav-item-title"><?php echo htmlspecialchars($prev_post['title']); ?></span>
                                    </a>
                            <?php else: ?>
                                <div style="width: 50%;"></div>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                </article>
            </main>

            <aside class="reader-sidebar">
                <!-- Search Widget -->
                <div class="sidebar-widget">
                    <h3 class="widget-title">Search</h3>
                    <form method="GET" action="./" class="search-form">
                        <input type="text" name="search" class="search-input" style="width: 100%;" placeholder="Search archive...">
                    </form>
                </div>

                <!-- Categories Widget -->
                <?php if (!empty($all_categories)): ?>
                    <div class="sidebar-widget">
                        <h3 class="widget-title">Categories</h3>
                        <div style="display: flex; flex-wrap: wrap; gap: 8px;">
                            <?php foreach ($all_categories as $cat): ?>
                                <a href="category/<?php echo urlencode($cat); ?>/" class="pill-filter" style="padding: 6px 12px; font-size: 12px;"><?php echo htmlspecialchars($cat); ?></a>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>

                <!-- Recent Posts Widget -->
                <?php 
                    // Fetch top 5 recent posts
                    if ($pdo) {
                        try {
                            $recent_stmt = $pdo->prepare("SELECT id, title, content, meta_image, slug FROM posts 
                                                          WHERE status = 'published' AND (publish_at IS NULL OR publish_at <= :now) 
                                                          AND id != :active_id
                                                          ORDER BY created_at DESC LIMIT 5");
                            $recent_stmt->execute([':now' => $now, ':active_id' => $active_post['id']]);
                            $recent_posts = $recent_stmt->fetchAll(PDO::FETCH_ASSOC);
                        } catch (PDOException $e) {}
                    }
                    if (!empty($recent_posts)):
                ?>
                    <div class="sidebar-widget">
                        <h3 class="widget-title">Recent Articles</h3>
                        <?php foreach ($recent_posts as $r_post): 
                            $r_thumb = get_post_thumbnail($r_post, $img_prefix);
                        ?>
                            <a href="<?php echo htmlspecialchars($r_post['slug']); ?>/" class="recent-post-link">
                                <div class="recent-thumbnail-container">
                                    <?php if (!empty($r_thumb)): ?>
                                        <img src="<?php echo htmlspecialchars($r_thumb); ?>" class="recent-thumbnail" alt="<?php echo htmlspecialchars($r_post['title']); ?>">
                                    <?php else: ?>
                                        <div style="width: 100%; height: 100%; background: linear-gradient(135deg, #1e1b4b, #311042);"></div>
                                    <?php endif; ?>
                                </div>
                                <div class="recent-post-title"><?php echo htmlspecialchars($r_post['title']); ?></div>
                            </a>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </aside>
        </div>

    <?php endif; ?>

    <!-- Footer -->
    <footer class="site-footer">
        <div class="footer-columns">
            <div class="footer-column footer-about">
                <h4><?php echo htmlspecialchars(SITE_NAME); ?></h4>
                <p><?php echo htmlspecialchars(SITE_DESCRIPTION); ?></p>
            </div>
            <div class="footer-column">
                <h4>Chess Network</h4>
                <ul>
                    <li><a href="https://catur.org/" target="_blank">Catur.org</a></li>
                    <li><a href="https://mcf.news/" target="_blank">MCF News</a></li>
                    <li><a href="https://gila.catur.org/" target="_blank">GilaCatur Blog</a></li>
                    <li><a href="https://gilachess.blogspot.com/" target="_blank">GilaChess Blogspot</a></li>
                </ul>
            </div>
            <div class="footer-column">
                <h4>Navigation</h4>
                <ul>
                    <li><a href="./">Home</a></li>
                    <li><a href="feed.php" target="_blank">RSS Feed</a></li>
                </ul>
            </div>
        </div>
        <div class="footer-bottom">
            &copy; <?php echo date('Y'); ?> <?php echo htmlspecialchars(SITE_NAME); ?>. Powered by Custom PHP SQLite CMS.
        </div>
    </footer>

</body>
</html>
