/**
 * 自在空间用户系统 - API客户端
 * 提供统一的API调用接口
 */

const API_BASE_URL = '/user-system/api';

// 存储用户信息和token
let userInfo = JSON.parse(localStorage.getItem('user_info') || '{}');
let authToken = localStorage.getItem('auth_token') || '';

/**
 * 通用API请求函数
 */
async function apiRequest(endpoint, method = 'GET', data = null, headers = {}) {
    const url = `${API_BASE_URL}/${endpoint}`;
    
    const defaultHeaders = {
        'Content-Type': 'application/json',
        'Accept': 'application/json'
    };
    
    // 添加认证token
    if (authToken) {
        defaultHeaders['Authorization'] = `Bearer ${authToken}`;
    }
    
    const config = {
        method,
        headers: { ...defaultHeaders, ...headers },
        credentials: 'include'
    };
    
    if (data) {
        if (data instanceof FormData) {
            // 如果是FormData，删除Content-Type让浏览器自动设置
            delete config.headers['Content-Type'];
            config.body = data;
        } else {
            config.body = JSON.stringify(data);
        }
    }
    
    try {
        const response = await fetch(url, config);
        const result = await response.json();
        
        if (!response.ok) {
            // 处理HTTP错误
            if (response.status === 401) {
                // 未授权，清除本地存储并跳转到登录页
                clearAuth();
                window.location.href = '/user-system/login.html';
                throw new Error('登录已过期，请重新登录');
            } else if (response.status === 403) {
                throw new Error('没有权限执行此操作');
            } else if (response.status === 404) {
                throw new Error('请求的资源不存在');
            } else if (response.status === 500) {
                throw new Error('服务器内部错误');
            }
            
            throw new Error(result.message || `请求失败: ${response.status}`);
        }
        
        if (!result.success) {
            throw new Error(result.message || '请求失败');
        }
        
        return result;
    } catch (error) {
        console.error('API请求失败:', error);
        throw error;
    }
}

/**
 * 用户认证相关API
 */
const authApi = {
    /**
     * 用户登录
     * @param {string} username - 用户名
     * @param {string} password - 密码
     */
    async login(username, password) {
        const result = await apiRequest('user.php?path=login', 'POST', {
            username,
            password
        });
        
        if (result.success && result.data && result.data.token) {
            // 保存用户信息和token
            setAuth(result.data.token, result.data.user);
        }
        
        return result;
    },
    
    /**
     * 用户注册
     * @param {Object} data - 注册信息
     */
    async register(data) {
        return await apiRequest('user.php?path=register', 'POST', data);
    },
    
    /**
     * 获取当前用户信息
     */
    async getCurrentUser() {
        if (!authToken) {
            return null;
        }
        
        try {
            const result = await apiRequest('user.php?path=profile');
            if (result.success) {
                userInfo = result.data;
                localStorage.setItem('user_info', JSON.stringify(userInfo));
                return userInfo;
            }
        } catch (error) {
            console.error('获取用户信息失败:', error);
            clearAuth();
        }
        
        return null;
    },
    
    /**
     * 更新用户信息
     * @param {Object} data - 要更新的用户信息
     */
    async updateProfile(data) {
        const result = await apiRequest('user.php?path=profile', 'PUT', data);
        
        if (result.success && result.data) {
            // 更新本地存储的用户信息
            userInfo = { ...userInfo, ...result.data };
            localStorage.setItem('user_info', JSON.stringify(userInfo));
        }
        
        return result;
    },
    
    /**
     * 修改密码
     * @param {string} currentPassword - 当前密码
     * @param {string} newPassword - 新密码
     */
    async changePassword(currentPassword, newPassword) {
        return await apiRequest('user.php?path=change-password', 'POST', {
            current_password: currentPassword,
            new_password: newPassword
        });
    },
    
    /**
     * 用户登出
     */
    logout() {
        clearAuth();
        window.location.href = '/user-system/login.html';
    }
};

/**
 * 管理员相关API
 */
