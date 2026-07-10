<?php
require_once __DIR__ . '/config.php';

// Function to generate the sitemap.xml file
function generate_static_sitemap() {
    $posts = [];
    $all_categories = [];
    $all_tags = [];

    // Dynamically determine the base URL
    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https://' : 'http://';
    $host = $_SERVER['HTTP_HOST'] ?? SITE_DOMAIN;
    if (strpos($host, 'localhost') !== false || strpos($host, '127.0.0.1') !== false) {
        $base_url = $protocol . $host . '/' . basename(__DIR__) . '/';
    } else {
        $base_url = $protocol . $host . '/';
    }

    try {
        if (file_exists(DB_FILE)) {
            $pdo = new PDO("sqlite:" . DB_FILE);
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            
            $now = date('Y-m-d H:i:s');
            $query = "SELECT slug, created_at, categories, tags FROM posts 
                      WHERE status = 'published' 
                      AND (publish_at IS NULL OR publish_at <= :now) 
                      ORDER BY created_at DESC";
                      
            $stmt = $pdo->prepare($query);
            $stmt->execute([':now' => $now]);
            $posts = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Gather categories & tags
            foreach ($posts as $post) {
                if (!empty($post['categories'])) {
                    foreach (explode(',', $post['categories']) as $c) {
                        $c = trim($c);
                        if (!empty($c)) $all_categories[$c] = true;
                    }
                }
                if (!empty($post['tags'])) {
                    foreach (explode(',', $post['tags']) as $t) {
                        $t = trim($t);
                        if (!empty($t)) $all_tags[$t] = true;
                    }
                }
            }
        }
    } catch (Exception $e) {
        // Fail silently
    }

    ob_start();
    echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
    ?>
<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">
    <!-- Homepage -->
    <url>
        <loc><?php echo htmlspecialchars($base_url); ?></loc>
        <changefreq>daily</changefreq>
        <priority>1.0</priority>
    </url>
    
    <!-- Posts -->
    <?php foreach ($posts as $post): ?>
        <url>
            <loc><?php echo htmlspecialchars($base_url . $post['slug'] . '/'); ?></loc>
            <lastmod><?php echo date('Y-m-d', strtotime($post['created_at'])); ?></lastmod>
            <changefreq>weekly</changefreq>
            <priority>0.8</priority>
        </url>
    <?php endforeach; ?>
    
    <!-- Categories -->
    <?php foreach (array_keys($all_categories) as $cat): ?>
        <url>
            <loc><?php echo htmlspecialchars($base_url . 'category/' . urlencode($cat) . '/'); ?></loc>
            <changefreq>weekly</changefreq>
            <priority>0.5</priority>
        </url>
    <?php endforeach; ?>
    
    <!-- Tags -->
    <?php foreach (array_keys($all_tags) as $tag): ?>
        <url>
            <loc><?php echo htmlspecialchars($base_url . 'tag/' . urlencode($tag) . '/'); ?></loc>
            <changefreq>weekly</changefreq>
            <priority>0.3</priority>
        </url>
    <?php endforeach; ?>
</urlset>
    <?php
    $xml = ob_get_clean();
    file_put_contents(__DIR__ . '/sitemap.xml', $xml);
    return $xml;
}

// Generate static sitemap and serve it if dynamically requested via browser or CLI
if (realpath(__FILE__) === realpath($_SERVER['SCRIPT_FILENAME'] ?? '')) {
    if (!headers_sent()) {
        header('Content-Type: application/xml; charset=utf-8');
    }
    echo generate_static_sitemap();
    exit();
}
