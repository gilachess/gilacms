<?php
// WordPress Custom PHP CMS Admin Panel
session_start();

// --- CONFIGURATION ---
require_once __DIR__ . '/../config.php';

// Ensure upload directory exists
if (!is_dir(UPLOAD_DIR)) {
    mkdir(UPLOAD_DIR, 0755, true);
}

// AJAX Image Upload API Endpoint
if (isset($_GET['api']) && $_GET['api'] === 'upload_image' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
        echo json_encode(['success' => false, 'error' => 'Unauthorized']);
        exit();
    }
    if (!isset($_FILES['upload_file'])) {
        echo json_encode(['success' => false, 'error' => 'No file uploaded']);
        exit();
    }
    $file = $_FILES['upload_file'];
    if ($file['error'] !== UPLOAD_ERR_OK) {
        echo json_encode(['success' => false, 'error' => 'Upload error: ' . $file['error']]);
        exit();
    }
    
    $filename = preg_replace('/[^a-zA-Z0-9\._-]/', '', basename($file['name']));
    $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    if (!in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'svg', 'webp'])) {
        echo json_encode(['success' => false, 'error' => 'Invalid file type. Only JPG, JPEG, PNG, GIF, SVG, and WEBP are allowed.']);
        exit();
    }
    
    $dest = UPLOAD_DIR . $filename;
    if (move_uploaded_file($file['tmp_name'], $dest)) {
        $host = $_SERVER['HTTP_HOST'] ?? 'gilachess.org';
        if (strpos($host, 'localhost') !== false || strpos($host, '127.0.0.1') !== false) {
            $web_path = '/gilachess.org/images/uploads/' . $filename;
        } else {
            $web_path = '/images/uploads/' . $filename;
        }
        echo json_encode(['success' => true, 'url' => $web_path]);
    } else {
        echo json_encode(['success' => false, 'error' => 'Failed to move uploaded file.']);
    }
    exit();
}

// --- DATABASE CONNECTION & AUTO-MIGRATIONS ---
$pdo = null;
try {
    $pdo = new PDO("sqlite:" . DB_FILE);
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
            // Column does not exist, add it
            $pdo->exec("ALTER TABLE posts ADD COLUMN $col $type");
        }
    }
} catch (Exception $e) {
    die("Database Error: " . $e->getMessage());
}

// --- AUTHENTICATION HANDLING ---
if (isset($_GET['logout'])) {
    unset($_SESSION['admin_logged_in']);
    header("Location: index.php");
    exit;
}

if (isset($_POST['login'])) {
    $password = isset($_POST['password']) ? $_POST['password'] : '';
    if ($password === ADMIN_PASSWORD) {
        $_SESSION['admin_logged_in'] = true;
        header("Location: index.php");
        exit;
    } else {
        $login_error = "Invalid password.";
    }
}

