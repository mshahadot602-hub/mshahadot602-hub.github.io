<?php
/**
 * 自在空间 - 数据库配置
 * 宝塔面板 MySQL 连接配置
 */

// 数据库配置
define('DB_HOST', '127.0.0.1');
define('DB_PORT', '3306');
define('DB_NAME', 'zizai_system');
define('DB_USER', 'zizai_system');
define('DB_PASS', 'xa3fHp7R62HyFMps');
define('DB_CHARSET', 'utf8mb4');

// JWT 密钥
define('JWT_SECRET', 'zizai_space_jwt_secret_key_2024');
define('JWT_EXPIRY', 86400 * 7); // 7天

// 文件上传配置
define('UPLOAD_DIR', __DIR__ . '/../uploads/');
define('GALLERY_DIR', UPLOAD_DIR . 'gallery/');
define('CLOUD_DIR', UPLOAD_DIR . 'cloud/');
define('MAX_FILE_SIZE', 2 * 1024 * 1024 * 1024); // 2GB
define('DEFAULT_STORAGE_LIMIT', 10 * 1024 * 1024 * 1024); // 10GB

// 允许的文件类型
define('ALLOWED_IMAGE_TYPES', ['image/jpeg', 'image/png', 'image/gif', 'image/webp']);
define('ALLOWED_FILE_TYPES', [
    'image/jpeg', 'image/png', 'image/gif', 'image/webp',
    'application/pdf', 'application/msword',
    'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    'application/vnd.ms-excel',
    'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    'application/vnd.ms-powerpoint',
    'application/vnd.openxmlformats-officedocument.presentationml.presentation',
    'text/plain', 'application/zip', 'application/x-rar-compressed',
    'audio/mpeg', 'video/mp4'
]);

/**
 * 获取数据库连接
 */
function getDB() {
    static $pdo = null;
    
    if ($pdo === null) {
        try {
            $dsn = "mysql:host=" . DB_HOST . ";port=" . DB_PORT . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
            $pdo = new PDO($dsn, DB_USER, DB_PASS, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false
            ]);
        } catch (PDOException $e) {
            jsonResponse(false, '数据库连接失败: ' . $e->getMessage(), null, 500);
            exit;
        }
    }
    
    return $pdo;
}

/**
 * 生成 JWT Token
 */
function generateJWT($userId, $username, $isAdmin) {
    $header = base64url_encode(json_encode(['alg' => 'HS256', 'typ' => 'JWT']));
    
    $payload = base64url_encode(json_encode([
        'user_id' => $userId,
        'username' => $username,
        'is_admin' => $isAdmin,
        'exp' => time() + JWT_EXPIRY,
        'iat' => time()
    ]));
    
    $signature = base64url_encode(hash_hmac('sha256', "$header.$payload", JWT_SECRET, true));
    
    return "$header.$payload.$signature";
}

/**
 * 验证 JWT Token
 */
function verifyJWT($token) {
    $parts = explode('.', $token);
    if (count($parts) !== 3) {
        return false;
    }
    
    [$header, $payload, $signature] = $parts;
    
    $validSignature = base64url_encode(hash_hmac('sha256', "$header.$payload", JWT_SECRET, true));
    
    if (!hash_equals($validSignature, $signature)) {
        return false;
    }
    
    $data = json_decode(base64url_decode($payload), true);
    
    if (!$data || !isset($data['exp']) || $data['exp'] < time()) {
        return false;
    }
    
    return $data;
}

/**
 * 获取当前用户ID
 */
function getCurrentUserId() {
    $headers = getallheaders();
    $authHeader = $headers['Authorization'] ?? '';
    
    if (empty($authHeader) || !preg_match('/^Bearer\s+(.+)$/', $authHeader, $matches)) {
        jsonResponse(false, '未授权访问', null, 401);
        exit;
    }
    
    $token = $matches[1];
    $payload = verifyJWT($token);
    
    if (!$payload) {
        jsonResponse(false, 'Token无效或已过期', null, 401);
        exit;
    }
    
    return $payload['user_id'];
}

/**
 * 检查是否为管理员
 */
function isAdmin() {
    $headers = getallheaders();
    $authHeader = $headers['Authorization'] ?? '';
    
    if (empty($authHeader) || !preg_match('/^Bearer\s+(.+)$/', $authHeader, $matches)) {
        return false;
    }
    
    $token = $matches[1];
    $payload = verifyJWT($token);
    
    return $payload && isset($payload['is_admin']) && $payload['is_admin'];
}

/**
 * 要求管理员权限
 */
function requireAdmin() {
    if (!isAdmin()) {
        jsonResponse(false, '需要管理员权限', null, 403);
        exit;
    }
}

/**
 * 记录操作日志
 */
function logOperation($userId, $actionType, $targetType, $targetId = null, $details = null) {
    try {
        $pdo = getDB();
        $stmt = $pdo->prepare("
            INSERT INTO operation_logs (user_id, action_type, target_type, target_id, details, ip_address, user_agent)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ");
        
        $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
        $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
        $detailsJson = $details ? json_encode($details, JSON_UNESCAPED_UNICODE) : null;
        
        $stmt->execute([$userId, $actionType, $targetType, $targetId, $detailsJson, $ip, $ua]);
    } catch (Exception $e) {
        // 日志记录失败不影响主流程
    }
}

/**
 * JSON 响应
 */
function jsonResponse($success, $message = '', $data = null, $httpCode = 200) {
    http_response_code($httpCode);
    header('Content-Type: application/json; charset=utf-8');
    
    $response = [
        'success' => $success,
        'message' => $message
    ];
    
    if ($data !== null) {
        $response['data'] = $data;
    }
    
    echo json_encode($response, JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * 获取请求体 JSON
 */
function getJsonInput() {
    $input = file_get_contents('php://input');
    return json_decode($input, true) ?: [];
}

/**
 * Base64 URL 编码
 */
function base64url_encode($data) {
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

/**
 * Base64 URL 解码
 */
function base64url_decode($data) {
    return base64_decode(strtr($data, '-_', '+/'));
}

/**
 * 密码哈希
 */
function hashPassword($password) {
    return password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
}

/**
 * 验证密码
 */
function verifyPassword($password, $hash) {
    return password_verify($password, $hash);
}

/**
 * 格式化文件大小
 */
function formatFileSize($bytes) {
    if ($bytes < 1024) return $bytes . ' B';
    if ($bytes < 1048576) return round($bytes / 1024, 1) . ' KB';
    if ($bytes < 1073741824) return round($bytes / 1048576, 1) . ' MB';
    return round($bytes / 1073741824, 1) . ' GB';
}

/**
 * 获取 MIME 类型
 */
function getMimeType($extension) {
    $mimeTypes = [
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png' => 'image/png',
        'gif' => 'image/gif',
        'webp' => 'image/webp',
        'pdf' => 'application/pdf',
        'doc' => 'application/msword',
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'xls' => 'application/vnd.ms-excel',
        'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'ppt' => 'application/vnd.ms-powerpoint',
        'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
        'txt' => 'text/plain',
        'zip' => 'application/zip',
        'rar' => 'application/x-rar-compressed',
        'mp3' => 'audio/mpeg',
        'mp4' => 'video/mp4',
    ];
    
    $ext = strtolower(ltrim($extension, '.'));
    return $mimeTypes[$ext] ?? 'application/octet-stream';
}

/**
 * 创建目录（如果不存在）
 */
function ensureDir($dir) {
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
}

// 确保上传目录存在
ensureDir(UPLOAD_DIR);
ensureDir(GALLERY_DIR);
ensureDir(CLOUD_DIR);
