<?php
require_once __DIR__ . '/config.php';

header('Content-Type: application/rss+xml; charset=utf-8');

// Dynamically determine the base URL to handle local subdirectories and production domain roots
$protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https://' : 'http://';
$host = $_SERVER['HTTP_HOST'] ?? SITE_DOMAIN;

if (strpos($host, 'localhost') !== false || strpos($host, '127.0.0.1') !== false) {
    // Local development subfolder environment
    $base_url = $protocol . $host . '/' . basename(__DIR__) . '/';
} else {
    // Production domain root environment
    $base_url = $protocol . $host . '/';
}

$posts = [];

try {
    if (file_exists(DB_FILE)) {
        $pdo = new PDO("sqlite:" . DB_FILE);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        
        // Fetch published posts that are not scheduled in the future
        $now = date('Y-m-d H:i:s');
        $query = "SELECT * FROM posts 
                  WHERE status = 'published' 
                  AND (publish_at IS NULL OR publish_at <= :now) 
                  ORDER BY created_at DESC 
                  LIMIT 20";
                  
        $stmt = $pdo->prepare($query);
        $stmt->execute([':now' => $now]);
        $posts = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (Exception $e) {
    // Fail silently and return an empty feed structure
}

echo '<?xml version="1.0" encoding="UTF-8" ?>' . "\n";
?>
<rss version="2.0" xmlns:content="http://purl.org/rss/1.0/modules/content/">
<channel>
    <title><?php echo htmlspecialchars(SITE_NAME); ?></title>
    <link><?php echo htmlspecialchars($base_url); ?></link>
    <description><?php echo htmlspecialchars(SITE_DESCRIPTION); ?></description>
    <language>en-us</language>
    <lastBuildDate><?php echo date(DATE_RSS); ?></lastBuildDate>
    
    <?php foreach ($posts as $post): ?>
        <item>
            <title><?php echo htmlspecialchars($post['title']); ?></title>
            <link><?php echo htmlspecialchars($base_url . $post['slug']); ?>/</link>
            <guid isPermaLink="true"><?php echo htmlspecialchars($base_url . $post['slug']); ?>/</guid>
            <pubDate><?php echo date(DATE_RSS, strtotime($post['created_at'])); ?></pubDate>
            <description><![CDATA[<?php echo mb_strimwidth(strip_tags($post['content']), 0, 300, '...'); ?>]]></description>
            <content:encoded><![CDATA[<?php echo $post['content']; ?>]]></content:encoded>
        </item>
    <?php endforeach; ?>
</channel>
</rss>