// Check login status
$logged_in = isset($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'] === true;

// --- ACTIONS & DATA PROCESSING (only if logged in) ---
$action = isset($_GET['action']) ? $_GET['action'] : 'list';
$message = '';
$error = '';

if ($logged_in) {
    // Delete Post Action
    if ($action === 'delete' && isset($_GET['id'])) {
        $id = (int)$_GET['id'];
        try {
            $stmt = $pdo->prepare("DELETE FROM posts WHERE id = :id");
            $stmt->execute([':id' => $id]);
            $message = "Post deleted successfully.";
            if (file_exists(__DIR__ . '/../sitemap.php')) {
                require_once __DIR__ . '/../sitemap.php';
                if (function_exists('generate_static_sitemap')) {
                    generate_static_sitemap();
                }
            }
            $action = 'list';
        } catch (PDOException $e) {
            $error = "Error deleting post: " . $e->getMessage();
        }
    }
    
    // Save Post (Insert/Update) Action
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_post'])) {
        $id = !empty($_POST['id']) ? (int)$_POST['id'] : null;
        $title = trim($_POST['title']);
        $slug = trim($_POST['slug']);
        $content = trim($_POST['content']);
        $status = trim($_POST['status']);
        $publish_at = !empty($_POST['publish_at']) ? date('Y-m-d H:i:s', strtotime($_POST['publish_at'])) : null;
        $categories = trim($_POST['categories']);
        $tags = trim($_POST['tags']);
        $meta_description = trim($_POST['meta_description']);
        $meta_image = trim($_POST['meta_image']);
        $created_at = !empty($_POST['created_at']) ? date('Y-m-d H:i:s', strtotime($_POST['created_at'])) : date('Y-m-d H:i:s');
        
        if (empty($title) || empty($slug)) {
            $error = "Title and Slug cannot be empty.";
        } else {
            try {
                if ($id) {
                    // Update
                    $sql = "UPDATE posts SET 
                                title = :title, 
                                slug = :slug, 
                                content = :content, 
                                status = :status, 
                                publish_at = :publish_at, 
                                categories = :categories, 
                                tags = :tags, 
                                meta_description = :meta_description, 
                                meta_image = :meta_image,
                                created_at = :created_at
                            WHERE id = :id";
                    $stmt = $pdo->prepare($sql);
                    $stmt->execute([
                        ':title'            => $title,
                        ':slug'             => $slug,
                        ':content'          => $content,
                        ':status'           => $status,
                        ':publish_at'       => $publish_at,
                        ':categories'       => $categories,
                        ':tags'             => $tags,
                        ':meta_description' => $meta_description,
                        ':meta_image'       => $meta_image,
                        ':created_at'       => $created_at,
                        ':id'               => $id
                    ]);
                    $message = "Post updated successfully.";
                } else {
                    // Insert - Find next available ID
                    $max_id_stmt = $pdo->query("SELECT MAX(id) FROM posts");
                    $next_id = (int)$max_id_stmt->fetchColumn() + 1;
                    
                    $sql = "INSERT INTO posts (id, title, slug, content, status, publish_at, categories, tags, meta_description, meta_image, created_at) 
                            VALUES (:id, :title, :slug, :content, :status, :publish_at, :categories, :tags, :meta_description, :meta_image, :created_at)";
                    $stmt = $pdo->prepare($sql);
                    $stmt->execute([
                        ':id'               => $next_id,
                        ':title'            => $title,
                        ':slug'             => $slug,
                        ':content'          => $content,
                        ':status'           => $status,
                        ':publish_at'       => $publish_at,
                        ':categories'       => $categories,
                        ':tags'             => $tags,
                        ':meta_description' => $meta_description,
                        ':meta_image'       => $meta_image,
                        ':created_at'       => $created_at
                    ]);
                    $message = "Post created successfully.";
                }
                if (file_exists(__DIR__ . '/../sitemap.php')) {
                    require_once __DIR__ . '/../sitemap.php';
                    if (function_exists('generate_static_sitemap')) {
                        generate_static_sitemap();
                    }
                }
                $action = 'list';
            } catch (PDOException $e) {
                $error = "Error saving post: " . $e->getMessage();
            }
        }
    }
    
    // Image Upload Action
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['upload_file'])) {
        $file = $_FILES['upload_file'];
        if ($file['error'] === UPLOAD_ERR_OK) {
            $filename = preg_replace('/[^a-zA-Z0-9\._-]/', '', basename($file['name']));
            $dest = UPLOAD_DIR . $filename;
            if (move_uploaded_file($file['tmp_name'], $dest)) {
                $message = "Image uploaded successfully.";
            } else {
                $error = "Failed to move uploaded file.";
            }
        } else {
            $error = "Upload error: " . $file['error'];
        }
        $action = 'images';
    }
    
    // Image Delete Action
    if ($action === 'delete_image' && isset($_GET['name'])) {
        $filename = basename($_GET['name']);
        $filepath = UPLOAD_DIR . $filename;
        if (file_exists($filepath)) {
            unlink($filepath);
            $message = "Image deleted successfully.";
        } else {
            $error = "File not found.";
        }
        $action = 'images';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>CMS Admin Panel - <?php echo htmlspecialchars(SITE_NAME); ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <!-- Modular Editor Support -->
    <?php if (CMS_EDITOR === 'tinymce'): ?>
        <script src="https://cdn.jsdelivr.net/npm/tinymce@6/tinymce.min.js" referrerpolicy="origin"></script>
    <?php elseif (CMS_EDITOR === 'markdown'): ?>
        <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/easymde/dist/easymde.min.css">
        <script src="https://cdn.jsdelivr.net/npm/easymde/dist/easymde.min.js"></script>
    <?php endif; ?>
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
            --success-color: #10b981;
            --error-color: #ef4444;
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
            background-image: 
                radial-gradient(at 0% 0%, rgba(99, 102, 241, 0.08) 0px, transparent 50%),
                radial-gradient(at 100% 100%, rgba(236, 72, 153, 0.04) 0px, transparent 50%);
        }

        a {
            color: var(--accent-color);
            text-decoration: none;
        }

        /* Navigation */
        .admin-nav {
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

        .admin-nav h1 {
            font-size: 18px;
            font-weight: 600;
            background: linear-gradient(135deg, #a78bfa, #6366f1);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }

        .nav-links {
            display: flex;
            gap: 20px;
            align-items: center;
        }

        .nav-link {
            font-size: 14px;
            font-weight: 500;
            color: var(--text-secondary);
            padding: 6px 12px;
            border-radius: 6px;
            transition: all 0.2s;
        }

        .nav-link:hover, .nav-link.active {
            color: #fff;
            background: rgba(255, 255, 255, 0.05);
        }

        .btn-logout {
            background: rgba(239, 68, 68, 0.15);
            color: #fca5a5;
        }

        /* Container */
        .admin-container {
            max-width: 1200px;
            margin: 40px auto;
            padding: 0 24px;
        }

        /* Messages */
        .alert {
            padding: 14px 20px;
            border-radius: 8px;
            margin-bottom: 24px;
            font-size: 14px;
            font-weight: 500;
        }

        .alert-success {
            background: rgba(16, 185, 129, 0.12);
            border: 1px solid rgba(16, 185, 129, 0.2);
            color: #34d399;
        }

        .alert-error {
            background: rgba(239, 68, 68, 0.12);
            border: 1px solid rgba(239, 68, 68, 0.2);
            color: #fca5a5;
        }

        /* Login Screen */
        .login-card {
            max-width: 400px;
            margin: 120px auto;
            background: var(--panel-bg);
            backdrop-filter: blur(16px);
            border: 1px solid var(--border-color);
            border-radius: 16px;
            padding: 32px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.3);
            text-align: center;
        }

        .login-card h2 {
            margin-bottom: 24px;
            font-size: 22px;
        }

        .form-group {
            margin-bottom: 20px;
            text-align: left;
        }

        .form-label {
            display: block;
            font-size: 13px;
            font-weight: 500;
            color: var(--text-secondary);
            margin-bottom: 8px;
        }

        .form-control {
            width: 100%;
            background: rgba(0, 0, 0, 0.3);
            border: 1px solid var(--border-color);
            padding: 10px 14px;
            border-radius: 8px;
            color: #fff;
            font-family: inherit;
            font-size: 14px;
            outline: none;
            transition: all 0.2s;
        }

        .form-control:focus {
            border-color: var(--accent-color);
            box-shadow: 0 0 8px var(--accent-glow);
        }

        .btn {
            background: var(--accent-color);
            color: #fff;
            border: none;
            padding: 10px 20px;
            border-radius: 8px;
            font-family: inherit;
            font-size: 14px;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.2s;
            display: inline-flex;
            align-items: center;
            justify-content: center;
        }

        .btn:hover {
            opacity: 0.9;
            transform: translateY(-1px);
        }

        .btn-secondary {
            background: rgba(255, 255, 255, 0.08);
            color: var(--text-primary);
        }

        .btn-danger {
            background: var(--error-color);
        }

        /* Post List Table */
        .table-card {
            background: var(--panel-bg);
            backdrop-filter: blur(12px);
            border: 1px solid var(--border-color);
            border-radius: 16px;
            overflow: hidden;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.2);
        }

        .table-header-row {
            padding: 20px 24px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            border-bottom: 1px solid var(--border-color);
        }

        .table-header-row h2 {
            font-size: 18px;
            font-weight: 600;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            text-align: left;
        }

        th, td {
            padding: 16px 24px;
            border-bottom: 1px solid var(--border-color);
            font-size: 14px;
        }

        th {
            background: rgba(255, 255, 255, 0.02);
            color: var(--text-secondary);
            font-weight: 500;
        }

        tr:last-child td {
            border-bottom: none;
        }

        tr:hover td {
            background: rgba(255, 255, 255, 0.01);
        }

        .badge-status {
            font-size: 11px;
            font-weight: 600;
            padding: 3px 8px;
            border-radius: 9999px;
            text-transform: uppercase;
        }

        .status-published { background: rgba(16, 185, 129, 0.15); color: #34d399; }
        .status-draft { background: rgba(156, 163, 175, 0.15); color: #d1d5db; }
        .status-scheduled { background: rgba(245, 158, 11, 0.15); color: #fbbf24; }

        .actions-cell {
            display: flex;
            gap: 12px;
        }

        .action-link {
            font-size: 13px;
            font-weight: 500;
        }

        .action-delete {
            color: #fca5a5;
        }

        /* Post Editor Grid */
        .editor-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 32px;
        }

        @media (max-width: 900px) {
            .editor-grid {
                grid-template-columns: 1fr;
            }
        }

        .editor-card {
            background: var(--panel-bg);
            backdrop-filter: blur(12px);
            border: 1px solid var(--border-color);
            border-radius: 16px;
            padding: 32px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.2);
        }

        .editor-title {
            font-size: 18px;
            font-weight: 600;
            margin-bottom: 24px;
            border-bottom: 1px solid var(--border-color);
            padding-bottom: 16px;
        }

        .checkbox-group {
            display: flex;
            align-items: center;
            gap: 8px;
            margin-top: 6px;
            font-size: 12px;
            color: var(--text-secondary);
        }

        .preview-pane {
            background: var(--card-bg);
            border: 1px solid var(--border-color);
            border-radius: 12px;
            padding: 24px;
            height: 600px;
            overflow-y: auto;
            color: #d1d5db;
        }

        .preview-pane img {
            max-width: 100%;
            height: auto;
            border-radius: 8px;
            margin: 16px 0;
        }

        /* Image Manager */
        .images-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
            gap: 20px;
            margin-top: 24px;
        }

        .image-card {
            background: var(--card-bg);
            border: 1px solid var(--border-color);
            border-radius: 12px;
            overflow: hidden;
            display: flex;
            flex-direction: column;
        }

        .image-thumbnail-container {
            height: 140px;
            background: #000;
            display: flex;
            align-items: center;
            justify-content: center;
            overflow: hidden;
            position: relative;
        }

        .image-thumbnail {
            max-width: 100%;
            max-height: 100%;
            object-fit: cover;
        }

        .image-details {
            padding: 12px;
            flex: 1;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
        }

        .image-name {
            font-size: 12px;
            font-weight: 500;
            margin-bottom: 8px;
            word-break: break-all;
            color: var(--text-primary);
        }

        .image-actions {
            display: flex;
            flex-direction: column;
            gap: 8px;
        }

        /* Quill overrides */
        .ql-toolbar.ql-snow {
            background: #f3f4f6 !important;
            border-color: rgba(0, 0, 0, 0.2) !important;
            border-radius: 8px 8px 0 0;
        }
        .ql-container.ql-snow {
            border-color: rgba(0, 0, 0, 0.2) !important;
            border-radius: 0 0 8px 8px;
            font-family: inherit !important;
            font-size: 15px !important;
            background: #ffffff !important;
        }
        .ql-editor {
            color: #111827 !important;
            min-height: 750px;
        }
        .ql-editor.ql-blank::before {
            color: #9ca3af !important;
            font-style: normal !important;
        }

        /* Modal Styles */
        .modal-overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(10, 15, 25, 0.85);
            backdrop-filter: blur(8px);
            z-index: 2000;
            display: flex;
            justify-content: center;
            align-items: center;
            opacity: 0;
            transition: opacity 0.3s ease;
            pointer-events: none;
        }
        .modal-overlay.active {
            opacity: 1;
            pointer-events: auto;
        }
        .modal-content {
            background: #111827;
            border: 1px solid var(--border-color);
            border-radius: 16px;
            width: 96%;
            max-width: 1600px;
            height: 94vh;
            max-height: 94vh;
            display: flex;
            flex-direction: column;
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.5);
            transform: scale(0.98);
            transition: transform 0.3s ease;
        }
        .modal-overlay.active .modal-content {
            transform: scale(1);
        }
        .modal-header {
            padding: 20px 24px;
            border-bottom: 1px solid var(--border-color);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .modal-header h2 {
            font-size: 20px;
            font-weight: 600;
            color: #fff;
        }
        .modal-close {
            font-size: 28px;
            color: var(--text-secondary);
            cursor: pointer;
            transition: color 0.2s;
            line-height: 1;
        }
        .modal-close:hover {
            color: #fff;
        }
        .modal-body {
            padding: 24px;
            overflow-y: auto;
            color: var(--text-primary);
            flex: 1;
        }
    </style>
</head>
<body>

    <?php if (!$logged_in): ?>
        <!-- Login Form -->
        <div class="login-card">
            <h2>CMS Administration</h2>
            <?php if (isset($login_error)): ?>
                <div class="alert alert-error"><?php echo htmlspecialchars($login_error); ?></div>
            <?php endif; ?>
            <form method="POST">
                <div class="form-group">
                    <label class="form-label" for="password">Admin Password</label>
                    <input type="password" id="password" name="password" class="form-control" placeholder="Enter password" required autofocus>
                </div>
                <button type="submit" name="login" class="btn" style="width: 100%;">Sign In</button>
            </form>
        </div>
    <?php else: ?>
        <!-- Admin Navigation -->
        <nav class="admin-nav">
            <h1><?php echo htmlspecialchars(SITE_NAME); ?> CMS Admin</h1>
            <div class="nav-links">
                <a href="index.php?action=list" class="nav-link <?php echo $action === 'list' ? 'active' : ''; ?>">Dashboard</a>
                <a href="index.php?action=create" class="nav-link <?php echo $action === 'create' || $action === 'edit' ? 'active' : ''; ?>">Write Post</a>
                <a href="index.php?action=images" class="nav-link <?php echo $action === 'images' ? 'active' : ''; ?>">Media Manager</a>
                <a href="../index.php" target="_blank" class="nav-link">View Blog</a>
                <a href="index.php?logout=true" class="nav-link btn-logout">Logout</a>
            </div>
        </nav>

        <div class="admin-container">
            <?php if (!empty($message)): ?>
                <div class="alert alert-success"><?php echo htmlspecialchars($message); ?></div>
            <?php endif; ?>
            <?php if (!empty($error)): ?>
                <div class="alert alert-error"><?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>

            <?php if ($action === 'list'): 
                // Fetch all posts
                $stmt = $pdo->query("SELECT * FROM posts ORDER BY created_at DESC");
                $all_posts = $stmt->fetchAll(PDO::FETCH_ASSOC);
            ?>
                <!-- Dashboard List -->
                <div class="table-card">
                    <div class="table-header-row">
                        <h2>All Blog Articles</h2>
                        <a href="index.php?action=create" class="btn">Write New Post</a>
                    </div>
                    <table>
                        <thead>
                            <tr>
                                <th style="width: 60px;">ID</th>
                                <th>Title</th>
                                <th>Slug</th>
                                <th>Category / Tags</th>
                                <th>Publish Date</th>
                                <th style="width: 120px;">Status</th>
                                <th style="width: 150px; text-align: center;">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($all_posts as $post): ?>
                                <tr>
                                    <td><?php echo $post['id']; ?></td>
                                    <td style="font-weight: 500; color: #fff;"><?php echo htmlspecialchars($post['title']); ?></td>
                                    <td>/<?php echo htmlspecialchars($post['slug']); ?></td>
                                    <td>
                                        <div style="font-size: 12px; color: var(--text-primary);"><?php echo htmlspecialchars($post['categories'] ?: '-'); ?></div>
                                        <div style="font-size: 11px; color: var(--text-secondary);"><?php echo htmlspecialchars($post['tags'] ?: ''); ?></div>
                                    </td>
                                    <td>
                                        <?php echo date('Y-m-d H:i', strtotime($post['created_at'])); ?>
                                        <?php if (!empty($post['publish_at'])): ?>
                                            <div style="font-size: 11px; color: var(--text-secondary);">Queue: <?php echo date('Y-m-d H:i', strtotime($post['publish_at'])); ?></div>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php 
                                            $now = date('Y-m-d H:i:s');
                                            $display_status = $post['status'];
                                            if ($post['status'] === 'published' && !empty($post['publish_at']) && $post['publish_at'] > $now) {
                                                $display_status = 'scheduled';
                                            }
                                        ?>
                                        <span class="badge-status status-<?php echo $display_status; ?>"><?php echo $display_status; ?></span>
                                    </td>
                                    <td class="actions-cell" style="justify-content: center;">
                                        <a href="index.php?action=edit&id=<?php echo $post['id']; ?>" class="action-link">Edit</a>
                                        <a href="index.php?action=delete&id=<?php echo $post['id']; ?>" class="action-link action-delete" onclick="return confirm('Are you sure you want to delete this post?');">Delete</a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

            <?php elseif ($action === 'create' || $action === 'edit'): 
                $editing = $action === 'edit' && isset($_GET['id']);
                $post_id = $editing ? (int)$_GET['id'] : null;
                $post = null;
                
                if ($editing) {
                    $stmt = $pdo->prepare("SELECT * FROM posts WHERE id = :id");
                    $stmt->execute([':id' => $post_id]);
                    $post = $stmt->fetch(PDO::FETCH_ASSOC);
                }
                
                $title_val = ($editing && $post['title'] !== null) ? $post['title'] : '';
                $slug_val = ($editing && $post['slug'] !== null) ? $post['slug'] : '';
                $content_val = ($editing && $post['content'] !== null) ? $post['content'] : '';
                $status_val = ($editing && $post['status'] !== null) ? $post['status'] : 'draft';
                $publish_at_val = ($editing && !empty($post['publish_at'])) ? date('Y-m-d\TH:i', strtotime($post['publish_at'])) : '';
                $categories_val = ($editing && $post['categories'] !== null) ? $post['categories'] : '';
                $tags_val = ($editing && $post['tags'] !== null) ? $post['tags'] : '';
                $meta_desc_val = ($editing && $post['meta_description'] !== null) ? $post['meta_description'] : '';
                $meta_img_val = ($editing && $post['meta_image'] !== null) ? $post['meta_image'] : '';
                $created_at_val = ($editing && !empty($post['created_at'])) ? date('Y-m-d\TH:i', strtotime($post['created_at'])) : date('Y-m-d\TH:i');
            ?>
                <!-- Write / Edit Form -->
                <div class="editor-single-column" style="max-width: 1200px; margin: 0 auto;">
                    <form method="POST" class="editor-card">
                        <div class="editor-title">
                            <h2><?php echo $editing ? 'Edit Post' : 'Create New Post'; ?></h2>
                        </div>
                        <input type="hidden" name="id" value="<?php echo $post_id; ?>">
                        
                        <div class="form-group">
                            <label class="form-label" for="title">Post Title</label>
                            <input type="text" name="title" id="title" class="form-control" value="<?php echo htmlspecialchars($title_val); ?>" required placeholder="e.g. Selangor Open 2026 Results">
                        </div>

                        <div class="form-group">
                            <label class="form-label" for="slug">URL Slug</label>
                            <input type="text" name="slug" id="slug" class="form-control" value="<?php echo htmlspecialchars($slug_val); ?>" required placeholder="e.g. selangor-open-2026-results">
                            <div class="checkbox-group">
                                <input type="checkbox" id="auto_slug" <?php echo $editing ? '' : 'checked'; ?>>
                                <label for="auto_slug">Auto-generate from title on type</label>
                            </div>
                        </div>

                        <div class="form-group">
                            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px;">
                                <label class="form-label" for="content" style="margin-bottom: 0;">Content</label>
                                <?php if (CMS_EDITOR === 'tinymce'): ?>
                                <div class="editor-tabs" style="display: flex; gap: 4px; background: rgba(255, 255, 255, 0.05); padding: 4px; border-radius: 8px; border: 1px solid var(--border-color);">
                                    <button type="button" id="tab-visual" class="tab-btn active" onclick="switchEditorMode('visual')" style="background: var(--accent-color); border: none; color: #fff; padding: 6px 12px; border-radius: 6px; font-size: 12px; font-weight: 500; cursor: pointer; transition: all 0.2s;">Visual Editor</button>
                                    <button type="button" id="tab-html" class="tab-btn" onclick="switchEditorMode('html')" style="background: transparent; border: none; color: var(--text-secondary); padding: 6px 12px; border-radius: 6px; font-size: 12px; font-weight: 500; cursor: pointer; transition: all 0.2s;">HTML Source</button>
                                </div>
                                <?php endif; ?>
                            </div>
                            <input type="hidden" name="content" id="content-input" value="<?php echo htmlspecialchars($content_val); ?>">
                            
                            <!-- AJAX Image Uploader Widget -->
                            <div class="ajax-uploader-box" style="margin-bottom: 12px; padding: 16px; background: rgba(255, 255, 255, 0.02); border: 1px dashed var(--border-color); border-radius: 8px;">
                                <div style="display: flex; align-items: center; justify-content: space-between; gap: 12px; flex-wrap: wrap;">
                                    <div style="display: flex; align-items: center; gap: 8px;">
                                        <span style="font-size: 13px; font-weight: 500; color: var(--text-secondary);">Upload image to posts:</span>
                                        <input type="file" id="ajax-upload-input" accept="image/*" style="font-size: 13px; color: var(--text-secondary);">
                                    </div>
                                    <button type="button" onclick="uploadEditorImage()" class="btn" style="padding: 6px 16px; font-size: 12px; background: var(--accent-color); font-weight: 500; border: none; box-shadow: none;">Upload File</button>
                                </div>
                                <div id="ajax-upload-result" style="display: none; margin-top: 12px; align-items: center; gap: 8px; background: rgba(16, 185, 129, 0.1); border: 1px solid rgba(16, 185, 129, 0.2); padding: 8px 12px; border-radius: 6px;">
                                    <span style="color: #10b981; font-size: 13px; font-weight: 500;">✓ Uploaded:</span>
                                    <input type="text" id="ajax-upload-url" readonly style="flex: 1; background: rgba(0, 0, 0, 0.2); border: 1px solid var(--border-color); color: #fff; padding: 6px 10px; border-radius: 4px; font-family: monospace; font-size: 12px;">
                                    <button type="button" onclick="copyUploadUrl()" class="btn" style="padding: 4px 12px; font-size: 11px; background: #10b981; border: none; box-shadow: none; font-weight: 500;">Copy Link</button>
                                </div>
                                <div id="ajax-upload-error" style="display: none; margin-top: 12px; color: #ef4444; font-size: 13px; font-weight: 500;"></div>
                            </div>
                            
                             <!-- HTML Editor Textarea (also used by TinyMCE oxide-dark) -->
                             <textarea id="html-editor" class="form-control" style="height: 750px; font-family: monospace; font-size: 14px; background: #1f2937; border: 1px solid var(--border-color); border-radius: 8px; color: #fff; resize: vertical; padding: 16px; line-height: 1.5;"><?php echo htmlspecialchars($content_val); ?></textarea>
                        </div>

                        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px;">
                            <div class="form-group">
                                <label class="form-label" for="status">Status</label>
                                <select name="status" id="status" class="form-control" style="background: #000;">
                                    <option value="published" <?php echo $status_val === 'published' ? 'selected' : ''; ?>>Published</option>
                                    <option value="draft" <?php echo $status_val === 'draft' ? 'selected' : ''; ?>>Draft</option>
                                </select>
                            </div>
                            
                            <div class="form-group">
                                <label class="form-label" for="publish_at">Publish At (For Scheduled Posts)</label>
                                <input type="datetime-local" name="publish_at" id="publish_at" class="form-control" value="<?php echo $publish_at_val; ?>">
                            </div>
                        </div>

                        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px;">
                            <div class="form-group">
                                <label class="form-label" for="categories">Categories</label>
                                <input type="text" name="categories" id="categories" class="form-control" value="<?php echo htmlspecialchars($categories_val); ?>" placeholder="Tournament Report, Announcement">
                            </div>
                            
                            <div class="form-group">
                                <label class="form-label" for="tags">Tags</label>
                                <input type="text" name="tags" id="tags" class="form-control" value="<?php echo htmlspecialchars($tags_val); ?>" placeholder="rapid, rating, junior">
                            </div>
                        </div>

                        <div class="form-group">
                            <label class="form-label" for="created_at">Post Creation Date Override</label>
                            <input type="datetime-local" name="created_at" id="created_at" class="form-control" value="<?php echo $created_at_val; ?>">
                        </div>

                        <div class="editor-title" style="margin-top: 32px; border-bottom: 1px solid var(--border-color); padding-bottom: 8px;">
                            <h3>SEO Meta Fields</h3>
                        </div>

                        <div class="form-group">
                            <label class="form-label" for="meta_description">Meta Description (for search results & social share)</label>
                            <textarea name="meta_description" id="meta_description" class="form-control" style="height: 70px;" placeholder="Brief summary of the article..."><?php echo htmlspecialchars($meta_desc_val); ?></textarea>
                        </div>

                        <div class="form-group">
                            <label class="form-label" for="meta_image">Open Graph Image (Social Thumbnail URL)</label>
                            <input type="text" name="meta_image" id="meta_image" class="form-control" value="<?php echo htmlspecialchars($meta_img_val); ?>" placeholder="e.g. images/uploads/thumbnail.png">
                        </div>

                        <div style="display: flex; gap: 16px; margin-top: 24px;">
                            <button type="submit" name="save_post" class="btn">Save Post</button>
                            <button type="button" class="btn btn-secondary" onclick="openPreviewModal()">Preview Post</button>
                            <a href="index.php?action=list" class="btn btn-secondary">Cancel</a>
                        </div>
                    </form>

                </div>

                <script>
                    const titleInput = document.getElementById('title');
                    const slugInput = document.getElementById('slug');
                    const autoSlugCheckbox = document.getElementById('auto_slug');
                    const contentInput = document.getElementById('content-input');
                    const htmlEditor = document.getElementById('html-editor');
                    const tabVisual = document.getElementById('tab-visual');
                    const tabHtml = document.getElementById('tab-html');

                    let currentMode = 'visual';
                    let embeddedBlocks = [];

                    // Extract script/style blocks to protect them
                    function extractBlocks(html) {
                        embeddedBlocks = [];
                        let index = 0;
                        const pattern = /<(script|style)([^>]*)>([\s\S]*?)<\/\1>/gi;
                        
                        return html.replace(pattern, function(match, type, attrs, content) {
                            embeddedBlocks.push({
                                type: type.toLowerCase(),
                                attrs: attrs,
                                content: content
                            });
                            const idx = index++;
                            return `<span class="html-embed-placeholder" data-index="${idx}" contenteditable="false" style="display: block; background: rgba(99, 102, 241, 0.155); border: 1px dashed #6366f1; padding: 16px; border-radius: 8px; margin: 12px 0; text-align: center; color: #4f46e5; font-weight: 600; font-family: sans-serif; font-size: 13px;">[Embedded ${type.toUpperCase()} Block - Kept Safe from WYSIWYG Editor]</span>`;
                        });
                    }

                    // Restore script/style blocks back into HTML
                    function restoreBlocks(html) {
                        const pattern = /<span class="html-embed-placeholder" data-index="(\d+)"[^>]*>[\s\S]*?<\/span>/gi;
                        return html.replace(pattern, function(match, idx) {
                            const block = embeddedBlocks[parseInt(idx)];
                            if (block) {
                                return `<${block.type}${block.attrs}>${block.content}</${block.type}>`;
                            }
                            return '';
                        });
                    }

                    // Auto-slug generation
                    titleInput.addEventListener('input', function() {
                        if (autoSlugCheckbox.checked) {
                            slugInput.value = titleInput.value
                                .toLowerCase()
                                .replace(/[^a-z0-9]+/g, '-')
                                .replace(/(^-|-$)+/g, '');
                        }
                    });

                    // Setup initial contents
                    const rawInitialContent = contentInput.value;
                    htmlEditor.value = rawInitialContent;

                    <?php if (CMS_EDITOR === 'tinymce'): ?>
                    const cleanedInitial = extractBlocks(rawInitialContent);
                    htmlEditor.value = cleanedInitial;

                    // Initialize TinyMCE
                    tinymce.init({
                        selector: '#html-editor',
                        height: 750,
                        skin: 'oxide-dark',
                        content_css: 'dark',
                        menubar: false,
                        branding: false,
                        promotion: false,
                        plugins: [
                            'accordion', 'advlist', 'anchor', 'autolink', 'autoresize', 'autosave',
                            'charmap', 'code', 'codesample', 'directionality', 'emoticons', 'fullscreen',
                            'help', 'image', 'importcss', 'insertdatetime', 'link', 'lists', 'media',
                            'nonbreaking', 'pagebreak', 'preview', 'quickbars', 'save', 'searchreplace',
                            'table', 'visualblocks', 'visualchars', 'wordcount',
                            'tableofcontents', 'footnotes', 'importword'
                        ],
                        toolbar: 'undo redo | blocks | bold italic underline strike | link image media table | footnotes tableofcontents importword | fullscreen code preview help',
                        setup: function(editor) {
                            editor.on('change keyup input text-change textInput', function() {
                                if (currentMode === 'visual') {
                                    syncContent();
                                }
                            });
                        }
                    });

                    // Sync function to retrieve latest HTML
                    function syncContent() {
                        if (currentMode === 'visual') {
                            const editor = tinymce.get('html-editor');
                            if (editor) {
                                let visualBody = editor.getContent();
                                let body = restoreBlocks(visualBody);
                                contentInput.value = body;
                            }
                        } else {
                            contentInput.value = htmlEditor.value;
                        }
                    }

                    // Mode Switcher
                    function switchEditorMode(mode) {
                        if (mode === currentMode) return;
                        
                        const editor = tinymce.get('html-editor');
                        if (mode === 'html') {
                            syncContent();
                            if (editor) {
                                editor.hide();
                            }
                            htmlEditor.value = contentInput.value;
                            
                            tabVisual.style.background = 'transparent';
                            tabVisual.style.color = 'var(--text-secondary)';
                            tabHtml.style.background = 'var(--accent-color)';
                            tabHtml.style.color = '#fff';
                        } else {
                            contentInput.value = htmlEditor.value;
                            const cleaned = extractBlocks(htmlEditor.value);
                            htmlEditor.value = cleaned;
                            if (editor) {
                                editor.show();
                                editor.setContent(cleaned);
                            }
                            
                            tabVisual.style.background = 'var(--accent-color)';
                            tabVisual.style.color = '#fff';
                            tabHtml.style.background = 'transparent';
                            tabHtml.style.color = 'var(--text-secondary)';
                        }
                        currentMode = mode;
                    }

                    htmlEditor.addEventListener('input', function() {
                        if (currentMode === 'html') {
                            contentInput.value = htmlEditor.value;
                        }
                    });

                    // Sync final inputs on form submission
                    document.querySelector('form').addEventListener('submit', function() {
                        syncContent();
                    });
                    <?php elseif (CMS_EDITOR === 'markdown'): ?>
                    // Initialize EasyMDE for Markdown editor
                    const easyMDE = new EasyMDE({
                        element: htmlEditor,
                        autoDownloadFontAwesome: true,
                        spellChecker: false,
                        minHeight: "500px",
                        renderingConfig: {
                            singleLineBreaks: false
                        }
                    });
                    
                    // Sync initial value and live updates to the hidden input field
                    contentInput.value = easyMDE.value();
                    easyMDE.codemirror.on("change", () => {
                        contentInput.value = easyMDE.value();
                    });
                    
                    document.querySelector('form').addEventListener('submit', function() {
                        contentInput.value = easyMDE.value();
                    });
                    <?php else: ?>
                    // Simple HTML/Plain text Editor (Textarea fallback)
                    htmlEditor.addEventListener('input', function() {
                        contentInput.value = htmlEditor.value;
                    });
                    
                    document.querySelector('form').addEventListener('submit', function() {
                        contentInput.value = htmlEditor.value;
                    });
                    <?php endif; ?>

                    // AJAX Image Upload helper
                    window.uploadEditorImage = function() {
                        const fileInput = document.getElementById('ajax-upload-input');
                        const resultBox = document.getElementById('ajax-upload-result');
                        const errorBox = document.getElementById('ajax-upload-error');
                        const urlInput = document.getElementById('ajax-upload-url');
                        
                        resultBox.style.display = 'none';
                        errorBox.style.display = 'none';
                        
                        if (fileInput.files.length === 0) {
                            errorBox.textContent = 'Please select a file first.';
                            errorBox.style.display = 'block';
                            return;
                        }
                        
                        const file = fileInput.files[0];
                        const formData = new FormData();
                        formData.append('upload_file', file);
                        
                        fetch('index.php?api=upload_image', {
                            method: 'POST',
                            body: formData
                        })
                        .then(response => response.json())
                        .then(data => {
                            if (data.success) {
                                urlInput.value = data.url;
                                resultBox.style.display = 'flex';
                                // Clear file input
                                fileInput.value = '';
                            } else {
                                errorBox.textContent = data.error || 'Upload failed.';
                                errorBox.style.display = 'block';
                            }
                        })
                        .catch(err => {
                            errorBox.textContent = 'Network error occurred during upload.';
                            errorBox.style.display = 'block';
                            console.error(err);
                        });
                    };

                    // Copy Upload URL to Clipboard
                    window.copyUploadUrl = function() {
                        const urlInput = document.getElementById('ajax-upload-url');
                        urlInput.select();
                        urlInput.setSelectionRange(0, 99999); // For mobile devices
                        
                        try {
                            navigator.clipboard.writeText(urlInput.value).then(() => {
                                const copyBtn = document.querySelector('#ajax-upload-result button');
                                const originalText = copyBtn.textContent;
                                copyBtn.textContent = 'Copied! ✓';
                                copyBtn.style.background = '#059669';
                                setTimeout(() => {
                                    copyBtn.textContent = originalText;
                                    copyBtn.style.background = '#10b981';
                                }, 1500);
                            });
                        } catch (err) {
                            // Fallback if clipboard API is not available
                            document.execCommand('copy');
                            alert('Copied link: ' + urlInput.value);
                        }
                    };

                    // Preview Modal controls
                    function openPreviewModal() {
                        syncContent();
                        const modal = document.getElementById('preview-modal');
                        const modalBody = document.getElementById('modal-preview-body');
                        
                        let rawHTML = contentInput.value;
                        let cleanHTML = rawHTML.replace(/\/images\//g, 'images/');
                        
                        modalBody.innerHTML = `
                            <h1 style="font-size: 28px; font-weight: 700; color: #fff; margin-bottom: 20px;">${titleInput.value || 'Untitled Post'}</h1>
                            <div class="post-preview-body" style="line-height: 1.7;">
                                ${cleanHTML || '<p style="color: var(--text-secondary);">No content written yet...</p>'}
                            </div>
                        `;
                        
                        modal.classList.add('active');
                        document.body.style.overflow = 'hidden';
                    }

                    function closePreviewModal() {
                        const modal = document.getElementById('preview-modal');
                        modal.classList.remove('active');
                        document.body.style.overflow = '';
                    }

                    document.getElementById('preview-modal').addEventListener('click', function(e) {
                        if (e.target === this) {
                            closePreviewModal();
                        }
                    });
                </script>

            <?php elseif ($action === 'images'): 
                // Scan images directory for uploads
                $images = glob(UPLOAD_DIR . "*.{jpg,jpeg,png,gif,svg,webp}", GLOB_BRACE);
            ?>
                <!-- Media Manager -->
                <div class="table-card" style="padding: 32px;">
                    <div class="editor-title" style="display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid var(--border-color); padding-bottom: 16px;">
                        <h2>Uploaded Media Manager</h2>
                        <form method="POST" enctype="multipart/form-data" style="display: flex; gap: 12px; align-items: center;">
                            <input type="file" name="upload_file" accept="image/*" required style="font-size: 13px;">
                            <button type="submit" class="btn">Upload Image</button>
                        </form>
                    </div>

                    <div class="images-grid">
                        <?php if (empty($images)): ?>
                            <div style="grid-column: 1 / -1; text-align: center; padding: 48px; color: var(--text-secondary);">
                                No uploaded images found. Upload files using the form above.
                            </div>
                        <?php else: ?>
                            <?php foreach ($images as $img): 
                                $basename = basename($img);
                                $local_url = "images/uploads/" . $basename;
                                $db_url = "/images/uploads/" . $basename; // URL structure to store in DB
                            ?>
                                <div class="image-card">
                                    <div class="image-thumbnail-container">
                                        <img src="<?php echo $local_url; ?>" class="image-thumbnail" alt="thumbnail">
                                    </div>
                                    <div class="image-details">
                                        <div class="image-name" title="<?php echo htmlspecialchars($basename); ?>">
                                            <?php echo htmlspecialchars(mb_strimwidth($basename, 0, 25, '...')); ?>
                                        </div>
                                        <div class="image-actions">
                                            <button class="btn btn-secondary btn-copy" style="font-size: 11px; padding: 6px;" data-url="<?php echo htmlspecialchars($db_url); ?>">Copy DB URL</button>
                                            <a href="index.php?action=delete_image&name=<?php echo urlencode($basename); ?>" class="btn btn-danger" style="font-size: 11px; padding: 6px; text-align: center;" onclick="return confirm('Are you sure you want to delete this image?');">Delete</a>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>

                <script>
                    // Copy URL helper
                    document.querySelectorAll('.btn-copy').forEach(button => {
                        button.addEventListener('click', function() {
                            const url = this.getAttribute('data-url');
                            navigator.clipboard.writeText(url).then(() => {
                                const oldText = this.innerText;
                                this.innerText = 'Copied!';
                                this.style.background = 'rgba(16, 185, 129, 0.2)';
                                this.style.color = '#34d399';
                                setTimeout(() => {
                                    this.innerText = oldText;
                                    this.style.background = '';
                                    this.style.color = '';
                                }, 1500);
                            });
                        });
                    });
                </script>

            <?php endif; ?>
        </div>
    <?php endif; ?>

    <!-- Post Preview Modal -->
    <div id="preview-modal" class="modal-overlay">
        <div class="modal-content">
            <div class="modal-header">
                <h2>Post Preview</h2>
                <span class="modal-close" onclick="closePreviewModal()">&times;</span>
            </div>
            <div class="modal-body" id="modal-preview-body"></div>
        </div>
    </div>

</body>
</html>
