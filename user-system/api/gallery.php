<?php
require_once 'config.php';

header('Content-Type: application/json; charset=utf-8');

// 获取请求方法
$method = $_SERVER['REQUEST_METHOD'];
$path = $_GET['path'] ?? '';

// 路由处理
switch ($method) {
    case 'GET':
        if ($path === '') {
            handleGetGalleryItems();
        } elseif (preg_match('/^(\d+)$/', $path, $matches)) {
            handleGetGalleryItem($matches[1]);
        } elseif ($path === 'categories') {
            handleGetCategories();
        } elseif ($path === 'statistics') {
            handleGetGalleryStatistics();
        } else {
            jsonResponse(false, '无效的API路径');
        }
        break;
        
    case 'POST':
        if ($path === '') {
            handleCreateGalleryItem();
        } else {
            jsonResponse(false, '无效的API路径');
        }
        break;
        
    case 'PUT':
        if (preg_match('/^(\d+)$/', $path, $matches)) {
            handleUpdateGalleryItem($matches[1]);
        } elseif (preg_match('/^(\d+)\/like$/', $path, $matches)) {
            handleLikeGalleryItem($matches[1]);
        } else {
            jsonResponse(false, '无效的API路径');
        }
        break;
        
    case 'DELETE':
        if (preg_match('/^(\d+)$/', $path, $matches)) {
            handleDeleteGalleryItem($matches[1]);
        } else {
            jsonResponse(false, '无效的API路径');
        }
        break;
        
    default:
        jsonResponse(false, '不支持的请求方法');
        break;
}

/**
 * 获取作品列表
 */
