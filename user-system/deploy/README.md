# 自在空间用户系统 - 部署说明

## 目录结构

```
C:\zizaihtml\html\user-system\
├── api/                    # API接口文件
│   ├── config.php         # 数据库配置和工具函数
│   ├── user.php           # 用户管理API
│   ├── admin.php          # 管理员API
│   ├── gallery.php        # 作品画廊API
│   └── cloud.php          # 云盘文件API
├── js/                    # JavaScript文件
│   └── api-client.js      # API客户端和工具函数
├── deploy/                # 部署文件
│   ├── database-init.sql  # 数据库初始化脚本
│   └── README.md          # 部署说明
├── login.html             # 登录页面
├── register.html          # 注册页面
├── profile.html           # 个人中心页面
└── admin.html             # 管理员后台页面
```

## 快速开始（3步完成部署）

### 第一步：初始化数据库

1. **登录宝塔面板**
   - 打开宝塔面板管理地址
   - 进入"数据库" -> "MySQL"

2. **创建数据库**
   - 点击"添加数据库"
   - 数据库名：`zizai_user_system`
   - 用户名：`zizai_admin`
   - 密码：设置一个安全密码
   - 字符集：`utf8mb4`

3. **导入初始化脚本**
   - 点击刚创建的数据库
   - 选择"导入"
   - 上传 `database-init.sql` 文件
   - 点击"执行"

4. **验证数据库**
   ```sql
   USE zizai_user_system;
   SHOW TABLES;
   SELECT username, email, is_admin FROM users;
   ```

### 第二步：配置API连接

1. **编辑配置文件**
   - 打开 `api/config.php`
   - 修改数据库连接信息：
   ```php
   define('DB_HOST', '127.0.0.1');
   define('DB_NAME', 'zizai_user_system');
   define('DB_USER', 'zizai_admin');
   define('DB_PASS', '你的数据库密码');
   ```

2. **设置JWT密钥**
   ```php
   define('JWT_SECRET', '生成一个随机的复杂字符串');
   ```

3. **创建上传目录**
   ```
   C:\zizaihtml\html\wwwroot\uploads\
   ├── avatars/     # 用户头像
   ├── gallery/     # 作品图片
   └── cloud/       # 云盘文件
   ```
   
   确保这些目录有写入权限。

### 第三步：测试系统

1. **访问登录页面**
   ```
   http://你的域名/user-system/login.html
   ```

2. **使用管理员账户登录**
   - 用户名：`xiaoxin`
   - 密码：`zizai123`

3. **测试功能**
   - [ ] 管理员登录
   - [ ] 用户注册
   - [ ] 个人资料管理
   - [ ] 作品上传
   - [ ] 文件上传下载
   - [ ] 管理员后台

## 详细配置

### API接口说明

系统提供39个API接口，分为4个模块：

#### 用户模块 (user.php)
| 方法 | 路径 | 功能 | 权限 |
|------|------|------|------|
| POST | user.php?path=login | 用户登录 | 公开 |
| POST | user.php?path=register | 用户注册 | 公开 |
| GET | user.php?path=profile | 获取当前用户信息 | 已登录 |
| PUT | user.php?path=profile | 更新用户信息 | 已登录 |
| POST | user.php?path=change-password | 修改密码 | 已登录 |

#### 管理员模块 (admin.php)
| 方法 | 路径 | 功能 | 权限 |
|------|------|------|------|
| GET | admin.php?path=users | 获取用户列表 | 管理员 |
| GET | admin.php?path=registration-requests | 获取注册申请 | 管理员 |
| POST | admin.php?path=registration-requests/{id}/approve | 批准注册 | 管理员 |
| POST | admin.php?path=registration-requests/{id}/reject | 拒绝注册 | 管理员 |
| POST | admin.php?path=users/{id}/approve | 批准用户 | 管理员 |
| PUT | admin.php?path=users/{id}/storage | 更新存储空间 | 管理员 |
| DELETE | admin.php?path=users/{id} | 删除用户 | 管理员 |
| GET | admin.php?path=statistics | 系统统计 | 管理员 |

#### 作品画廊模块 (gallery.php)
| 方法 | 路径 | 功能 | 权限 |
|------|------|------|------|
| GET | gallery.php | 获取作品列表 | 公开 |
| GET | gallery.php?path={id} | 获取作品详情 | 公开 |
| POST | gallery.php | 上传作品 | 已登录 |
| PUT | gallery.php?path={id} | 更新作品 | 上传者/管理员 |
| DELETE | gallery.php?path={id} | 删除作品 | 上传者/管理员 |
| PUT | gallery.php?path={id}/like | 点赞/取消点赞 | 已登录 |
| GET | gallery.php?path=categories | 获取分类统计 | 公开 |
| GET | gallery.php?path=statistics | 作品统计 | 管理员 |

