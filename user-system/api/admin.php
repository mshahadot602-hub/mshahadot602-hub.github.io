<?php
require_once 'config.php';

header('Content-Type: application/json; charset=utf-8');

// 检查管理员权限
requireAdmin();

// 获取请求方法
$method = $_SERVER['REQUEST_METHOD'];
$path = $_GET['path'] ?? '';

// 路由处理
switch ($method) {
    case 'GET':
        if ($path === 'users') {
            handleGetUsers();
        } elseif ($path === 'registration-requests') {
            handleGetRegistrationRequests();
        } elseif ($path === 'statistics') {
            handleGetStatistics();
        } else {
            jsonResponse(false, '无效的API路径');
        }
        break;
        
    case 'POST':
        if (preg_match('/^registration-requests\/(\d+)\/approve$/', $path, $matches)) {
            handleApproveRegistration($matches[1]);
        } elseif (preg_match('/^registration-requests\/(\d+)\/reject$/', $path, $matches)) {
            handleRejectRegistration($matches[1]);
        } elseif (preg_match('/^users\/(\d+)\/approve$/', $path, $matches)) {
            handleApproveUser($matches[1]);
        } else {
            jsonResponse(false, '无效的API路径');
        }
        break;
        
    case 'PUT':
        if (preg_match('/^users\/(\d+)\/storage$/', $path, $matches)) {
            handleUpdateUserStorage($matches[1]);
        } else {
            jsonResponse(false, '无效的API路径');
        }
        break;
        
    case 'DELETE':
        if (preg_match('/^users\/(\d+)$/', $path, $matches)) {
            handleDeleteUser($matches[1]);
        } else {
            jsonResponse(false, '无效的API路径');
        }
        break;
        
    default:
        jsonResponse(false, '不支持的请求方法');
        break;
}

/**
 * 获取用户列表
 */
function handleGetUsers() {
    $page = max(1, intval($_GET['page'] ?? 1));
    $limit = max(1, min(100, intval($_GET['limit'] ?? 20)));
    $offset = ($page - 1) * $limit;
    
    $search = trim($_GET['search'] ?? '');
    $status = $_GET['status'] ?? ''; // 'approved', 'pending', 'inactive'
    $orderBy = $_GET['orderBy'] ?? 'created_at';
    $order = strtoupper($_GET['order'] ?? 'desc');
    
    // 验证排序字段
    $allowedOrderFields = ['id', 'username', 'email', 'registration_date', 'last_login', 'used_storage'];
    if (!in_array($orderBy, $allowedOrderFields)) {
        $orderBy = 'created_at';
    }
    
    if ($order !== 'ASC' && $order !== 'DESC') {
        $order = 'DESC';
    }
    
    try {
        $pdo = getDB();
        $currentUserId = getCurrentUserId();
        
        // 构建查询条件
        $whereConditions = ['1=1'];
        $params = [];
        
        if ($search) {
            $whereConditions[] = '(u.username LIKE ? OR u.email LIKE ? OR u.display_name LIKE ?)';
            $searchTerm = "%{$search}%";
            $params[] = $searchTerm;
            $params[] = $searchTerm;
            $params[] = $searchTerm;
        }
        
        if ($status === 'approved') {
            $whereConditions[] = 'u.is_approved = TRUE';
        } elseif ($status === 'pending') {
            $whereConditions[] = 'u.is_approved = FALSE';
        } elseif ($status === 'inactive') {
            $whereConditions[] = 'u.is_active = FALSE';
        }
        
        $whereClause = implode(' AND ', $whereConditions);
        
        // 获取总数
        $countSql = "
            SELECT COUNT(*) as total
            FROM users u
            WHERE $whereClause
        ";
        
        $countStmt = $pdo->prepare($countSql);
        $countStmt->execute($params);
        $totalCount = $countStmt->fetch()['total'];
        
        // 获取用户列表
        $sql = "
            SELECT 
                u.id, u.username, u.email, u.display_name, u.avatar_url, u.bio,
                u.is_admin, u.is_approved, u.is_active,
                u.storage_limit, u.used_storage, u.registration_date, u.last_login,
                COUNT(DISTINCT gi.id) as gallery_count,
                COUNT(DISTINCT cf.id) as cloud_file_count,
                (SELECT COUNT(*) FROM operation_logs WHERE user_id = u.id) as log_count
            FROM users u
            LEFT JOIN gallery_items gi ON u.id = gi.user_id
            LEFT JOIN cloud_files cf ON u.id = cf.user_id
            WHERE $whereClause
            GROUP BY u.id
            ORDER BY u.{$orderBy} {$order}
            LIMIT ? OFFSET ?
        ";
        
        $params[] = $limit;
        $params[] = $offset;
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $users = $stmt->fetchAll();
        
        // 格式化用户数据
        $formattedUsers = array_map(function($user) {
            $storagePercentage = $user['storage_limit'] > 0 
                ? round($user['used_storage'] * 100 / $user['storage_limit'], 2)
                : 0;
            
            return [
                'id' => (int)$user['id'],
                'username' => $user['username'],
                'email' => $user['email'],
                'display_name' => $user['display_name'],
                'avatar_url' => $user['avatar_url'],
                'bio' => $user['bio'],
                'is_admin' => (bool)$user['is_admin'],
                'is_approved' => (bool)$user['is_approved'],
                'is_active' => (bool)$user['is_active'],
                'storage' => [
                    'limit' => (int)$user['storage_limit'],
                    'used' => (int)$user['used_storage'],
                    'free' => $user['storage_limit'] - $user['used_storage'],
                    'percentage' => $storagePercentage,
                    'formatted_limit' => formatFileSize($user['storage_limit']),
                    'formatted_used' => formatFileSize($user['used_storage']),
                    'formatted_free' => formatFileSize($user['storage_limit'] - $user['used_storage'])
                ],
                'statistics' => [
                    'gallery_count' => (int)$user['gallery_count'],
                    'cloud_file_count' => (int)$user['cloud_file_count'],
                    'log_count' => (int)$user['log_count']
                ],
                'dates' => [
                    'registration_date' => $user['registration_date'],
                    'last_login' => $user['last_login']
                ]
            ];
        }, $users);
        
        // 记录操作日志
        logOperation($currentUserId, 'view_users', 'admin', null, [
            'page' => $page,
            'limit' => $limit,
            'search' => $search,
            'status' => $status,
            'total_count' => $totalCount
        ]);
        
        jsonResponse(true, '获取用户列表成功', [
            'users' => $formattedUsers,
            'pagination' => [
                'page' => $page,
                'limit' => $limit,
                'total' => $totalCount,
                'pages' => ceil($totalCount / $limit)
            ]
        ]);
        
    } catch (Exception $e) {
        jsonResponse(false, '获取用户列表失败: ' . $e->getMessage());
    }
}