function handleGetGalleryItems() {
    $page = max(1, intval($_GET['page'] ?? 1));
    $limit = max(1, min(100, intval($_GET['limit'] ?? 20)));
    $offset = ($page - 1) * $limit;
    
    $category = trim($_GET['category'] ?? '');
    $userId = intval($_GET['user_id'] ?? 0);
    $search = trim($_GET['search'] ?? '');
    $orderBy = $_GET['orderBy'] ?? 'created_at';
    $order = strtoupper($_GET['order'] ?? 'desc');
    
    // 验证排序字段
    $allowedOrderFields = ['id', 'title', 'created_at', 'view_count', 'like_count', 'download_count'];
    if (!in_array($orderBy, $allowedOrderFields)) {
        $orderBy = 'created_at';
    }
    
    if ($order !== 'ASC' && $order !== 'DESC') {
        $order = 'DESC';
    }
    
    try {
        $pdo = getDB();
        $currentUserId = getCurrentUserId();
        $isAdmin = isAdmin();
        
        // 构建查询条件
        $whereConditions = ['1=1'];
        $params = [];
        
        // 权限控制：普通用户只能看到公开作品或自己的作品
        if (!$isAdmin) {
            $whereConditions[] = '(gi.is_public = TRUE OR gi.user_id = ?)';
            $params[] = $currentUserId;
        }
        
        if ($category) {
            $whereConditions[] = 'gi.category = ?';
            $params[] = $category;
        }
        
        if ($userId > 0) {
            $whereConditions[] = 'gi.user_id = ?';
            $params[] = $userId;
        }
        
        if ($search) {
            $whereConditions[] = '(gi.title LIKE ? OR gi.description LIKE ?)';
            $searchTerm = "%{$search}%";
            $params[] = $searchTerm;
            $params[] = $searchTerm;
        }
        
        $whereClause = implode(' AND ', $whereConditions);
        
        // 获取总数
        $countSql = "
            SELECT COUNT(*) as total
            FROM gallery_items gi
            WHERE $whereClause
        ";
        
        $countStmt = $pdo->prepare($countSql);
        $countStmt->execute($params);
        $totalCount = $countStmt->fetch()['total'];
        
        // 获取作品列表
        $sql = "
            SELECT 
                gi.id, gi.user_id, gi.title, gi.description, gi.image_url, 
                gi.thumbnail_url, gi.category, gi.tags, gi.is_public,
                gi.view_count, gi.like_count, gi.download_count, gi.file_size,
                gi.mime_type, gi.created_at, gi.updated_at,
                u.username, u.display_name, u.avatar_url as user_avatar,
                (SELECT COUNT(*) FROM gallery_comments gc WHERE gc.gallery_id = gi.id AND gc.is_public = TRUE) as comment_count,
                (SELECT COUNT(*) FROM gallery_likes gl WHERE gl.gallery_id = gi.id AND gl.user_id = ?) as is_liked
            FROM gallery_items gi
            LEFT JOIN users u ON gi.user_id = u.id
            WHERE $whereClause
            ORDER BY gi.{$orderBy} {$order}
            LIMIT ? OFFSET ?
        ";
        
        $params[] = $currentUserId;
        $params[] = $limit;
        $params[] = $offset;
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $items = $stmt->fetchAll();
        
        // 格式化作品数据
        $formattedItems = array_map(function($item) {
            $tags = $item['tags'] ? json_decode($item['tags'], true) : [];
            
            return [
                'id' => (int)$item['id'],
                'user' => [
                    'id' => (int)$item['user_id'],
                    'username' => $item['username'],
                    'display_name' => $item['display_name'],
                    'avatar_url' => $item['user_avatar']
                ],
                'title' => $item['title'],
                'description' => $item['description'],
                'image_url' => $item['image_url'],
                'thumbnail_url' => $item['thumbnail_url'],
                'category' => $item['category'],
                'tags' => $tags,
                'is_public' => (bool)$item['is_public'],
                'statistics' => [
                    'view_count' => (int)$item['view_count'],
                    'like_count' => (int)$item['like_count'],
                    'download_count' => (int)$item['download_count'],
                    'comment_count' => (int)$item['comment_count']
                ],
                'file' => [
                    'size' => (int)$item['file_size'],
                    'formatted_size' => formatFileSize($item['file_size']),
                    'mime_type' => $item['mime_type']
                ],
                'interaction' => [
                    'is_liked' => (bool)$item['is_liked']
                ],
                'dates' => [
                    'created_at' => $item['created_at'],
                    'updated_at' => $item['updated_at']
                ]
            ];
        }, $items);
        
        // 记录操作日志
        logOperation($currentUserId, 'view_gallery', 'gallery', null, [
            'page' => $page,
            'limit' => $limit,
            'category' => $category,
            'user_id' => $userId,
            'search' => $search,
            'total_count' => $totalCount
        ]);
        
        jsonResponse(true, '获取作品列表成功', [
            'items' => $formattedItems,
            'pagination' => [
                'page' => $page,
                'limit' => $limit,
                'total' => $totalCount,
                'pages' => ceil($totalCount / $limit)
            ]
        ]);
        
    } catch (Exception $e) {
        jsonResponse(false, '获取作品列表失败: ' . $e->getMessage());
    }
}

/**
 * 获取作品详情
 */