const adminApi = {
    /**
     * 获取用户列表
     * @param {Object} params - 查询参数
     */
    async getUsers(params = {}) {
        const queryString = new URLSearchParams(params).toString();
        return await apiRequest(`admin.php?path=users&${queryString}`);
    },
    
    /**
     * 获取注册申请列表
     * @param {Object} params - 查询参数
     */
    async getRegistrationRequests(params = {}) {
        const queryString = new URLSearchParams(params).toString();
        return await apiRequest(`admin.php?path=registration-requests&${queryString}`);
    },
    
    /**
     * 批准注册申请
     * @param {number} requestId - 申请ID
     */
    async approveRegistration(requestId) {
        return await apiRequest(`admin.php?path=registration-requests/${requestId}/approve`, 'POST');
    },
    
    /**
     * 拒绝注册申请
     * @param {number} requestId - 申请ID
     * @param {string} reason - 拒绝原因
     */
    async rejectRegistration(requestId, reason = '') {
        return await apiRequest(`admin.php?path=registration-requests/${requestId}/reject`, 'POST', {
            reason
        });
    },
    
    /**
     * 批准用户
     * @param {number} userId - 用户ID
     */
    async approveUser(userId) {
        return await apiRequest(`admin.php?path=users/${userId}/approve`, 'POST');
    },
    
    /**
     * 更新用户存储空间
     * @param {number} userId - 用户ID
     * @param {number} storageLimit - 存储空间限制（字节）
     */
    async updateUserStorage(userId, storageLimit) {
        return await apiRequest(`admin.php?path=users/${userId}/storage`, 'PUT', {
            storage_limit: storageLimit
        });
    },
    
    /**
     * 删除用户
     * @param {number} userId - 用户ID
     */
    async deleteUser(userId) {
        return await apiRequest(`admin.php?path=users/${userId}`, 'DELETE');
    },
    
    /**
     * 获取系统统计
     */
    async getStatistics() {
        return await apiRequest('admin.php?path=statistics');
    }
};

/**
 * 作品画廊相关API
 */
const galleryApi = {
    /**
     * 获取作品列表
     * @param {Object} params - 查询参数
     */
    async getGalleryItems(params = {}) {
        const queryString = new URLSearchParams(params).toString();
        return await apiRequest(`gallery.php?${queryString}`);
    },
    
    /**
     * 获取作品详情
     * @param {number} itemId - 作品ID
     */
    async getGalleryItem(itemId) {
        return await apiRequest(`gallery.php?path=${itemId}`);
    },
    
    /**
     * 上传作品
     * @param {FormData} formData - 包含作品数据的FormData
     */
    async uploadGalleryItem(formData) {
        return await apiRequest('gallery.php', 'POST', formData);
    },
    
    /**
     * 更新作品
     * @param {number} itemId - 作品ID
     * @param {Object} data - 要更新的数据
     */
    async updateGalleryItem(itemId, data) {
        return await apiRequest(`gallery.php?path=${itemId}`, 'PUT', data);
    },
    
    /**
     * 删除作品
     * @param {number} itemId - 作品ID
     */
    async deleteGalleryItem(itemId) {
        return await apiRequest(`gallery.php?path=${itemId}`, 'DELETE');
    },
    
    /**
     * 点赞/取消点赞作品
     * @param {number} itemId - 作品ID
     */
    async likeGalleryItem(itemId) {
        return await apiRequest(`gallery.php?path=${itemId}/like`, 'PUT');
    },
    
    /**
     * 获取分类统计
     */
    async getCategories() {
        return await apiRequest('gallery.php?path=categories');
    },
    
    /**
     * 获取作品统计（管理员）
     */
    async getGalleryStatistics() {
        return await apiRequest('gallery.php?path=statistics');
    }
};

/**
 * 云盘文件相关API
 */
const cloudApi = {
    /**
     * 获取文件列表
     * @param {Object} params - 查询参数
     */
    async getFiles(params = {}) {
        const queryString = new URLSearchParams(params).toString();
        return await apiRequest(`cloud.php?path=files&${queryString}`);
    },
    
    /**
     * 获取存储信息
     */
    async getStorageInfo() {
        return await apiRequest('cloud.php?path=storage-info');
    },
    
    /**
     * 上传文件
     * @param {File} file - 要上传的文件
     * @param {boolean} isPublic - 是否公开
     */
    async uploadFile(file, isPublic = false) {
        const formData = new FormData();
        formData.append('file', file);
        formData.append('is_public', isPublic ? '1' : '0');
        
        return await apiRequest('cloud.php?path=upload', 'POST', formData);
    },
    
    /**
     * 下载文件
     * @param {number} fileId - 文件ID
     */
    async downloadFile(fileId) {
        // 直接打开下载链接
        const url = `${API_BASE_URL}/cloud.php?path=download/${fileId}`;
        const link = document.createElement('a');
        link.href = url;
        link.download = '';
        document.body.appendChild(link);
        link.click();
        document.body.removeChild(link);
    },
    
    /**
     * 更新文件可见性
     * @param {number} fileId - 文件ID
     * @param {boolean} isPublic - 是否公开
     */
    async updateFileVisibility(fileId, isPublic) {
        return await apiRequest(`cloud.php?path=files/${fileId}/visibility`, 'PUT', {
            is_public: isPublic
        });
    },
    
    /**
     * 删除文件
     * @param {number} fileId - 文件ID
     */
    async deleteFile(fileId) {
        return await apiRequest(`cloud.php?path=files/${fileId}`, 'DELETE');
    },
    
    /**
     * 获取云盘统计（管理员）
     */
    async getCloudStatistics() {
        return await apiRequest('cloud.php?path=admin/statistics');
    }
};