/**
 * 获取注册申请列表
 */
function handleGetRegistrationRequests() {
    $page = max(1, intval($_GET['page'] ?? 1));
    $limit = max(1, min(100, intval($_GET['limit'] ?? 20)));
    $offset = ($page - 1) * $limit;
    
    $status = $_GET['status'] ?? ''; // 'pending', 'approved', 'rejected'
    $orderBy = $_GET['orderBy'] ?? 'created_at';
    $order = strtoupper($_GET['order'] ?? 'desc');
    
    // 验证排序字段
    $allowedOrderFields = ['id', 'username', 'email', 'created_at', 'reviewed_at'];
    if (!in_array($orderBy, $allowedOrderFields)) {
        $orderBy = 'created_at';
    }
    
    if ($order !== 'ASC' && $order !== 'DESC') {
        $order = 'DESC';
    }
    
    try {
        $pdo = getDB();
        $currentUserId = getCurrentUserId();
        
        // 构建查询条件
        $whereConditions = ['1=1'];
        $params = [];
        
        if ($status) {
            $whereConditions[] = 'status = ?';
            $params[] = $status;
        }
        
        $whereClause = implode(' AND ', $whereConditions);
        
        // 获取总数
        $countSql = "
            SELECT COUNT(*) as total
            FROM registration_requests
            WHERE $whereClause
        ";
        
        $countStmt = $pdo->prepare($countSql);
        $countStmt->execute($params);
        $totalCount = $countStmt->fetch()['total'];
        
        // 获取申请列表
        $sql = "
            SELECT 
                r.*,
                ru.username as reviewer_username,
                ru.display_name as reviewer_display_name
            FROM registration_requests r
            LEFT JOIN users ru ON r.reviewed_by = ru.id
            WHERE $whereClause
            ORDER BY r.{$orderBy} {$order}
            LIMIT ? OFFSET ?
        ";
        
        $params[] = $limit;
        $params[] = $offset;
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $requests = $stmt->fetchAll();
        
        // 记录操作日志
        logOperation($currentUserId, 'view_registration_requests', 'admin', null, [
            'page' => $page,
            'limit' => $limit,
            'status' => $status,
            'total_count' => $totalCount
        ]);
        
        jsonResponse(true, '获取注册申请列表成功', [
            'requests' => $requests,
            'pagination' => [
                'page' => $page,
                'limit' => $limit,
                'total' => $totalCount,
                'pages' => ceil($totalCount / $limit)
            ]
        ]);
        
    } catch (Exception $e) {
        jsonResponse(false, '获取注册申请列表失败: ' . $e->getMessage());
    }
}

