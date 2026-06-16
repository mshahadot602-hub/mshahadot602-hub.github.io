<?php
require_once 'config.php';

header('Content-Type: application/json; charset=utf-8');

// 获取请求方法
$method = $_SERVER['REQUEST_METHOD'];
$path = $_GET['path'] ?? '';

// 路由处理
switch ($method) {
    case 'GET':
        if ($path === 'files') {
            handleGetFiles();
        } elseif ($path === 'storage-info') {
            handleGetStorageInfo();
        } elseif (preg_match('/^download\/(\d+)$/', $path, $matches)) {
            handleDownloadFile($matches[1]);
        } elseif ($path === 'admin/statistics') {
            handleGetAdminStatistics();
        } else {
            jsonResponse(false, '无效的API路径');
        }
        break;
        
    case 'POST':
        if ($path === 'upload') {
            handleUploadFile();
        } else {
            jsonResponse(false, '无效的API路径');
        }
        break;
        
    case 'PUT':
        if (preg_match('/^files\/(\d+)\/visibility$/', $path, $matches)) {
            handleUpdateFileVisibility($matches[1]);
        } else {
            jsonResponse(false, '无效的API路径');
        }
        break;
        
    case 'DELETE':
        if (preg_match('/^files\/(\d+)$/', $path, $matches)) {
            handleDeleteFile($matches[1]);
        } else {
            jsonResponse(false, '无效的API路径');
        }
        break;
        
    default:
        jsonResponse(false, '不支持的请求方法');
        break;
}

/**
 * 获取文件列表
 */
function handleGetFiles() {
    $page = max(1, intval($_GET['page'] ?? 1));
    $limit = max(1, min(100, intval($_GET['limit'] ?? 20)));
    $offset = ($page - 1) * $limit;
    
    $userId = intval($_GET['user_id'] ?? 0);
    $isPublic = isset($_GET['is_public']) ? filter_var($_GET['is_public'], FILTER_VALIDATE_BOOLEAN) : null;
    $search = trim($_GET['search'] ?? '');
    $orderBy = $_GET['orderBy'] ?? 'created_at';
    $order = strtoupper($_GET['order'] ?? 'desc');
    
    // 验证排序字段
    $allowedOrderFields = ['id', 'filename', 'original_filename', 'file_size', 'created_at', 'download_count'];
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
        
        // 权限控制：普通用户只能看到自己的文件或公开文件
        if (!$isAdmin) {
            if ($userId > 0 && $userId != $currentUserId) {
                // 普通用户不能查看其他用户的文件，除非是公开文件
                $whereConditions[] = '(cf.user_id = ? OR cf.is_public = TRUE)';
                $params[] = $currentUserId;
            } else {
                $whereConditions[] = '(cf.user_id = ? OR cf.is_public = TRUE)';
                $params[] = $currentUserId;
            }
        } elseif ($userId > 0) {
            // 管理员可以查看指定用户的文件
            $whereConditions[] = 'cf.user_id = ?';
            $params[] = $userId;
        }
        
        if ($isPublic !== null) {
            $whereConditions[] = 'cf.is_public = ?';
            $params[] = $isPublic ? 1 : 0;
        }
        
        if ($search) {
            $whereConditions[] = '(cf.filename LIKE ? OR cf.original_filename LIKE ?)';
            $searchTerm = "%{$search}%";
            $params[] = $searchTerm;
            $params[] = $searchTerm;
        }
        
        $whereClause = implode(' AND ', $whereConditions);
        
        // 获取总数
        $countSql = "
            SELECT COUNT(*) as total
            FROM cloud_files cf
            WHERE $whereClause
        ";
        
        $countStmt = $pdo->prepare($countSql);
        $countStmt->execute($params);
        $totalCount = $countStmt->fetch()['total'];
        
        // 获取文件列表
        $sql = "
            SELECT 
                cf.*,
                u.username, u.display_name, u.avatar_url as user_avatar
            FROM cloud_files cf
            LEFT JOIN users u ON cf.user_id = u.id
            WHERE $whereClause
            ORDER BY cf.{$orderBy} {$order}
            LIMIT ? OFFSET ?
        ";
        
        $params[] = $limit;
        $params[] = $offset;
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $files = $stmt->fetchAll();
        
        // 格式化文件数据
        $formattedFiles = array_map(function($file) {
            return [
                'id' => (int)$file['id'],
                'user' => [
                    'id' => (int)$file['user_id'],
                    'username' => $file['username'],
                    'display_name' => $file['display_name'],
                    'avatar_url' => $file['user_avatar']
                ],
                'filename' => $file['filename'],
                'original_filename' => $file['original_filename'],
                'file_path' => $file['file_path'],
                'file_size' => (int)$file['file_size'],
                'formatted_file_size' => formatFileSize($file['file_size']),
                'mime_type' => $file['mime_type'],
                'is_public' => (bool)$file['is_public'],
                'download_count' => (int)$file['download_count'],
                'dates' => [
                    'created_at' => $file['created_at'],
                    'updated_at' => $file['updated_at']
                ]
            ];
        }, $files);
        
        // 记录操作日志
        logOperation($currentUserId, 'view_cloud_files', 'cloud', null, [
            'page' => $page,
            'limit' => $limit,
            'user_id' => $userId,
            'is_public' => $isPublic,
            'search' => $search,
            'total_count' => $totalCount
        ]);
        
        jsonResponse(true, '获取文件列表成功', [
            'files' => $formattedFiles,
            'pagination' => [
                'page' => $page,
                'limit' => $limit,
                'total' => $totalCount,
                'pages' => ceil($totalCount / $limit)
            ]
        ]);
        
    } catch (Exception $e) {
        jsonResponse(false, '获取文件列表失败: ' . $e->getMessage());
    }
}