#### 云盘模块 (cloud.php)
| 方法 | 路径 | 功能 | 权限 |
|------|------|------|------|
| GET | cloud.php?path=files | 获取文件列表 | 已登录 |
| GET | cloud.php?path=storage-info | 获取存储信息 | 已登录 |
| POST | cloud.php?path=upload | 上传文件 | 已登录 |
| GET | cloud.php?path=download/{id} | 下载文件 | 文件所有者/公开 |
| PUT | cloud.php?path=files/{id}/visibility | 更新可见性 | 文件所有者/管理员 |
| DELETE | cloud.php?path=files/{id} | 删除文件 | 文件所有者/管理员 |
| GET | cloud.php?path=admin/statistics | 云盘统计 | 管理员 |

### 前端页面说明

#### login.html - 登录页面
- 支持用户名/邮箱登录
- 密码显示/隐藏切换
- 记住密码功能
- 跳转到注册页面

#### register.html - 注册页面
- 用户名可用性检查
- 密码强度验证
- 邮箱格式验证
- 注册原因填写
- 实时表单验证

#### profile.html - 个人中心
- 用户信息展示
- 存储空间使用情况
- 最近活动记录
- 文件统计图表
- 账户设置

#### admin.html - 管理员后台
- 统计仪表板
- 用户管理（查看、批准、删除）
- 注册申请审核
- 作品管理
- 云盘管理
- 操作日志查看
- 系统设置

### 安全配置

1. **JWT认证配置**
   ```php
   define('JWT_SECRET', 'your-very-long-random-string');
   define('JWT_EXPIRATION', 86400); // 24小时
   ```

2. **CORS跨域配置**
   ```php
   define('ALLOWED_ORIGINS', 'https://yourdomain.com');
   ```

3. **文件上传限制**
   ```php
   define('MAX_FILE_SIZE', 2 * 1024 * 1024 * 1024); // 2GB
   define('ALLOWED_FILE_TYPES', [
       'image/jpeg', 'image/png', 'image/gif',
       'application/pdf', 'application/zip',
       'video/mp4', 'audio/mpeg'
   ]);
   ```

4. **密码加密**
   - 使用BCrypt加密算法
   - 密码强度要求：至少8位，包含大小写字母、数字和特殊字符
   - 盐值自动生成

### 宝塔面板配置建议

1. **PHP配置**
   - PHP版本：7.4+
   - 启用扩展：mysqli, json, mbstring, fileinfo
   - 上传限制：`upload_max_filesize = 2048M`
   - 执行时间：`max_execution_time = 300`

2. **Nginx配置**
   ```nginx
   location /user-system/ {
       try_files $uri $uri/ /user-system/index.html;
   }
   
   location /user-system/api/ {
       if (!-e $request_filename) {
           rewrite ^/user-system/api/(.*)$ /user-system/api/$1 last;
       }
   }
   
   # 文件上传大小限制
   client_max_body_size 2048M;
   ```

3. **MySQL配置**
   - 字符集：utf8mb4
   - 排序规则：utf8mb4_unicode_ci
   - 最大连接数：150
   - 查询缓存：启用

### 测试账号

| 类型 | 用户名 | 密码 | 权限 |
|------|--------|------|------|
| 管理员 | xiaoxin | zizai123 | 全部权限 |
| 普通用户 | user1 | zizai123 | 基本权限 |
| 普通用户 | user2 | zizai123 | 基本权限 |
| 待审核 | user3 | zizai123 | 等待审核 |

### 常见问题

**Q: 登录失败怎么办？**
A: 检查数据库连接配置是否正确，确保 `zizai_user_system` 数据库已创建并导入初始化脚本。

**Q: 文件上传失败？**
A: 检查 `wwwroot/uploads/` 目录是否存在且有写入权限，检查PHP的 `upload_max_filesize` 配置。

**Q: 404错误？**
A: 检查Nginx的伪静态配置，确保URL重写规则正确。

**Q: 数据库连接失败？**
A: 检查 `api/config.php` 中的数据库配置，确保主机、用户名、密码、数据库名正确。

**Q: 权限不足？**
A: 使用管理员账户 `xiaoxin/zizai123` 登录，管理员拥有所有权限。

### 性能优化建议

1. **数据库优化**
   - 为常用查询字段创建索引
   - 定期清理操作日志
   - 启用查询缓存

2. **文件优化**
   - 使用CDN加速静态文件
   - 图片上传时自动压缩
   - 大文件分片上传

3. **缓存优化**
   - API响应缓存
   - 会话缓存
   - 页面缓存

### 备份建议

1. **数据库备份**
   ```
   宝塔面板 -> 数据库 -> 备份 -> 设置定时备份
   建议：每天凌晨自动备份
   ```

2. **文件备份**
   ```
   宝塔面板 -> 文件 -> 备份 -> 设置定时备份
   备份目录：/wwwroot/uploads/
   ```

### 系统更新

```
1. 备份数据库和文件
2. 更新API文件
3. 更新前端文件
4. 执行数据库迁移脚本（如果有）
5. 清理缓存
6. 测试功能
```

## 技术支持

如遇到问题，请检查：
1. 数据库是否初始化成功
2. 配置文件是否正确
3. 文件权限是否正确
4. PHP扩展是否启用
5. 查看错误日志：`api/error.log`

---

**部署完成！** 现在可以访问系统了：
- 登录页面：`http://你的域名/user-system/login.html`
- 管理员后台：使用 `xiaoxin/zizai123` 登录后访问管理页面