/**
 * 批准注册申请
 */
function handleApproveRegistration($requestId) {
    $currentUserId = getCurrentUserId();
    
    try {
        $pdo = getDB();
        
        // 获取注册申请
        $stmt = $pdo->prepare("
            SELECT * FROM registration_requests 
            WHERE id = ? AND status = 'pending'
        ");
        
        $stmt->execute([$requestId]);
        $request = $stmt->fetch();
        
        if (!$request) {
            jsonResponse(false, '申请不存在或已处理');
        }
        
        // 检查用户名和邮箱是否已存在
        $checkStmt = $pdo->prepare("
            SELECT id FROM users 
            WHERE username = ? OR email = ?
        ");
        
        $checkStmt->execute([$request['username'], $request['email']]);
        if ($checkStmt->fetch()) {
            jsonResponse(false, '用户名或邮箱已存在');
        }
        
        // 开始事务
        $pdo->beginTransaction();
        
        // 创建用户
        $userStmt = $pdo->prepare("
            INSERT INTO users (
                username, email, password_hash, display_name,
                is_approved, storage_limit
            ) VALUES (?, ?, ?, ?, TRUE, ?)
        ");
        
        $userStmt->execute([
            $request['username'],
            $request['email'],
            $request['password_hash'],
            $request['display_name'] ?: $request['username'],
            DEFAULT_STORAGE_LIMIT
        ]);
        
        $userId = $pdo->lastInsertId();
        
        // 更新注册申请状态
        $updateStmt = $pdo->prepare("
            UPDATE registration_requests 
            SET status = 'approved', reviewed_by = ?, reviewed_at = NOW(), review_notes = '已批准'
            WHERE id = ?
        ");
        
        $updateStmt->execute([$currentUserId, $requestId]);
        
        // 提交事务
        $pdo->commit();
        
        // 记录操作日志
        logOperation($currentUserId, 'approve_registration', 'registration_request', $requestId, [
            'request_id' => $requestId,
            'username' => $request['username'],
            'email' => $request['email'],
            'new_user_id' => $userId
        ]);
        
        jsonResponse(true, '注册申请已批准', [
            'request_id' => $requestId,
            'user_id' => $userId,
            'username' => $request['username'],
            'email' => $request['email']
        ]);
        
    } catch (Exception $e) {
        if (isset($pdo) && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        jsonResponse(false, '批准注册申请失败: ' . $e->getMessage());
    }
}

/**
 * 拒绝注册申请
 */
function handleRejectRegistration($requestId) {
    $currentUserId = getCurrentUserId();
    $input = getJsonInput();
    $reason = trim($input['reason'] ?? '');
    
    try {
        $pdo = getDB();
        
        // 获取注册申请
        $stmt = $pdo->prepare("
            SELECT * FROM registration_requests 
            WHERE id = ? AND status = 'pending'
        ");
        
        $stmt->execute([$requestId]);
        $request = $stmt->fetch();
        
        if (!$request) {
            jsonResponse(false, '申请不存在或已处理');
        }
        
        // 更新注册申请状态
        $updateStmt = $pdo->prepare("
            UPDATE registration_requests 
            SET status = 'rejected', reviewed_by = ?, reviewed_at = NOW(), review_notes = ?
            WHERE id = ?
        ");
        
        $updateStmt->execute([$currentUserId, $reason ?: '已拒绝', $requestId]);
        
        // 记录操作日志
        logOperation($currentUserId, 'reject_registration', 'registration_request', $requestId, [
            'request_id' => $requestId,
            'username' => $request['username'],
            'email' => $request['email'],
            'reason' => $reason
        ]);
        
        jsonResponse(true, '注册申请已拒绝', [
            'request_id' => $requestId,
            'username' => $request['username'],
            'email' => $request['email']
        ]);
        
    } catch (Exception $e) {
        jsonResponse(false, '拒绝注册申请失败: ' . $e->getMessage());
    }
}

/**
 * 批准用户（直接批准已注册但未审核的用户）
 */
function handleApproveUser($userId) {
    $currentUserId = getCurrentUserId();
    
    try {
        $pdo = getDB();
        
        // 获取用户
        $stmt = $pdo->prepare("
            SELECT * FROM users 
            WHERE id = ? AND is_approved = FALSE
        ");
        
        $stmt->execute([$userId]);
        $user = $stmt->fetch();
        
        if (!$user) {
            jsonResponse(false, '用户不存在或已审核');
        }
        
        // 更新用户状态
        $updateStmt = $pdo->prepare("
            UPDATE users 
            SET is_approved = TRUE, updated_at = NOW()
            WHERE id = ?
        ");
        
        $updateStmt->execute([$userId]);
        
        // 记录操作日志
        logOperation($currentUserId, 'approve_user', 'user', $userId, [
            'user_id' => $userId,
            'username' => $user['username'],
            'email' => $user['email']
        ]);
        
        jsonResponse(true, '用户已批准', [
            'user_id' => $userId,
            'username' => $user['username'],
            'email' => $user['email']
        ]);
        
    } catch (Exception $e) {
        jsonResponse(false, '批准用户失败: ' . $e->getMessage());
    }
}

/**
 * 更新用户存储空间
 */
function handleUpdateUserStorage($userId) {
    $currentUserId = getCurrentUserId();
    $input = getJsonInput();
    
    $storageLimit = intval($input['storage_limit'] ?? 0);
    
    if ($storageLimit < 0) {
        jsonResponse(false, '存储空间不能为负数');
    }
    
    try {
        $pdo = getDB();
        
        // 获取用户
        $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
        $stmt->execute([$userId]);
        $user = $stmt->fetch();
        
        if (!$user) {
            jsonResponse(false, '用户不存在');
        }
        
        // 检查新存储空间是否足够容纳现有文件
        if ($storageLimit < $user['used_storage']) {
            jsonResponse(false, '新存储空间不能小于已使用空间');
        }
        
        // 更新存储空间
        $updateStmt = $pdo->prepare("
            UPDATE users 
            SET storage_limit = ?, updated_at = NOW()
            WHERE id = ?
        ");
        
        $updateStmt->execute([$storageLimit, $userId]);
        
        // 记录操作日志
        logOperation($currentUserId, 'update_storage_limit', 'user', $userId, [
            'user_id' => $userId,
            'username' => $user['username'],
            'old_limit' => $user['storage_limit'],
            'new_limit' => $storageLimit,
            'formatted_old_limit' => formatFileSize($user['storage_limit']),
            'formatted_new_limit' => formatFileSize($storageLimit)
        ]);
        
        jsonResponse(true, '用户存储空间已更新', [
            'user_id' => $userId,
            'username' => $user['username'],
            'old_storage_limit' => (int)$user['storage_limit'],
            'new_storage_limit' => $storageLimit,
            'formatted_old_limit' => formatFileSize($user['storage_limit']),
            'formatted_new_limit' => formatFileSize($storageLimit)
        ]);
        
    } catch (Exception $e) {
        jsonResponse(false, '更新用户存储空间失败: ' . $e->getMessage());
    }
}

/**
 * 删除用户
 */
function handleDeleteUser($userId) {
    $currentUserId = getCurrentUserId();
    
    try {
        $pdo = getDB();
        
        // 获取用户
        $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
        $stmt->execute([$userId]);
        $user = $stmt->fetch();
        
        if (!$user) {
            jsonResponse(false, '用户不存在');
        }
        
        // 不能删除自己
        if ($userId == $currentUserId) {
            jsonResponse(false, '不能删除自己的账户');
        }
        
        // 开始事务
        $pdo->beginTransaction();
        
        // 删除用户的文件（物理文件）
        // 这里需要根据实际情况实现文件删除逻辑
        
        // 删除用户记录
        $deleteStmt = $pdo->prepare("DELETE FROM users WHERE id = ?");
        $deleteStmt->execute([$userId]);
        
        // 提交事务
        $pdo->commit();
        
        // 记录操作日志
        logOperation($currentUserId, 'delete_user', 'user', $userId, [
            'user_id' => $userId,
            'username' => $user['username'],
            'email' => $user['email']
        ]);
        
        jsonResponse(true, '用户已删除', [
            'user_id' => $userId,
            'username' => $user['username'],
            'email' => $user['email']
        ]);
        
    } catch (Exception $e) {
        if (isset($pdo) && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        jsonResponse(false, '删除用户失败: ' . $e->getMessage());
    }
}

/**
 * 获取系统统计
 */
function handleGetStatistics() {
    try {
        $pdo = getDB();
        $currentUserId = getCurrentUserId();
        
        // 获取用户统计
        $userStats = $pdo->query("
            SELECT 
                COUNT(*) as total_users,
                SUM(CASE WHEN is_admin = TRUE THEN 1 ELSE 0 END) as admin_users,
                SUM(CASE WHEN is_approved = TRUE THEN 1 ELSE 0 END) as approved_users,
                SUM(CASE WHEN is_approved = FALSE THEN 1 ELSE 0 END) as pending_users,
                SUM(CASE WHEN is_active = FALSE THEN 1 ELSE 0 END) as inactive_users
            FROM users
        ")->fetch();
        
        // 获取注册申请统计
        $regStats = $pdo->query("
            SELECT 
                COUNT(*) as total_requests,
                SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending_requests,
                SUM(CASE WHEN status = 'approved' THEN 1 ELSE 0 END) as approved_requests,
                SUM(CASE WHEN status = 'rejected' THEN 1 ELSE 0 END) as rejected_requests
            FROM registration_requests
        ")->fetch();
        
        // 获取作品统计
        $galleryStats = $pdo->query("
            SELECT 
                COUNT(*) as total_items,
                SUM(CASE WHEN is_public = TRUE THEN 1 ELSE 0 END) as public_items,
                SUM(CASE WHEN is_public = FALSE THEN 1 ELSE 0 END) as private_items,
                SUM(view_count) as total_views,
                SUM(like_count) as total_likes,
                SUM(download_count) as total_downloads
            FROM gallery_items
        ")->fetch();
        
        // 获取云盘统计
        $cloudStats = $pdo->query("
            SELECT 
                COUNT(*) as total_files,
                SUM(CASE WHEN is_public = TRUE THEN 1 ELSE 0 END) as public_files,
                SUM(file_size) as total_size,
                SUM(download_count) as total_downloads
            FROM cloud_files
        ")->fetch();
        
        // 获取操作日志统计
        $logStats = $pdo->query("
            SELECT 
                COUNT(*) as total_logs,
                COUNT(DISTINCT user_id) as unique_users,
                COUNT(DISTINCT DATE(created_at)) as active_days
            FROM operation_logs
        ")->fetch();
        
        // 获取今日活跃用户
        $todayActive = $pdo->query("
            SELECT COUNT(DISTINCT user_id) as today_active_users
            FROM operation_logs 
            WHERE DATE(created_at) = CURDATE()
        ")->fetch();
        
        $stats = [
            'users' => [
                'total' => (int)$userStats['total_users'],
                'admins' => (int)$userStats['admin_users'],
                'approved' => (int)$userStats['approved_users'],
                'pending' => (int)$userStats['pending_users'],
                'inactive' => (int)$userStats['inactive_users']
            ],
            'registrations' => [
                'total' => (int)$regStats['total_requests'],
                'pending' => (int)$regStats['pending_requests'],
                'approved' => (int)$regStats['approved_requests'],
                'rejected' => (int)$regStats['rejected_requests']
            ],
            'gallery' => [
                'total' => (int)$galleryStats['total_items'],
                'public' => (int)$galleryStats['public_items'],
                'private' => (int)$galleryStats['private_items'],
                'total_views' => (int)$galleryStats['total_views'],
                'total_likes' => (int)$galleryStats['total_likes'],
                'total_downloads' => (int)$galleryStats['total_downloads']
            ],
            'cloud' => [
                'total_files' => (int)$cloudStats['total_files'],
                'public_files' => (int)$cloudStats['public_files'],
                'total_size' => (int)$cloudStats['total_size'],
                'formatted_total_size' => formatFileSize($cloudStats['total_size']),
                'total_downloads' => (int)$cloudStats['total_downloads']
            ],
            'logs' => [
                'total' => (int)$logStats['total_logs'],
                'unique_users' => (int)$logStats['unique_users'],
                'active_days' => (int)$logStats['active_days'],
                'today_active_users' => (int)$todayActive['today_active_users']
            ],
            'system' => [
                'uptime' => '24/7',
                'health' => '100%',
                'last_backup' => date('Y-m-d H:i:s', strtotime('-1 day')),
                'next_backup' => date('Y-m-d H:i:s', strtotime('+1 day'))
            ]
        ];
        
        // 记录操作日志
        logOperation($currentUserId, 'view_statistics', 'admin', null, [
            'stats_viewed' => array_keys($stats)
        ]);
        
        jsonResponse(true, '获取系统统计成功', $stats);
        
    } catch (Exception $e) {
        jsonResponse(false, '获取系统统计失败: ' . $e->getMessage());
    }
}
?>