/**
 * 工具函数
 */

/**
 * 设置认证信息
 * @param {string} token - JWT token
 * @param {Object} user - 用户信息
 */
function setAuth(token, user) {
    authToken = token;
    userInfo = user;
    
    localStorage.setItem('auth_token', token);
    localStorage.setItem('user_info', JSON.stringify(user));
    
    // 设置全局变量
    window.userInfo = user;
    window.authToken = token;
}

/**
 * 清除认证信息
 */
function clearAuth() {
    authToken = '';
    userInfo = {};
    
    localStorage.removeItem('auth_token');
    localStorage.removeItem('user_info');
    
    delete window.userInfo;
    delete window.authToken;
}

/**
 * 检查用户是否已登录
 * @returns {boolean}
 */
function isLoggedIn() {
    return !!authToken;
}

/**
 * 检查用户是否是管理员
 * @returns {boolean}
 */
function isAdmin() {
    return userInfo && userInfo.is_admin === true;
}

/**
 * 检查用户是否已批准
 * @returns {boolean}
 */
function isApproved() {
    return userInfo && userInfo.is_approved === true;
}

/**
 * 格式化文件大小
 * @param {number} bytes - 字节数
 * @returns {string}
 */
function formatFileSize(bytes) {
    if (bytes === 0) return '0 B';
    
    const k = 1024;
    const sizes = ['B', 'KB', 'MB', 'GB', 'TB'];
    const i = Math.floor(Math.log(bytes) / Math.log(k));
    
    return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
}

/**
 * 格式化日期时间
 * @param {string} dateString - 日期字符串
 * @returns {string}
 */
function formatDateTime(dateString) {
    if (!dateString) return '';
    
    const date = new Date(dateString);
    const now = new Date();
    const diffMs = now - date;
    const diffMins = Math.floor(diffMs / 60000);
    const diffHours = Math.floor(diffMs / 3600000);
    const diffDays = Math.floor(diffMs / 86400000);
    
    if (diffMins < 1) {
        return '刚刚';
    } else if (diffMins < 60) {
        return `${diffMins}分钟前`;
    } else if (diffHours < 24) {
        return `${diffHours}小时前`;
    } else if (diffDays < 7) {
        return `${diffDays}天前`;
    } else {
        return date.toLocaleDateString('zh-CN', {
            year: 'numeric',
            month: 'short',
            day: 'numeric'
        });
    }
}

/**
 * 显示消息提示
 * @param {string} message - 消息内容
 * @param {string} type - 消息类型：success, error, warning, info
 * @param {number} duration - 显示时长（毫秒）
 */
