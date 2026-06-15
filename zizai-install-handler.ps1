# zizai-install:// URL Protocol Handler
# 接收 Base64 编码的网络路径，解码后直接启动
param([string]$url)
$encoded = $url -replace '^zizai-install://', ''
try {
    $exePath = [System.Text.Encoding]::UTF8.GetString([System.Convert]::FromBase64String($encoded))
    if (Test-Path $exePath) {
        $dir = Split-Path $exePath -Parent
        Start-Process -FilePath "cmd.exe" -ArgumentList "/c cd /d `"$dir`" && start `"`" `"$exePath`"" -WindowStyle Hidden
    } else {
        [System.Windows.Forms.MessageBox]::Show("安装程序未找到：`n$exePath", "自在空间 - 在线安装", "OK", "Error")
    }
} catch {
    [System.Windows.Forms.MessageBox]::Show("启动失败：$($_.Exception.Message)", "自在空间 - 在线安装", "OK", "Error")
}
