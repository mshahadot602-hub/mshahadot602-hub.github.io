<?php
require_once 'config.php';

header('Content-Type: application/json; charset=utf-8');

// 获取请求方法
$method = $_SERVER['REQUEST_METHOD'];
$path = $_GET['path'] ?? '';

// 路由处理
switch ($method) {
    case 'POST':
        if ($path === 'login') {
            handleLogin();
        } elseif ($path === 'register') {
            handleRegister();
        } else {
            jsonResponse(false, '无效的API路径');
        }
        break;
        
    case 'GET':
        if (preg_match('/^profile\/(\d+)$/', $path, $matches)) {
            handleGetProfile($matches[1]);
        } else {
            jsonResponse(false, '无效的API路径');
        }
        break;
        
    case 'PUT':
        if (preg_match('/^profile\/(\d+)$/', $path, $matches)) {
            handleUpdateProfile($matches[1]);
        } else {
            jsonResponse(false, '无效的API路径');
        }
        break;
        
    default:
        jsonResponse(false, '不支持的请求方法');
        break;
}

/**
 * 用户登录
 */
function handleLogin() {
    $input = getJsonInput();
    
    $username = trim($input['username'] ?? '');
    $password = $input['password'] ?? '';
    
    if (empty($username) || empty($password)) {
        jsonResponse(false, '用户名和密码不能为空');
    }
    
    try {
        $pdo = getDB();
        
        // 查询用户
        $stmt = $pdo->prepare("
            SELECT id, username, email, display_name, avatar_url, bio, 
                   password_hash, is_admin, is_approved, is_active, 
                   storage_limit, used_storage, registration_date
            FROM users 
            WHERE username = ? OR email = ?
        ");
        
        $stmt->execute([$username, $username]);
        $user = $stmt->fetch();
        
        if (!$user) {
            jsonResponse(false, '用户名或密码错误');
        }
        
        // 验证密码
        if (!verifyPassword($password, $user['password_hash'])) {
            jsonResponse(false, '用户名或密码错误');
        }
        
        // 检查用户状态
        if (!$user['is_active']) {
            jsonResponse(false, '账户已被禁用');
        }
        
        if (!$user['is_approved']) {
            jsonResponse(false, '账户尚未通过审核');
        }
        
        // 更新最后登录时间
        $updateStmt = $pdo->prepare("UPDATE users SET last_login = NOW() WHERE id = ?");
        $updateStmt->execute([$user['id']]);
        
        // 生成 JWT Token
        $token = generateJWT($user['id'], $user['username'], $user['is_admin']);
        
        // 记录操作日志
        logOperation($user['id'], 'login', 'user', $user['id'], [
            'method' => 'password',
            'ip' => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1'
        ]);
        
        // 返回用户信息和Token
        $userData = [
            'id' => (int)$user['id'],
            'username' => $user['username'],
            'email' => $user['email'],
            'display_name' => $user['display_name'],
            'avatar_url' => $user['avatar_url'],
            'bio' => $user['bio'],
            'is_admin' => (bool)$user['is_admin'],
            'is_approved' => (bool)$user['is_approved'],
            'storage_limit' => (int)$user['storage_limit'],
            'used_storage' => (int)$user['used_storage'],
            'registration_date' => $user['registration_date'],
            'last_login' => date('Y-m-d H:i:s')
        ];
        
        jsonResponse(true, '登录成功', [
            'user' => $userData,
            'token' => $token
        ]);
        
    } catch (Exception $e) {
        jsonResponse(false, '登录失败: ' . $e->getMessage());
    }
}

/**
 * 用户注册
 */
function handleRegister() {
    $input = getJsonInput();
    
    $username = trim($input['username'] ?? '');
    $email = trim($input['email'] ?? '');
    $password = $input['password'] ?? '';
    $displayName = trim($input['display_name'] ?? '');
    $reason = trim($input['reason'] ?? '');
    
    // 验证输入
    if (empty($username) || empty($email) || empty($password)) {
        jsonResponse(false, '用户名、邮箱和密码不能为空');
    }
    
    if (strlen($username) < 3 || strlen($username) > 50) {
        jsonResponse(false, '用户名长度必须在3-50个字符之间');
    }
    
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        jsonResponse(false, '邮箱格式不正确');
    }
    
    if (strlen($password) < 6) {
        jsonResponse(false, '密码长度至少6位');
    }
    
    if (!empty($displayName) && (strlen($displayName) < 2 || strlen($displayName) > 100)) {
        jsonResponse(false, '昵称长度必须在2-100个字符之间');
    }
    
    try {
        $pdo = getDB();
        
        // 检查用户名是否已存在
        $stmt = $pdo->prepare("SELECT id FROM users WHERE username = ?");
        $stmt->execute([$username]);
        if ($stmt->fetch()) {
            jsonResponse(false, '用户名已存在');
        }
        
        // 检查邮箱是否已存在
        $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ?");
        $stmt->execute([$email]);
        if ($stmt->fetch()) {
            jsonResponse(false, '邮箱已存在');
        }
        
        // 检查注册申请中是否已存在
        $stmt = $pdo->prepare("SELECT id FROM registration_requests WHERE username = ? OR email = ?");
        $stmt->execute([$username, $email]);
        if ($stmt->fetch()) {
            jsonResponse(false, '该用户名或邮箱已提交注册申请，请等待审核');
        }
        
        // 密码哈希
        $passwordHash = hashPassword($password);
        
        // 插入注册申请
        $stmt = $pdo->prepare("
            INSERT INTO registration_requests (username, email, password_hash, display_name, reason)
            VALUES (?, ?, ?, ?, ?)
        ");
        
        $stmt->execute([$username, $email, $passwordHash, $displayName, $reason]);
        $requestId = $pdo->lastInsertId();
        
        // 记录操作日志
        logOperation(null, 'register_request', 'registration_request', $requestId, [
            'username' => $username,
            'email' => $email,
            'reason' => $reason
        ]);
        
        jsonResponse(true, '注册申请已提交，请等待管理员审核', [
            'request_id' => $requestId,
            'username' => $username,
            'email' => $email
        ]);
        
    } catch (Exception $e) {
        jsonResponse(false, '注册失败: ' . $e->getMessage());
    }
}

/**
 * 获取用户资料
 */
function handleGetProfile($userId) {
    $currentUserId = getCurrentUserId();
    $isAdmin = isAdmin();
    
    // 权限检查：只能查看自己的资料或管理员可以查看所有
    if ($currentUserId != $userId && !$isAdmin) {
        jsonResponse(false, '没有权限查看该用户资料', null, 403);
    }
    
    try {
        $pdo = getDB();
        
        $stmt = $pdo->prepare("
            SELECT 
                u.id, u.username, u.email, u.display_name, u.avatar_url, u.bio,
                u.is_admin, u.is_approved, u.is_active, 
                u.storage_limit, u.used_storage, u.registration_date, u.last_login,
                COUNT(DISTINCT gi.id) as gallery_count,
                COUNT(DISTINCT cf.id) as cloud_file_count,
                COUNT(DISTINCT f.following_id) as following_count,
                COUNT(DISTINCT f2.follower_id) as follower_count
            FROM users u
            LEFT JOIN gallery_items gi ON u.id = gi.user_id AND gi.is_public = TRUE
            LEFT JOIN cloud_files cf ON u.id = cf.user_id
            LEFT JOIN user_follows f ON u.id = f.follower_id
            LEFT JOIN user_follows f2 ON u.id = f2.following_id
            WHERE u.id = ?
            GROUP BY u.id
        ");
        
        $stmt->execute([$userId]);
        $user = $stmt->fetch();
        
        if (!$user) {
            jsonResponse(false, '用户不存在', null, 404);
        }
        
        // 计算存储使用百分比
        $storagePercentage = $user['storage_limit'] > 0 
            ? round($user['used_storage'] * 100 / $user['storage_limit'], 2)
            : 0;
        
        $profileData = [
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
                'following_count' => (int)$user['following_count'],
                'follower_count' => (int)$user['follower_count']
            ],
            'dates' => [
                'registration_date' => $user['registration_date'],
                'last_login' => $user['last_login']
            ]
        ];
        
        jsonResponse(true, '获取用户资料成功', $profileData);
        
    } catch (Exception $e) {
        jsonResponse(false, '获取用户资料失败: ' . $e->getMessage());
    }
}

/**
 * 更新用户资料
 */
function handleUpdateProfile($userId) {
    $currentUserId = getCurrentUserId();
    $isAdmin = isAdmin();
    
    // 权限检查：只能更新自己的资料或管理员可以更新所有
    if ($currentUserId != $userId && !$isAdmin) {
        jsonResponse(false, '没有权限更新该用户资料', null, 403);
    }
    
    $input = getJsonInput();
    
    $displayName = trim($input['display_name'] ?? '');
    $avatarUrl = trim($input['avatar_url'] ?? '');
    $bio = trim($input['bio'] ?? '');
    
    // 验证输入
    if (!empty($displayName) && (strlen($displayName) < 2 || strlen($displayName) > 100)) {
        jsonResponse(false, '昵称长度必须在2-100个字符之间');
    }
    
    if (!empty($bio) && strlen($bio) > 1000) {
        jsonResponse(false, '个人简介不能超过1000个字符');
    }
    
    try {
        $pdo = getDB();
        
        // 检查用户是否存在
        $checkStmt = $pdo->prepare("SELECT id FROM users WHERE id = ?");
        $checkStmt->execute([$userId]);
        if (!$checkStmt->fetch()) {
            jsonResponse(false, '用户不存在', null, 404);
        }
        
        // 更新用户资料
        $updateFields = [];
        $updateParams = [];
        
        if (!empty($displayName)) {
            $updateFields[] = 'display_name = ?';
            $updateParams[] = $displayName;
        }
        
        if (!empty($avatarUrl)) {
            $updateFields[] = 'avatar_url = ?';
            $updateParams[] = $avatarUrl;
        }
        
        if (isset($input['bio'])) {
            $updateFields[] = 'bio = ?';
            $updateParams[] = $bio;
        }
        
        if (empty($updateFields)) {
            jsonResponse(false, '没有需要更新的字段');
        }
        
        $updateParams[] = $userId;
        
        $sql = "UPDATE users SET " . implode(', ', $updateFields) . " WHERE id = ?";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($updateParams);
        
        // 记录操作日志
        logOperation($currentUserId, 'update_profile', 'user', $userId, [
            'fields_updated' => $updateFields,
            'display_name' => $displayName,
            'avatar_url' => $avatarUrl,
            'bio' => $bio
        ]);
        
        jsonResponse(true, '用户资料更新成功');
        
    } catch (Exception $e) {
        jsonResponse(false, '更新用户资料失败: ' . $e->getMessage());
    }
}
?>