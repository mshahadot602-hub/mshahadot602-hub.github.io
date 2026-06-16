@echo off
chcp 65001 >nul
title 自在空间用户系统 - 一键部署脚本
color 0A

echo.
echo ============================================
echo   自在空间用户系统 - 一键部署脚本
echo ============================================
echo.

REM 检查是否以管理员身份运行
net session >nul 2>&1
if %errorLevel% neq 0 (
    echo [错误] 请以管理员身份运行此脚本！
    echo 右键点击脚本 -> 以管理员身份运行
    pause
    exit /b 1
)

REM 设置变量
set "PROJECT_ROOT=C:\zizaihtml\html\user-system"
set "UPLOAD_ROOT=C:\zizaihtml\html\wwwroot\uploads"
set "DB_SCRIPT=%PROJECT_ROOT%\deploy\database-init.sql"

echo [1/6] 检查项目目录...
if not exist "%PROJECT_ROOT%" (
    echo [错误] 项目目录不存在: %PROJECT_ROOT%
    pause
    exit /b 1
)
echo [✓] 项目目录检查通过

echo.
echo [2/6] 创建上传目录结构...
if not exist "%UPLOAD_ROOT%" (
    mkdir "%UPLOAD_ROOT%"
)
if not exist "%UPLOAD_ROOT%\avatars" (
    mkdir "%UPLOAD_ROOT%\avatars"
)
if not exist "%UPLOAD_ROOT%\gallery" (
    mkdir "%UPLOAD_ROOT%\gallery"
)
if not exist "%UPLOAD_ROOT%\cloud" (
    mkdir "%UPLOAD_ROOT%\cloud"
)
echo [✓] 上传目录创建完成

echo.
echo [3/6] 设置目录权限...
REM 设置上传目录权限（IIS用户）
icacls "%UPLOAD_ROOT%" /grant "IIS_IUSRS:(OI)(CI)F" /T
icacls "%UPLOAD_ROOT%" /grant "Users:(OI)(CI)RX" /T
echo [✓] 目录权限设置完成

echo.
echo [4/6] 检查配置文件...
set "CONFIG_FILE=%PROJECT_ROOT%\api\config.php"
if not exist "%CONFIG_FILE%" (
    echo [错误] 配置文件不存在: %CONFIG_FILE%
    pause
    exit /b 1
)

REM 备份原始配置文件
if exist "%CONFIG_FILE%.backup" (
    del "%CONFIG_FILE%.backup"
)
copy "%CONFIG_FILE%" "%CONFIG_FILE%.backup" >nul
echo [✓] 配置文件备份完成

echo.
echo [5/6] 显示数据库配置信息...
echo.
echo ============================================
echo   数据库配置信息
echo ============================================
echo.
echo 请按照以下步骤配置数据库：
echo.
echo 1. 登录宝塔面板
echo 2. 进入"数据库" -> "MySQL"
echo 3. 点击"添加数据库"
echo 4. 填写以下信息：
echo    - 数据库名: zizai_user_system
echo    - 用户名: zizai_admin
echo    - 密码: 设置一个安全密码
echo    - 字符集: utf8mb4
echo 5. 点击"提交"
echo 6. 点击刚创建的数据库
echo 7. 选择"导入"
echo 8. 上传文件: %DB_SCRIPT%
echo 9. 点击"执行"
echo.
echo ============================================
echo.

echo [6/6] 显示API配置信息...
echo.
echo ============================================
echo   API配置文件修改
echo ============================================
echo.
echo 请修改以下配置文件：
echo 文件路径: %CONFIG_FILE%
echo.
echo 需要修改的内容：
echo.
echo 1. 数据库连接配置：
echo    define('DB_HOST', '127.0.0.1');
echo    define('DB_NAME', 'zizai_user系统');
echo    define('DB_USER', 'zizai_admin');
echo    define('DB_PASS', '你的数据库密码');
echo.
echo 2. JWT密钥配置：
echo    define('JWT_SECRET', '生成一个随机的复杂字符串');
echo    建议：使用 openssl rand -base64 32 生成
echo.
echo 3. 文件上传配置：
echo    define('UPLOAD_DIR', 'C:/zizaihtml/html/wwwroot/uploads/');
echo.
echo ============================================
echo.

echo.
echo ============================================
echo   部署完成！
echo ============================================
echo.
echo 下一步操作：
echo.
echo 1. 按照上面的说明配置数据库
echo 2. 修改API配置文件
echo 3. 测试系统访问
echo.
echo 测试地址：
echo   - 登录页面: http://你的域名/user-system/login.html
echo   - 管理员账户: xiaoxin / zizai123
echo.
echo 详细部署说明请查看：
echo   %PROJECT_ROOT%\deploy\README.md
echo.
echo ============================================
echo.

REM 创建测试脚本
echo 创建测试脚本...
set "TEST_SCRIPT=%PROJECT_ROOT%\deploy\测试系统.bat"
(
echo @echo off
echo chcp 65001 ^>nul
echo title 自在空间用户系统 - 测试脚本
echo color 0B
echo.
echo echo 正在测试系统连接...
echo echo.
echo 
echo REM 测试数据库连接
echo echo [1/4] 测试数据库配置...
echo echo 请确保已按照部署说明配置数据库
echo echo.
echo 
echo REM 测试文件权限
echo echo [2/4] 测试文件权限...
echo if exist "%UPLOAD_ROOT%\test.txt" (
echo     del "%UPLOAD_ROOT%\test.txt"
echo )
echo echo test ^> "%UPLOAD_ROOT%\test.txt"
echo if exist "%UPLOAD_ROOT%\test.txt" (
echo     echo [✓] 文件写入权限正常
echo     del "%UPLOAD_ROOT%\test.txt"
echo ) else (
echo     echo [错误] 文件写入权限失败
echo )
echo.
echo 
echo REM 测试API访问
echo echo [3/4] 测试API访问...
echo echo 请手动访问以下地址测试：
echo echo   http://你的域名/user-system/api/config.php
echo echo 应该显示"API配置正常"
echo.
echo 
echo REM 测试页面访问
echo echo [4/4] 测试页面访问...
echo echo 请手动访问以下地址测试：
echo echo   1. http://你的域名/user-system/login.html
echo echo   2. http://你的域名/user-system/register.html
echo echo   3. 使用 xiaoxin / zizai123 登录
echo echo   4. 访问个人中心和管理员后台
echo.
echo 
echo echo ============================================
echo echo   测试完成！
echo echo ============================================
echo echo.
echo echo 如果遇到问题，请检查：
echo echo   1. 数据库是否配置正确
echo echo   2. 配置文件是否修改正确
echo echo   3. 文件权限是否设置正确
echo echo   4. 查看错误日志：%PROJECT_ROOT%\api\error.log
echo echo.
echo pause
) > "%TEST_SCRIPT%"

echo [✓] 测试脚本创建完成: %TEST_SCRIPT%
echo.
echo 运行 "测试系统.bat" 进行系统测试
echo.

pause
exit /b 0