function handleGetGalleryItem($itemId) {
    try {
        $pdo = getDB();
        $currentUserId = getCurrentUserId();
        $isAdmin = isAdmin();
        
        // 获取作品详情
        $sql = "
            SELECT 
                gi.*,
                u.username, u.display_name, u.avatar_url as user_avatar,
                (SELECT COUNT(*) FROM gallery_likes gl WHERE gl.gallery_id = gi.id AND gl.user_id = ?) as is_liked
            FROM gallery_items gi
            LEFT JOIN users u ON gi.user_id = u.id
            WHERE gi.id = ?
        ";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$currentUserId, $itemId]);
        $item = $stmt->fetch();
        
        if (!$item) {
            jsonResponse(false, '作品不存在', null, 404);
        }
        
        // 权限检查：普通用户只能查看公开作品或自己的作品
        if (!$isAdmin && $item['user_id'] != $currentUserId && !$item['is_public']) {
            jsonResponse(false, '没有权限查看该作品', null, 403);
        }
        
        // 更新浏览次数
        $updateStmt = $pdo->prepare("
            UPDATE gallery_items 
            SET view_count = view_count + 1 
            WHERE id = ?
        ");
        $updateStmt->execute([$itemId]);
        
        // 获取评论
        $commentsStmt = $pdo->prepare("
            SELECT 
                gc.*,
                u.username, u.display_name, u.avatar_url as user_avatar
            FROM gallery_comments gc
            LEFT JOIN users u ON gc.user_id = u.id
            WHERE gc.gallery_id = ? AND gc.is_public = TRUE
            ORDER BY gc.created_at DESC
            LIMIT 50
        ");
        
        $commentsStmt->execute([$itemId]);
        $comments = $commentsStmt->fetchAll();
        
        // 格式化评论数据
        $formattedComments = array_map(function($comment) {
            return [
                'id' => (int)$comment['id'],
                'user' => [
                    'id' => (int)$comment['user_id'],
                    'username' => $comment['username'],
                    'display_name' => $comment['display_name'],
                    'avatar_url' => $comment['user_avatar']
                ],
                'content' => $comment['content'],
                'is_public' => (bool)$comment['is_public'],
                'parent_id' => $comment['parent_id'] ? (int)$comment['parent_id'] : null,
                'dates' => [
                    'created_at' => $comment['created_at'],
                    'updated_at' => $comment['updated_at']
                ]
            ];
        }, $comments);
        
        $tags = $item['tags'] ? json_decode($item['tags'], true) : [];
        
        $itemData = [
            'id' => (int)$item['id'],
            'user' => [
                'id' => (int)$item['user_id'],
                'username' => $item['username'],
                'display_name' => $item['display_name'],
                'avatar_url' => $item['user_avatar']
            ],
            'title' => $item['title'],
            'description' => $item['description'],
            'image_url' => $item['image_url'],
            'thumbnail_url' => $item['thumbnail_url'],
            'category' => $item['category'],
            'tags' => $tags,
            'is_public' => (bool)$item['is_public'],
            'statistics' => [
                'view_count' => (int)$item['view_count'] + 1, // 包含本次浏览
                'like_count' => (int)$item['like_count'],
                'download_count' => (int)$item['download_count']
            ],
            'file' => [
                'size' => (int)$item['file_size'],
                'formatted_size' => formatFileSize($item['file_size']),
                'mime_type' => $item['mime_type']
            ],
            'interaction' => [
                'is_liked' => (bool)$item['is_liked']
            ],
            'comments' => $formattedComments,
            'dates' => [
                'created_at' => $item['created_at'],
                'updated_at' => $item['updated_at']
            ]
        ];
        
        // 记录操作日志
        logOperation($currentUserId, 'view_gallery_item', 'gallery_item', $itemId, [
            'item_id' => $itemId,
            'title' => $item['title'],
            'user_id' => $item['user_id']
        ]);
        
        jsonResponse(true, '获取作品详情成功', $itemData);
        
    } catch (Exception $e) {
        jsonResponse(false, '获取作品详情失败: ' . $e->getMessage());
    }
}

/**
 * 创建作品
 */
function handleCreateGalleryItem() {
    $currentUserId = getCurrentUserId();
    
    // 检查是否有文件上传
    if (!isset($_FILES['image']) || $_FILES['image']['error'] !== UPLOAD_ERR_OK) {
        jsonResponse(false, '请选择要上传的图片');
    }
    
    $file = $_FILES['image'];
    $title = trim($_POST['title'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $category = trim($_POST['category'] ?? '');
    $tags = $_POST['tags'] ?? '';
    $isPublic = isset($_POST['is_public']) ? filter_var($_POST['is_public'], FILTER_VALIDATE_BOOLEAN) : true;
    
    // 验证输入
    if (empty($title)) {
        jsonResponse(false, '作品标题不能为空');
    }
    
    if (strlen($title) > 200) {
        jsonResponse(false, '作品标题不能超过200个字符');
    }
    
    if (strlen($description) > 2000) {
        jsonResponse(false, '作品描述不能超过2000个字符');
    }
    
    if ($category && strlen($category) > 50) {
        jsonResponse(false, '分类不能超过50个字符');
    }
    
    // 验证文件
    if ($file['size'] > MAX_FILE_SIZE) {
        jsonResponse(false, '文件大小不能超过2GB');
    }
    
    $fileType = mime_content_type($file['tmp_name']);
    if (!in_array($fileType, ALLOWED_IMAGE_TYPES)) {
        jsonResponse(false, '只允许上传图片文件 (JPEG, PNG, GIF, WebP)');
    }
    
    try {
        $pdo = getDB();
        
        // 检查用户存储空间
        $checkStmt = $pdo->prepare("
            SELECT storage_limit, used_storage 
            FROM users 
            WHERE id = ?
        ");
        
        $checkStmt->execute([$currentUserId]);
        $user = $checkStmt->fetch();
        
        if (!$user) {
            jsonResponse(false, '用户不存在');
        }
        
        $freeStorage = $user['storage_limit'] - $user['used_storage'];
        if ($freeStorage < $file['size']) {
            jsonResponse(false, '存储空间不足');
        }
        
        // 创建用户上传目录
        $userDir = GALLERY_DIR . $currentUserId . '/';
        ensureDir($userDir);
        
        // 生成文件名
        $fileExt = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        $fileName = uniqid() . '.' . $fileExt;
        $filePath = $userDir . $fileName;
        $relativePath = '/uploads/gallery/' . $currentUserId . '/' . $fileName;
        
        // 保存文件
        if (!move_uploaded_file($file['tmp_name'], $filePath)) {
            jsonResponse(false, '文件保存失败');
        }
        
        // 生成缩略图（如果需要）
        $thumbnailPath = null;
        if (in_array($fileType, ['image/jpeg', 'image/png', 'image/gif'])) {
            $thumbnailName = 'thumb_' . $fileName;
            $thumbnailPath = $userDir . $thumbnailName;
            createThumbnail($filePath, $thumbnailPath, 300, 300);
        }
        
        // 处理标签
        $tagsArray = [];
        if ($tags) {
            $tagsArray = array_map('trim', explode(',', $tags));
            $tagsArray = array_slice($tagsArray, 0, 10); // 最多10个标签
        }
        
        // 插入数据库
        $stmt = $pdo->prepare("
            INSERT INTO gallery_items (
                user_id, title, description, image_url, thumbnail_url,
                category, tags, is_public, file_size, mime_type
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        
        $stmt->execute([
            $currentUserId,
            $title,
            $description,
            $relativePath,
            $thumbnailPath ? '/uploads/gallery/' . $currentUserId . '/' . basename($thumbnailPath) : null,
            $category ?: null,
            $tagsArray ? json_encode($tagsArray, JSON_UNESCAPED_UNICODE) : null,
            $isPublic ? 1 : 0,
            $file['size'],
            $fileType
        ]);
        
        $itemId = $pdo->lastInsertId();
        
        // 更新用户存储使用量
        $updateStmt = $pdo->prepare("
            UPDATE users 
            SET used_storage = used_storage + ? 
            WHERE id = ?
        ");
        
        $updateStmt->execute([$file['size'], $currentUserId]);
        
        // 记录操作日志
        logOperation($currentUserId, 'create_gallery_item', 'gallery_item', $itemId, [
            'item_id' => $itemId,
            'title' => $title,
            'file_size' => $file['size'],
            'is_public' => $isPublic
        ]);
        
        jsonResponse(true, '作品上传成功', [
            'item_id' => $itemId,
            'title' => $title,
            'image_url' => $relativePath,
            'thumbnail_url' => $thumbnailPath ? '/uploads/gallery/' . $currentUserId . '/' . basename($thumbnailPath) : null,
            'file_size' => $file['size'],
            'formatted_file_size' => formatFileSize($file['size'])
        ]);
        
    } catch (Exception $e) {
        jsonResponse(false, '作品上传失败: ' . $e->getMessage());
    }
}

/**
 * 更新作品
 */
function handleUpdateGalleryItem($itemId) {
    $currentUserId = getCurrentUserId();
    $isAdmin = isAdmin();
    
    $input = getJsonInput();
    
    $title = trim($input['title'] ?? '');
    $description = trim($input['description'] ?? '');
    $category = trim($input['category'] ?? '');
    $tags = $input['tags'] ?? '';
    $isPublic = isset($input['is_public']) ? filter_var($input['is_public'], FILTER_VALIDATE_BOOLEAN) : null;
    
    // 验证输入
    if (!empty($title) && strlen($title) > 200) {
        jsonResponse(false, '作品标题不能超过200个字符');
    }
    
    if (!empty($description) && strlen($description) > 2000) {
        jsonResponse(false, '作品描述不能超过2000个字符');
    }
    
    if (!empty($category) && strlen($category) > 50) {
        jsonResponse(false, '分类不能超过50个字符');
    }
    
    try {
        $pdo = getDB();
        
        // 获取作品信息
        $stmt = $pdo->prepare("SELECT * FROM gallery_items WHERE id = ?");
        $stmt->execute([$itemId]);
        $item = $stmt->fetch();
        
        if (!$item) {
            jsonResponse(false, '作品不存在', null, 404);
        }
        
        // 权限检查：只能更新自己的作品或管理员可以更新所有
        if ($item['user_id'] != $currentUserId && !$isAdmin) {
            jsonResponse(false, '没有权限更新该作品', null, 403);
        }
        
        // 构建更新字段
        $updateFields = [];
        $updateParams = [];
        
        if (!empty($title)) {
            $updateFields[] = 'title = ?';
            $updateParams[] = $title;
        }
        
        if (isset($input['description'])) {
            $updateFields[] = 'description = ?';
            $updateParams[] = $description;
        }
        
        if (isset($input['category'])) {
            $updateFields[] = 'category = ?';
            $updateParams[] = $category ?: null;
        }
        
        if (isset($input['tags'])) {
            $tagsArray = [];
            if ($tags) {
                $tagsArray = array_map('trim', explode(',', $tags));
                $tagsArray = array_slice($tagsArray, 0, 10);
            }
            $updateFields[] = 'tags = ?';
            $updateParams[] = $tagsArray ? json_encode($tagsArray, JSON_UNESCAPED_UNICODE) : null;
        }
        
        if ($isPublic !== null) {
            $updateFields[] = 'is_public = ?';
            $updateParams[] = $isPublic ? 1 : 0;
        }
        
        if (empty($updateFields)) {
            jsonResponse(false, '没有需要更新的字段');
        }
        
        $updateFields[] = 'updated_at = NOW()';
        $updateParams[] = $itemId;
        
        $sql = "UPDATE gallery_items SET " . implode(', ', $updateFields) . " WHERE id = ?";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($updateParams);
        
        // 记录操作日志
        logOperation($currentUserId, 'update_gallery_item', 'gallery_item', $itemId, [
            'item_id' => $itemId,
            'title' => $item['title'],
            'fields_updated' => $updateFields
        ]);
        
        jsonResponse(true, '作品更新成功', [
            'item_id' => $itemId,
            'title' => $title ?: $item['title']
        ]);
        
    } catch (Exception $e) {
        jsonResponse(false, '作品更新失败: ' . $e->getMessage());
    }
}

/**
 * 删除作品
 */
function handleDeleteGalleryItem($itemId) {
    $currentUserId = getCurrentUserId();
    $isAdmin = isAdmin();
    
    try {
        $pdo = getDB();
        
        // 获取作品信息
        $stmt = $pdo->prepare("SELECT * FROM gallery_items WHERE id = ?");
        $stmt->execute([$itemId]);
        $item = $stmt->fetch();
        
        if (!$item) {
            jsonResponse(false, '作品不存在', null, 404);
        }
        
        // 权限检查：只能删除自己的作品或管理员可以删除所有
        if ($item['user_id'] != $currentUserId && !$isAdmin) {
            jsonResponse(false, '没有权限删除该作品', null, 403);
        }
        
        // 开始事务
        $pdo->beginTransaction();
        
        // 删除物理文件
        $imagePath = 'wwwroot' . $item['image_url'];
        if (file_exists($imagePath) && is_file($imagePath)) {
            unlink($imagePath);
        }
        
        if ($item['thumbnail_url']) {
            $thumbPath = 'wwwroot' . $item['thumbnail_url'];
            if (file_exists($thumbPath) && is_file($thumbPath)) {
                unlink($thumbPath);
            }
        }
        
        // 删除数据库记录
        $deleteStmt = $pdo->prepare("DELETE FROM gallery_items WHERE id = ?");
        $deleteStmt->execute([$itemId]);
        
        // 更新用户存储使用量
        if ($item['file_size'] > 0) {
            $updateStmt = $pdo->prepare("
                UPDATE users 
                SET used_storage = used_storage - ? 
                WHERE id = ?
            ");
            
            $updateStmt->execute([$item['file_size'], $item['user_id']]);
        }
        
        // 提交事务
        $pdo->commit();
        
        // 记录操作日志
        logOperation($currentUserId, 'delete_gallery_item', 'gallery_item', $itemId, [
            'item_id' => $itemId,
            'title' => $item['title'],
            'file_size' => $item['file_size']
        ]);
        
        jsonResponse(true, '作品删除成功', [
            'item_id' => $itemId,
            'title' => $item['title']
        ]);
        
    } catch (Exception $e) {
        if (isset($pdo) && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        jsonResponse(false, '作品删除失败: ' . $e->getMessage());
    }
}

/**
 * 点赞作品
 */
function handleLikeGalleryItem($itemId) {
    $currentUserId = getCurrentUserId();
    
    try {
        $pdo = getDB();
        
        // 获取作品信息
        $stmt = $pdo->prepare("SELECT * FROM gallery_items WHERE id = ?");
        $stmt->execute([$itemId]);
        $item = $stmt->fetch();
        
        if (!$item) {
            jsonResponse(false, '作品不存在', null, 404);
        }
        
        // 权限检查：只能点赞公开作品或自己的作品
        if (!$item['is_public'] && $item['user_id'] != $currentUserId) {
            jsonResponse(false, '没有权限点赞该作品', null, 403);
        }
        
        // 检查是否已点赞
        $checkStmt = $pdo->prepare("
            SELECT id FROM gallery_likes 
            WHERE user_id = ? AND gallery_id = ?
        ");
        
        $checkStmt->execute([$currentUserId, $itemId]);
        $existingLike = $checkStmt->fetch();
        
        if ($existingLike) {
            // 取消点赞
            $deleteStmt = $pdo->prepare("
                DELETE FROM gallery_likes 
                WHERE user_id = ? AND gallery_id = ?
            ");
            
            $deleteStmt->execute([$currentUserId, $itemId]);
            
            // 更新点赞数
            $updateStmt = $pdo->prepare("
                UPDATE gallery_items 
                SET like_count = GREATEST(0, like_count - 1) 
                WHERE id = ?
            ");
            
            $updateStmt->execute([$itemId]);
            
            $action = 'unlike';
        } else {
            // 点赞
            $insertStmt = $pdo->prepare("
                INSERT INTO gallery_likes (user_id, gallery_id) 
                VALUES (?, ?)
            ");
            
            $insertStmt->execute([$currentUserId, $itemId]);
            
            // 更新点赞数
            $updateStmt = $pdo->prepare("
                UPDATE gallery_items 
                SET like_count = like_count + 1 
                WHERE id = ?
            ");
            
            $updateStmt->execute([$itemId]);
            
            $action = 'like';
        }
        
        // 记录操作日志
        logOperation($currentUserId, $action . '_gallery_item', 'gallery_item', $itemId, [
            'item_id' => $itemId,
            'title' => $item['title'],
            'action' => $action
        ]);
        
        jsonResponse(true, $action === 'like' ? '点赞成功' : '取消点赞成功', [
            'item_id' => $itemId,
            'action' => $action,
            'like_count' => $item['like_count'] + ($action === 'like' ? 1 : -1)
        ]);
        
    } catch (Exception $e) {
        jsonResponse(false, '点赞操作失败: ' . $e->getMessage());
    }
}

/**
 * 获取分类统计
 */
function handleGetCategories() {
    try {
        $pdo = getDB();
        $currentUserId = getCurrentUserId();
        $isAdmin = isAdmin();
        
        // 构建权限条件
        $whereCondition = 'gi.is_public = TRUE';
        $params = [];
        
        if (!$isAdmin) {
            $whereCondition = '(gi.is_public = TRUE OR gi.user_id = ?)';
            $params[] = $currentUserId;
        }
        
        $sql = "
            SELECT 
                gi.category,
                COUNT(*) as item_count,
                SUM(gi.view_count) as total_views,
                SUM(gi.like_count) as total_likes
            FROM gallery_items gi
            WHERE gi.category IS NOT NULL AND gi.category != '' AND $whereCondition
            GROUP BY gi.category
            ORDER BY item_count DESC
        ";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $categories = $stmt->fetchAll();
        
        // 记录操作日志
        logOperation($currentUserId, 'view_categories', 'gallery', null, [
            'category_count' => count($categories)
        ]);
        
        jsonResponse(true, '获取分类统计成功', $categories);
        
    } catch (Exception $e) {
        jsonResponse(false, '获取分类统计失败: ' . $e->getMessage());
    }
}

/**
 * 获取作品统计
 */
function handleGetGalleryStatistics() {
    requireAdmin(); // 需要管理员权限
    
    try {
        $pdo = getDB();
        $currentUserId = getCurrentUserId();
        
        // 获取总体统计
        $overallStats = $pdo->query("
            SELECT 
                COUNT(*) as total_items,
                SUM(CASE WHEN is_public = TRUE THEN 1 ELSE 0 END) as public_items,
                SUM(CASE WHEN is_public = FALSE THEN 1 ELSE 0 END) as private_items,
                SUM(view_count) as total_views,
                SUM(like_count) as total_likes,
                SUM(download_count) as total_downloads,
                SUM(file_size) as total_size
            FROM gallery_items
        ")->fetch();
        
        // 获取按日统计
        $dailyStats = $pdo->query("
            SELECT 
                DATE(created_at) as date,
                COUNT(*) as upload_count,
                SUM(file_size) as upload_size
            FROM gallery_items
            WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
            GROUP BY DATE(created_at)
            ORDER BY date DESC
        ")->fetchAll();
        
        // 获取热门作品
        $popularItems = $pdo->query("
            SELECT 
                gi.id, gi.title, gi.user_id, gi.view_count, gi.like_count,
                u.username, u.display_name
            FROM gallery_items gi
            LEFT JOIN users u ON gi.user_id = u.id
            ORDER BY gi.view_count DESC
            LIMIT 10
        ")->fetchAll();
        
        // 获取活跃用户
        $activeUsers = $pdo->query("
            SELECT 
                u.id, u.username, u.display_name,
                COUNT(gi.id) as item_count,
                SUM(gi.view_count) as total_views,
                SUM(gi.like_count) as total_likes
            FROM users u
            LEFT JOIN gallery_items gi ON u.id = gi.user_id
            GROUP BY u.id, u.username, u.display_name
            ORDER BY item_count DESC
            LIMIT 10
        ")->fetchAll();
        
        $stats = [
            'overall' => [
                'total_items' => (int)$overallStats['total_items'],
                'public_items' => (int)$overallStats['public_items'],
                'private_items' => (int)$overallStats['private_items'],
                'total_views' => (int)$overallStats['total_views'],
                'total_likes' => (int)$overallStats['total_likes'],
                'total_downloads' => (int)$overallStats['total_downloads'],
                'total_size' => (int)$overallStats['total_size'],
                'formatted_total_size' => formatFileSize($overallStats['total_size'])
            ],
            'daily_stats' => array_map(function($day) {
                return [
                    'date' => $day['date'],
                    'upload_count' => (int)$day['upload_count'],
                    'upload_size' => (int)$day['upload_size'],
                    'formatted_upload_size' => formatFileSize($day['upload_size'])
                ];
            }, $dailyStats),
            'popular_items' => array_map(function($item) {
                return [
                    'id' => (int)$item['id'],
                    'title' => $item['title'],
                    'user' => [
                        'id' => (int)$item['user_id'],
                        'username' => $item['username'],
                        'display_name' => $item['display_name']
                    ],
                    'view_count' => (int)$item['view_count'],
                    'like_count' => (int)$item['like_count']
                ];
            }, $popularItems),
            'active_users' => array_map(function($user) {
                return [
                    'id' => (int)$user['id'],
                    'username' => $user['username'],
                    'display_name' => $user['display_name'],
                    'item_count' => (int)$user['item_count'],
                    'total_views' => (int)$user['total_views'],
                    'total_likes' => (int)$user['total_likes']
                ];
            }, $activeUsers)
        ];
        
        // 记录操作日志
        logOperation($currentUserId, 'view_gallery_statistics', 'admin', null, [
            'stats_viewed' => array_keys($stats)
        ]);
        
        jsonResponse(true, '获取作品统计成功', $stats);
        
    } catch (Exception $e) {
        jsonResponse(false, '获取作品统计失败: ' . $e->getMessage());
    }
}

/**
 * 创建缩略图
 */
function createThumbnail($sourcePath, $destPath, $width, $height) {
    $imageInfo = getimagesize($sourcePath);
    if (!$imageInfo) {
        return false;
    }
    
    $mime = $imageInfo['mime'];
    $sourceWidth = $imageInfo[0];
    $sourceHeight = $imageInfo[1];
    
    // 计算缩放比例
    $ratio = min($width / $sourceWidth, $height / $sourceHeight);
    $newWidth = round($sourceWidth * $ratio);
    $newHeight = round($sourceHeight * $ratio);
    
    // 创建新图像
    $newImage = imagecreatetruecolor($newWidth, $newHeight);
    
    // 根据MIME类型加载原图
    switch ($mime) {
        case 'image/jpeg':
            $sourceImage = imagecreatefromjpeg($sourcePath);
            break;
        case 'image/png':
            $sourceImage = imagecreatefrompng($sourcePath);
            // 保留透明度
            imagealphablending($newImage, false);
            imagesavealpha($newImage, true);
            $transparent = imagecolorallocatealpha($newImage, 0, 0, 0, 127);
            imagefill($newImage, 0, 0, $transparent);
            break;
        case 'image/gif':
            $sourceImage = imagecreatefromgif($sourcePath);
            break;
        case 'image/webp':
            $sourceImage = imagecreatefromwebp($sourcePath);
            break;
        default:
            return false;
    }
    
    // 调整大小
    imagecopyresampled($newImage, $sourceImage, 0, 0, 0, 0, $newWidth, $newHeight, $sourceWidth, $sourceHeight);
    
    // 保存缩略图
    switch ($mime) {
        case 'image/jpeg':
            imagejpeg($newImage, $destPath, 85);
            break;
        case 'image/png':
            imagepng($newImage, $destPath, 9);
            break;
        case 'image/gif':
            imagegif($newImage, $destPath);
            break;
        case 'image/webp':
            imagewebp($newImage, $destPath, 85);
            break;
    }
    
    // 释放内存
    imagedestroy($sourceImage);
    imagedestroy($newImage);
    
    return true;
}
?>