/**
 * 获取存储信息
 */
function handleGetStorageInfo() {
    $currentUserId = getCurrentUserId();
    
    try {
        $pdo = getDB();
        
        $sql = "
            SELECT 
                storage_limit,
                used_storage,
                storage_limit - used_storage as free_storage,
                ROUND(used_storage * 100.0 / storage_limit, 2) as usage_percentage,
                (SELECT COUNT(*) FROM cloud_files WHERE user_id = ?) as file_count,
                (SELECT SUM(file_size) FROM cloud_files WHERE user_id = ? AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)) as recent_upload_size
            FROM users 
            WHERE id = ?
        ";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$currentUserId, $currentUserId, $currentUserId]);
        $info = $stmt->fetch();
        
        if (!$info) {
            jsonResponse(false, '用户不存在');
        }
        
        $storageInfo = [
            'storage_limit' => (int)$info['storage_limit'],
            'used_storage' => (int)$info['used_storage'],
            'free_storage' => (int)$info['free_storage'],
            'usage_percentage' => (float)$info['usage_percentage'],
            'file_count' => (int)$info['file_count'],
            'recent_upload_size' => (int)$info['recent_upload_size'],
            'formatted_storage_limit' => formatFileSize($info['storage_limit']),
            'formatted_used_storage' => formatFileSize($info['used_storage']),
            'formatted_free_storage' => formatFileSize($info['free_storage']),
            'formatted_recent_upload_size' => formatFileSize($info['recent_upload_size'])
        ];
        
        // 获取文件类型分布
        $typeStmt = $pdo->prepare("
            SELECT 
                CASE 
                    WHEN mime_type LIKE 'image/%' THEN '图片'
                    WHEN mime_type LIKE 'video/%' THEN '视频'
                    WHEN mime_type LIKE 'audio/%' THEN '音频'
                    WHEN mime_type LIKE 'text/%' OR mime_type = 'application/pdf' THEN '文档'
                    WHEN mime_type LIKE 'application/%' THEN '应用'
                    ELSE '其他'
                END as file_type,
                COUNT(*) as count,
                SUM(file_size) as total_size
            FROM cloud_files 
            WHERE user_id = ?
            GROUP BY file_type
            ORDER BY total_size DESC
        ");
        
        $typeStmt->execute([$currentUserId]);
        $typeStats = $typeStmt->fetchAll();
        
        $storageInfo['type_distribution'] = array_map(function($stat) {
            return [
                'file_type' => $stat['file_type'],
                'count' => (int)$stat['count'],
                'total_size' => (int)$stat['total_size'],
                'formatted_total_size' => formatFileSize($stat['total_size'])
            ];
        }, $typeStats);
        
        jsonResponse(true, '获取存储信息成功', $storageInfo);
        
    } catch (Exception $e) {
        jsonResponse(false, '获取存储信息失败: ' . $e->getMessage());
    }
}