function showMessage(message, type = 'info', duration = 3000) {
    // 移除现有的消息
    const existingMessages = document.querySelectorAll('.message-toast');
    existingMessages.forEach(msg => msg.remove());
    
    // 创建消息元素
    const messageEl = document.createElement('div');
    messageEl.className = `message-toast message-${type}`;
    messageEl.innerHTML = `
        <div class="message-content">${message}</div>
        <button class="message-close">&times;</button>
    `;
    
    // 添加到页面
    document.body.appendChild(messageEl);
    
    // 添加关闭按钮事件
    const closeBtn = messageEl.querySelector('.message-close');
    closeBtn.addEventListener('click', () => {
        messageEl.remove();
    });
    
    // 自动关闭
    if (duration > 0) {
        setTimeout(() => {
            if (messageEl.parentNode) {
                messageEl.remove();
            }
        }, duration);
    }
    
    // 添加样式
    if (!document.querySelector('#message-styles')) {
        const styleEl = document.createElement('style');
        styleEl.id = 'message-styles';
        styleEl.textContent = `
            .message-toast {
                position: fixed;
                top: 20px;
                right: 20px;
                padding: 12px 20px;
                border-radius: 4px;
                color: white;
                box-shadow: 0 4px 12px rgba(0,0,0,0.15);
                z-index: 9999;
                display: flex;
                align-items: center;
                justify-content: space-between;
                min-width: 300px;
                max-width: 500px;
                animation: slideIn 0.3s ease;
            }
            
            .message-success {
                background-color: #4caf50;
            }
            
            .message-error {
                background-color: #f44336;
            }
            
            .message-warning {
                background-color: #ff9800;
            }
            
            .message-info {
                background-color: #2196f3;
            }
            
            .message-content {
                flex: 1;
                margin-right: 10px;
            }
            
            .message-close {
                background: none;
                border: none;
                color: white;
                font-size: 20px;
                cursor: pointer;
                padding: 0;
                width: 24px;
                height: 24px;
                display: flex;
                align-items: center;
                justify-content: center;
                opacity: 0.8;
            }
            
            .message-close:hover {
                opacity: 1;
            }
            
            @keyframes slideIn {
                from {
                    transform: translateX(100%);
                    opacity: 0;
                }
                to {
                    transform: translateX(0);
                    opacity: 1;
                }
            }
        `;
        document.head.appendChild(styleEl);
    }
}

/**
 * 显示确认对话框
 * @param {string} message - 确认消息
 * @param {string} title - 对话框标题
 * @returns {Promise<boolean>}
 */
function showConfirm(message, title = '确认操作') {
    return new Promise((resolve) => {
        // 创建对话框
        const dialogEl = document.createElement('div');
        dialogEl.className = 'confirm-dialog';
        dialogEl.innerHTML = `
            <div class="confirm-overlay"></div>
            <div class="confirm-content">
                <div class="confirm-header">
                    <h3>${title}</h3>
                    <button class="confirm-close">&times;</button>
                </div>
                <div class="confirm-body">${message}</div>
                <div class="confirm-footer">
                    <button class="confirm-btn confirm-cancel">取消</button>
                    <button class="confirm-btn confirm-ok">确定</button>
                </div>
            </div>
        `;
        
        document.body.appendChild(dialogEl);
        
        // 添加事件监听
        const closeBtn = dialogEl.querySelector('.confirm-close');
        const cancelBtn = dialogEl.querySelector('.confirm-cancel');
        const okBtn = dialogEl.querySelector('.confirm-ok');
        const overlay = dialogEl.querySelector('.confirm-overlay');
        
        const closeDialog = (result) => {
            dialogEl.remove();
            resolve(result);
        };
        
        closeBtn.addEventListener('click', () => closeDialog(false));
        cancelBtn.addEventListener('click', () => closeDialog(false));
        okBtn.addEventListener('click', () => closeDialog(true));
        overlay.addEventListener('click', () => closeDialog(false));
        
        // 添加样式
        if (!document.querySelector('#confirm-styles')) {
            const styleEl = document.createElement('style');
            styleEl.id = 'confirm-styles';
            styleEl.textContent = `
                .confirm-dialog {
                    position: fixed;
                    top: 0;
                    left: 0;
                    right: 0;
                    bottom: 0;
                    z-index: 10000;
                }
                
                .confirm-overlay {
                    position: absolute;
                    top: 0;
                    left: 0;
                    right: 0;
                    bottom: 0;
                    background: rgba(0,0,0,0.5);
                }
                
                .confirm-content {
                    position: absolute;
                    top: 50%;
                    left: 50%;
                    transform: translate(-50%, -50%);
                    background: white;
                    border-radius: 8px;
                    box-shadow: 0 8px 32px rgba(0,0,0,0.3);
                    min-width: 400px;
                    max-width: 500px;
                    animation: dialogFadeIn 0.3s ease;
                }
                
                .confirm-header {
                    padding: 20px 20px 10px;
                    border-bottom: 1px solid #eee;
                    display: flex;
                    align-items: center;
                    justify-content: space-between;
                }
                
                .confirm-header h3 {
                    margin: 0;
                    font-size: 18px;
                    font-weight: 600;
                }
                
                .confirm-close {
                    background: none;
                    border: none;
                    font-size: 24px;
                    cursor: pointer;
                    color: #999;
                    padding: 0;
                    width: 30px;
                    height: 30px;
                    display: flex;
                    align-items: center;
                    justify-content: center;
                }
                
                .confirm-close:hover {
                    color: #333;
                }
                
                .confirm-body {
                    padding: 20px;
                    font-size: 16px;
                    line-height: 1.5;
                }
                
                .confirm-footer {
                    padding: 10px 20px 20px;
                    text-align: right;
                    border-top: 1px solid #eee;
                }
                
                .confirm-btn {
                    padding: 8px 20px;
                    border-radius: 4px;
                    border: none;
                    cursor: pointer;
                    font-size: 14px;
                    margin-left: 10px;
                    transition: all 0.2s;
                }
                
                .confirm-cancel {
                    background: #f5f5f5;
                    color: #333;
                }
                
                .confirm-cancel:hover {
                    background: #e5e5e5;
                }
                
                .confirm-ok {
                    background: #2196f3;
                    color: white;
                }
                
                .confirm-ok:hover {
                    background: #0d8bf2;
                }
                
                @keyframes dialogFadeIn {
                    from {
                        opacity: 0;
                        transform: translate(-50%, -60%);
                    }
                    to {
                        opacity: 1;
                        transform: translate(-50%, -50%);
                    }
                }
            `;
            document.head.appendChild(styleEl);
        }
    });
}