/**
 * 上传文件
 */
function handleUploadFile() {
    $currentUserId = getCurrentUserId();
    
    // 检查是否有文件上传
    if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
        jsonResponse(false, '请选择要上传的文件');
    }
    
    $file = $_FILES['file'];
    $isPublic = isset($_POST['is_public']) ? filter_var($_POST['is_public'], FILTER_VALIDATE_BOOLEAN) : false;
    
    // 验证文件
    if ($file['size'] > MAX_FILE_SIZE) {
        jsonResponse(false, '文件大小不能超过2GB');
    }
    
    if ($file['size'] == 0) {
        jsonResponse(false, '文件不能为空');
    }
    
    try {
        $pdo = getDB();
        
        // 检查用户存储空间
        $checkStmt = $pdo->prepare("
            CALL check_user_storage(?, ?, @can_upload)
        ");
        
        $checkStmt->execute([$currentUserId, $file['size']]);
        
        $resultStmt = $pdo->query("SELECT @can_upload as can_upload");
        $result = $resultStmt->fetch();
        
        if (!$result['can_upload']) {
            jsonResponse(false, '存储空间不足，无法上传文件');
        }
        
        // 创建用户上传目录
        $userDir = CLOUD_DIR . $currentUserId . '/';
        ensureDir($userDir);
        
        // 生成文件名
        $originalFileName = basename($file['name']);
        $fileExt = strtolower(pathinfo($originalFileName, PATHINFO_EXTENSION));
        $fileName = uniqid() . '.' . $fileExt;
        $filePath = $userDir . $fileName;
        $relativePath = '/uploads/cloud/' . $currentUserId . '/' . $fileName;
        
        // 保存文件
        if (!move_uploaded_file($file['tmp_name'], $filePath)) {
            jsonResponse(false, '文件保存失败');
        }
        
        // 获取MIME类型
        $mimeType = mime_content_type($filePath);
        if (!$mimeType) {
            $mimeType = getMimeTypeByExtension($fileExt);
        }
        
        // 插入数据库
        $stmt = $pdo->prepare("
            INSERT INTO cloud_files (
                user_id, filename, original_filename, file_path, 
                file_size, mime_type, is_public
            ) VALUES (?, ?, ?, ?, ?, ?, ?)
        ");
        
        $stmt->execute([
            $currentUserId,
            $fileName,
            $originalFileName,
            $relativePath,
            $file['size'],
            $mimeType,
            $isPublic ? 1 : 0
        ]);
        
        $fileId = $pdo->lastInsertId();
        
        // 记录操作日志
        logOperation($currentUserId, 'upload_file', 'cloud_file', $fileId, [
            'file_id' => $fileId,
            'filename' => $originalFileName,
            'file_size' => $file['size'],
            'is_public' => $isPublic
        ]);
        
        jsonResponse(true, '文件上传成功', [
            'file_id' => $fileId,
            'filename' => $originalFileName,
            'file_path' => $relativePath,
            'file_size' => $file['size'],
            'formatted_file_size' => formatFileSize($file['size']),
            'mime_type' => $mimeType,
            'is_public' => $isPublic
        ]);
        
    } catch (Exception $e) {
        jsonResponse(false, '文件上传失败: ' . $e->getMessage());
    }
}

/**
 * 下载文件
 */
function handleDownloadFile($fileId) {
    try {
        $pdo = getDB();
        $currentUserId = getCurrentUserId();
        $isAdmin = isAdmin();
        
        // 获取文件信息
        $sql = "
            SELECT cf.*, u.username
            FROM cloud_files cf
            LEFT JOIN users u ON cf.user_id = u.id
            WHERE cf.id = ?
        ";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$fileId]);
        $file = $stmt->fetch();
        
        if (!$file) {
            jsonResponse(false, '文件不存在', null, 404);
        }
        
        // 权限检查
        if (!$file['is_public'] && $file['user_id'] != $currentUserId && !$isAdmin) {
            jsonResponse(false, '没有权限下载该文件', null, 403);
        }
        
        // 检查文件是否存在
        $physicalPath = 'wwwroot' . $file['file_path'];
        if (!file_exists($physicalPath) || !is_file($physicalPath)) {
            jsonResponse(false, '文件不存在或已被删除', null, 404);
        }
        
        // 更新下载次数
        $updateStmt = $pdo->prepare("
            UPDATE cloud_files 
            SET download_count = download_count + 1 
            WHERE id = ?
        ");
        
        $updateStmt->execute([$fileId]);
        
        // 记录操作日志
        logOperation($currentUserId, 'download_file', 'cloud_file', $fileId, [
            'file_id' => $fileId,
            'filename' => $file['original_filename'],
            'file_size' => $file['file_size']
        ]);
        
        // 设置HTTP头
        header('Content-Description: File Transfer');
        header('Content-Type: ' . ($file['mime_type'] ?: 'application/octet-stream'));
        header('Content-Disposition: attachment; filename="' . rawurlencode($file['original_filename']) . '"');
        header('Content-Length: ' . $file['file_size']);
        header('Cache-Control: must-revalidate');
        header('Pragma: public');
        header('Expires: 0');
        
        // 输出文件
        readfile($physicalPath);
        exit;
        
    } catch (Exception $e) {
        jsonResponse(false, '文件下载失败: ' . $e->getMessage());
    }
}

/**
 * 更新文件可见性
 */
function handleUpdateFileVisibility($fileId) {
    $currentUserId = getCurrentUserId();
    $isAdmin = isAdmin();
    
    $input = getJsonInput();
    $isPublic = isset($input['is_public']) ? filter_var($input['is_public'], FILTER_VALIDATE_BOOLEAN) : false;
    
    try {
        $pdo = getDB();
        
        // 获取文件信息
        $stmt = $pdo->prepare("SELECT * FROM cloud_files WHERE id = ?");
        $stmt->execute([$fileId]);
        $file = $stmt->fetch();
        
        if (!$file) {
            jsonResponse(false, '文件不存在', null, 404);
        }
        
        // 权限检查：只能更新自己的文件或管理员可以更新所有
        if ($file['user_id'] != $currentUserId && !$isAdmin) {
            jsonResponse(false, '没有权限更新该文件', null, 403);
        }
        
        // 更新可见性
        $updateStmt = $pdo->prepare("
            UPDATE cloud_files 
            SET is_public = ?, updated_at = NOW()
            WHERE id = ?
        ");
        
        $updateStmt->execute([$isPublic ? 1 : 0, $fileId]);
        
        // 记录操作日志
        logOperation($currentUserId, 'update_file_visibility', 'cloud_file', $fileId, [
            'file_id' => $fileId,
            'filename' => $file['original_filename'],
            'old_visibility' => (bool)$file['is_public'],
            'new_visibility' => $isPublic
        ]);
        
        jsonResponse(true, '文件可见性已更新', [
            'file_id' => $fileId,
            'filename' => $file['original_filename'],
            'is_public' => $isPublic
        ]);
        
    } catch (Exception $e) {
        jsonResponse(false, '更新文件可见性失败: ' . $e->getMessage());
    }
}

/**
 * 删除文件
 */
function handleDeleteFile($fileId) {
    $currentUserId = getCurrentUserId();
    $isAdmin = isAdmin();
    
    try {
        $pdo = getDB();
        
        // 获取文件信息
        $stmt = $pdo->prepare("SELECT * FROM cloud_files WHERE id = ?");
        $stmt->execute([$fileId]);
        $file = $stmt->fetch();
        
        if (!$file) {
            jsonResponse(false, '文件不存在', null, 404);
        }
        
        // 权限检查：只能删除自己的文件或管理员可以删除所有
        if ($file['user_id'] != $currentUserId && !$isAdmin) {
            jsonResponse(false, '没有权限删除该文件', null, 403);
        }
        
        // 开始事务
        $pdo->beginTransaction();
        
        // 删除物理文件
        $physicalPath = 'wwwroot' . $file['file_path'];
        if (file_exists($physicalPath) && is_file($physicalPath)) {
            unlink($physicalPath);
        }
        
        // 删除数据库记录
        $deleteStmt = $pdo->prepare("DELETE FROM cloud_files WHERE id = ?");
        $deleteStmt->execute([$fileId]);
        
        // 更新用户存储使用量
        $updateStmt = $pdo->prepare("
            UPDATE users 
            SET used_storage = used_storage - ? 
            WHERE id = ?
        ");
        
        $updateStmt->execute([$file['file_size'], $file['user_id']]);
        
        // 提交事务
        $pdo->commit();
        
        // 记录操作日志
        logOperation($currentUserId, 'delete_file', 'cloud_file', $fileId, [
            'file_id' => $fileId,
            'filename' => $file['original_filename'],
            'file_size' => $file['file_size']
        ]);
        
        jsonResponse(true, '文件删除成功', [
            'file_id' => $fileId,
            'filename' => $file['original_filename']
        ]);
        
    } catch (Exception $e) {
        if (isset($pdo) && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        jsonResponse(false, '文件删除失败: ' . $e->getMessage());
    }
}

/**
 * 获取管理员统计
 */
function handleGetAdminStatistics() {
    requireAdmin(); // 需要管理员权限
    
    try {
        $pdo = getDB();
        $currentUserId = getCurrentUserId();
        
        // 获取总体统计
        $overallStats = $pdo->query("
            SELECT 
                COUNT(*) as total_files,
                SUM(file_size) as total_size,
                COUNT(DISTINCT user_id) as total_users,
                (SELECT COUNT(*) FROM cloud_files WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)) as recent_files,
                (SELECT COUNT(*) FROM cloud_files WHERE is_public = TRUE) as public_files,
                (SELECT SUM(download_count) FROM cloud_files) as total_downloads
            FROM cloud_files
        ")->fetch();
        
        // 获取按日统计
        $dailyStats = $pdo->query("
            SELECT 
                DATE(created_at) as date,
                COUNT(*) as upload_count,
                SUM(file_size) as upload_size
            FROM cloud_files
            WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
            GROUP BY DATE(created_at)
            ORDER BY date DESC
        ")->fetchAll();
        
        // 获取文件类型统计
        $typeStats = $pdo->query("
            SELECT 
                CASE 
                    WHEN mime_type LIKE 'image/%' THEN '图片'
                    WHEN mime_type LIKE 'video/%' THEN '视频'
                    WHEN mime_type LIKE 'audio/%' THEN '音频'
                    WHEN mime_type LIKE 'text/%' OR mime_type = 'application/pdf' THEN '文档'
                    WHEN mime_type LIKE 'application/%' THEN '应用'
                    ELSE '其他'
                END as file_type,
                COUNT(*) as count,
                SUM(file_size) as total_size,
                AVG(file_size) as avg_size
            FROM cloud_files
            GROUP BY file_type
            ORDER BY total_size DESC
        ")->fetchAll();
        
        // 获取用户存储使用排行
        $userStats = $pdo->query("
            SELECT 
                u.id, u.username, u.display_name,
                u.storage_limit, u.used_storage,
                COUNT(cf.id) as file_count
            FROM users u
            LEFT JOIN cloud_files cf ON u.id = cf.user_id
            GROUP BY u.id, u.username, u.display_name, u.storage_limit, u.used_storage
            ORDER BY u.used_storage DESC
            LIMIT 10
        ")->fetchAll();
        
        // 获取热门文件（下载次数最多）
        $popularFiles = $pdo->query("
            SELECT 
                cf.id, cf.original_filename, cf.download_count, cf.file_size,
                u.username, u.display_name
            FROM cloud_files cf
            LEFT JOIN users u ON cf.user_id = u.id
            WHERE cf.is_public = TRUE
            ORDER BY cf.download_count DESC
            LIMIT 10
        ")->fetchAll();
        
        $stats = [
            'overall' => [
                'total_files' => (int)$overallStats['total_files'],
                'total_size' => (int)$overallStats['total_size'],
                'formatted_total_size' => formatFileSize($overallStats['total_size']),
                'total_users' => (int)$overallStats['total_users'],
                'recent_files' => (int)$overallStats['recent_files'],
                'public_files' => (int)$overallStats['public_files'],
                'total_downloads' => (int)$overallStats['total_downloads']
            ],
            'daily_stats' => array_map(function($day) {
                return [
                    'date' => $day['date'],
                    'upload_count' => (int)$day['upload_count'],
                    'upload_size' => (int)$day['upload_size'],
                    'formatted_upload_size' => formatFileSize($day['upload_size'])
                ];
            }, $dailyStats),
            'type_stats' => array_map(function($type) {
                return [
                    'file_type' => $type['file_type'],
                    'count' => (int)$type['count'],
                    'total_size' => (int)$type['total_size'],
                    'formatted_total_size' => formatFileSize($type['total_size']),
                    'avg_size' => (int)$type['avg_size'],
                    'formatted_avg_size' => formatFileSize($type['avg_size'])
                ];
            }, $typeStats),
            'user_stats' => array_map(function($user) {
                $usagePercentage = $user['storage_limit'] > 0 
                    ? round($user['used_storage'] * 100 / $user['storage_limit'], 2)
                    : 0;
                
                return [
                    'user' => [
                        'id' => (int)$user['id'],
                        'username' => $user['username'],
                        'display_name' => $user['display_name']
                    ],
                    'storage' => [
                        'limit' => (int)$user['storage_limit'],
                        'used' => (int)$user['used_storage'],
                        'free' => $user['storage_limit'] - $user['used_storage'],
                        'percentage' => $usagePercentage,
                        'formatted_limit' => formatFileSize($user['storage_limit']),
                        'formatted_used' => formatFileSize($user['used_storage']),
                        'formatted_free' => formatFileSize($user['storage_limit'] - $user['used_storage'])
                    ],
                    'file_count' => (int)$user['file_count']
                ];
            }, $userStats),
            'popular_files' => array_map(function($file) {
                return [
                    'id' => (int)$file['id'],
                    'filename' => $file['original_filename'],
                    'user' => [
                        'username' => $file['username'],
                        'display_name' => $file['display_name']
                    ],
                    'download_count' => (int)$file['download_count'],
                    'file_size' => (int)$file['file_size'],
                    'formatted_file_size' => formatFileSize($file['file_size'])
                ];
            }, $popularFiles)
        ];
        
        // 记录操作日志
        logOperation($currentUserId, 'view_cloud_statistics', 'admin', null, [
            'stats_viewed' => array_keys($stats)
        ]);
        
        jsonResponse(true, '获取云盘统计成功', $stats);
        
    } catch (Exception $e) {
        jsonResponse(false, '获取云盘统计失败: ' . $e->getMessage());
    }
}

/**
 * 根据文件扩展名获取MIME类型
 */
function getMimeTypeByExtension($extension) {
    $mimeTypes = [
        'txt' => 'text/plain',
        'pdf' => 'application/pdf',
        'doc' => 'application/msword',
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'xls' => 'application/vnd.ms-excel',
        'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'ppt' => 'application/vnd.ms-powerpoint',
        'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png' => 'image/png',
        'gif' => 'image/gif',
        'bmp' => 'image/bmp',
        'webp' => 'image/webp',
        'zip' => 'application/zip',
        'rar' => 'application/x-rar-compressed',
        '7z' => 'application/x-7z-compressed',
        'mp3' => 'audio/mpeg',
        'wav' => 'audio/wav',
        'mp4' => 'video/mp4',
        'avi' => 'video/x-msvideo',
        'mov' => 'video/quicktime',
        'mkv' => 'video/x-matroska',
        'flv' => 'video/x-flv',
        'html' => 'text/html',
        'htm' => 'text/html',
        'css' => 'text/css',
        'js' => 'application/javascript',
        'json' => 'application/json',
        'xml' => 'application/xml',
        'csv' => 'text/csv'
    ];
    
    $extension = strtolower($extension);
    return $mimeTypes[$extension] ?? 'application/octet-stream';
}
?>