/**
 * 初始化页面
 * 检查登录状态，设置用户信息
 */
async function initPage() {
    // 检查登录状态
    if (!isLoggedIn()) {
        // 如果不是登录页面，跳转到登录页
        if (!window.location.pathname.includes('/login.html') && 
            !window.location.pathname.includes('/register.html')) {
            window.location.href = '/user-system/login.html';
        }
        return;
    }
    
    // 获取最新用户信息
    try {
        const user = await authApi.getCurrentUser();
        if (!user) {
            // 用户信息获取失败，清除认证信息
            clearAuth();
            window.location.href = '/user-system/login.html';
            return;
        }
        
        // 更新页面上的用户信息显示
        updateUserDisplay(user);
        
        // 检查权限
        if (window.location.pathname.includes('/admin/') && !isAdmin()) {
            showMessage('需要管理员权限才能访问此页面', 'error');
            setTimeout(() => {
                window.location.href = '/user-system/profile.html';
            }, 2000);
            return;
        }
        
    } catch (error) {
        console.error('初始化页面失败:', error);
        clearAuth();
        window.location.href = '/user-system/login.html';
    }
}

/**
 * 更新页面上的用户信息显示
 * @param {Object} user - 用户信息
 */
function updateUserDisplay(user) {
    // 更新导航栏用户信息
    const userDisplayElements = document.querySelectorAll('.user-display');
    userDisplayElements.forEach(el => {
        if (el.dataset.field === 'username') {
            el.textContent = user.username;
        } else if (el.dataset.field === 'display_name') {
            el.textContent = user.display_name || user.username;
        } else if (el.dataset.field === 'avatar') {
            if (user.avatar_url) {
                el.src = user.avatar_url;
            }
        }
    });
    
    // 更新管理员标识
    const adminBadges = document.querySelectorAll('.admin-badge');
    adminBadges.forEach(badge => {
        badge.style.display = user.is_admin ? 'inline-block' : 'none';
    });
    
    // 更新存储空间显示
    const storageElements = document.querySelectorAll('.storage-display');
    storageElements.forEach(el => {
        if (el.dataset.field === 'used') {
            el.textContent = formatFileSize(user.used_storage || 0);
        } else if (el.dataset.field === 'limit') {
            el.textContent = formatFileSize(user.storage_limit || 0);
        } else if (el.dataset.field === 'percentage') {
            const percentage = user.storage_limit > 0 
                ? Math.round((user.used_storage || 0) * 100 / user.storage_limit)
                : 0;
            el.textContent = `${percentage}%`;
            
            // 更新进度条
            const progressBar = el.closest('.storage-progress')?.querySelector('.progress-bar');
            if (progressBar) {
                progressBar.style.width = `${percentage}%`;
                progressBar.className = `progress-bar ${
                    percentage > 90 ? 'progress-danger' :
                    percentage > 70 ? 'progress-warning' :
                    'progress-success'
                }`;
            }
        }
    });
}

// 导出API对象和工具函数
window.API = {
    auth: authApi,
    admin: adminApi,
    gallery: galleryApi,
    cloud: cloudApi,
    
    // 工具函数
    isLoggedIn,
    isAdmin,
    isApproved,
    formatFileSize,
    formatDateTime,
    showMessage,
    showConfirm,
    initPage
};

// 页面加载完成后初始化
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', () => {
        // 延迟初始化，避免阻塞页面加载
        setTimeout(initPage, 100);
    });
} else {
    setTimeout(initPage, 100);
}