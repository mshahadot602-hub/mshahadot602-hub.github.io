"""
自在网盘 - 后端服务 v3（含登录验证）
连接公司服务器 \\192.168.1.200 的四个共享目录
"""
import os
import shutil
import subprocess
import time
import re
import uuid
from pathlib import Path
from datetime import datetime
from urllib.parse import quote

from fastapi import FastAPI, UploadFile, File, Query, Depends, HTTPException, Header, Form
from fastapi.responses import FileResponse, JSONResponse, HTMLResponse
from fastapi.middleware.cors import CORSMiddleware
import uvicorn

app = FastAPI(title="自在网盘")

app.add_middleware(CORSMiddleware, allow_origins=["*"], allow_methods=["*"], allow_headers=["*"])

SHARES = {
    "Share":  r"\\192.168.1.200\Share",
    "Share2": r"\\192.168.1.200\Share2",
    "Share3": r"\\192.168.1.200\Share3",
    "Share4": r"\\192.168.1.200\Share4",
}

# 个人网盘映射
PERSONAL_SHARE = r"\\192.168.1.200\share4\zizai_share"

SHARE_LABELS = {
    "Share":  "共享盘",
    "Share2": "部门盘",
    "Share3": "项目盘",
    "Share4": "归档盘",
}

# ==================== 用户认证 ====================

USERS = {
    "Sonia": "zizai123", "大木": "zizai123", "大韦": "zizai123", "德意": "zizai123",
    "多多": "zizai123", "范范": "zizai123", "girasole": "zizai123", "果果": "zizai123",
    "后森": "zizai123", "KK": "zizai123", "老王": "zizai123", "老朱": "zizai123",
    "黎世鑫": "zizai123", "明轩": "zizai123", "momo": "zizai123", "moti": "zizai123",
    "千寻": "zizai123", "树叶": "zizai123", "朱雀": "zizai123", "苏寻": "zizai123",
    "太玮": "zizai123", "乌龟": "zizai123", "小李": "zizai123", "晓路": "zizai123",
    "小诺": "zizai123", "小新": "zizai123", "小鱼": "zizai123", "徐铭汛": "zizai123",
    "姚亮": "zizai123", "悦丰": "zizai123", "周益汇": "zizai123", "自在 ee": "zizai123",
    "自在 momo": "zizai123", "自在酱": "zizai123", "自在joy": "zizai123", "自在君": "zizai123",
    "Z酱": "zizai123",
}

_tokens = {}

def get_current_user(authorization: str = Header(None)):
    if not authorization or not authorization.startswith("Bearer "):
        raise HTTPException(status_code=401, detail="请先登录")
    token = authorization[7:]
    username = _tokens.get(token)
    if not username:
        raise HTTPException(status_code=401, detail="登录已过期，请重新登录")
    return username


_usage_cache = {}
_usage_cache_time = 0
CACHE_TTL = 300

_mounted_drives = {}


def format_size(size_bytes):
    if size_bytes == 0:
        return "0 B"
    units = ["B", "KB", "MB", "GB", "TB"]
    i = 0
    val = float(size_bytes)
    while val >= 1024 and i < len(units) - 1:
        val /= 1024
        i += 1
    return f"{val:.1f} {units}"


def try_get_disk_usage(unc_path):
    try:
        ps = f"""
$path = '{unc_path}'
try {{
    $item = Get-Item -Path $path -ErrorAction Stop
    $psd = Get-PSDrive -Name $item.PSDrive.Name -ErrorAction Stop
    $used = $psd.Used
    $free = $psd.Free
    if ($used -and $free) {{
        Write-Host "OK:$($used + $free);$used;$free"
    }} else {{
        Write-Host "ZERO"
    }}
}} catch {{
    Write-Host "FAIL"
}}
"""
        result = subprocess.run(
            ["powershell", "-NoProfile", "-Command", ps],
            capture_output=True, text=True, timeout=10
        )
        out = result.stdout.strip()
        if out.startswith("OK:"):
            parts = out[3:].split(";")
            return {"total": int(parts[0]), "used": int(parts[1]), "free": int(parts[2])}
    except Exception:
        pass

    try:
        ps = f"""
$computer = '192.168.1.200'
try {{
    $disks = Get-WmiObject Win32_LogicalDisk -ComputerName $computer -Filter "DriveType=3" -ErrorAction Stop
    $total = 0; $free = 0
    foreach ($d in $disks) {{ $total += $d.Size; $free += $d.FreeSpace }}
    if ($total -gt 0) {{
        Write-Host "OK:$total;$($total - $free);$free"
    }} else {{
        Write-Host "ZERO"
    }}
}} catch {{
    Write-Host "FAIL"
}}
"""
        result = subprocess.run(
            ["powershell", "-NoProfile", "-Command", ps],
            capture_output=True, text=True, timeout=10
        )
        out = result.stdout.strip()
        if out.startswith("OK:"):
            parts = out[3:].split(";")
            return {"total": int(parts[0]), "used": int(parts[1]), "free": int(parts[2])}
    except Exception:
        pass

    try:
        total_size = 0
        for entry in os.scandir(unc_path):
            if entry.is_file():
                try:
                    total_size += entry.stat().st_size
                except OSError:
                    pass
        return {"total": max(total_size * 4, 107374182400), "used": total_size, "free": max(total_size * 3, 107374182400 - total_size)}
    except Exception:
        pass

    return None


def get_share_usage(share_name):
    global _usage_cache, _usage_cache_time
    now = time.time()
    if now - _usage_cache_time < CACHE_TTL and share_name in _usage_cache:
        return _usage_cache[share_name]

    unc_path = SHARES[share_name]
    _usage_cache[share_name] = try_get_disk_usage(unc_path)
    _usage_cache_time = now
    return _usage_cache[share_name]


def get_all_usage():
    total_all = 0
    used_all = 0
    free_all = 0
    any_data = False

    for name in SHARES:
        info = get_share_usage(name)
        if info:
            total_all += info["total"]
            used_all += info["used"]
            free_all += info["free"]
            any_data = True

    if any_data:
        return {"total": total_all, "used": used_all, "free": free_all}
    return None


def list_directory(share_name, sub_path=""):
    base = SHARES[share_name]
    full_path = os.path.join(base, sub_path) if sub_path else base

    if not os.path.exists(full_path):
        return None

    items = []
    try:
        for entry in os.scandir(full_path):
            stat = entry.stat()
            item = {
                "name": entry.name,
                "type": "folder" if entry.is_dir() else "file",
                "size": stat.st_size if entry.is_file() else 0,
                "size_str": format_size(stat.st_size) if entry.is_file() else "",
                "modified": datetime.fromtimestamp(stat.st_mtime).strftime("%Y-%m-%d %H:%M"),
                "modified_ts": stat.st_mtime,
            }
            items.append(item)
    except PermissionError:
        return {"error": "无权限访问此目录"}

    items.sort(key=lambda x: (0 if x["type"] == "folder" else 1, x["name"].lower()))
    return items


# ==================== 认证接口 ====================

@app.post("/api/login")
def api_login(username: str = Form(""), password: str = Form("")):
    if username not in USERS:
        return JSONResponse({"error": "用户名不存在"}, status_code=401)
    if USERS[username] != password:
        return JSONResponse({"error": "密码错误"}, status_code=401)
    token = str(uuid.uuid4())
    _tokens[token] = username
    return {"token": token, "username": username}


@app.get("/api/me")
def api_me(username: str = Depends(get_current_user)):
    return {"username": username}


# ==================== 页面 ====================

@app.get("/")
def index():
    # 优先查找同目录下的 index.html，再查找上游 output/temp
    html_path = Path(__file__).parent / "index.html"
    if not html_path.exists():
        html_path = Path(__file__).parent.parent / "output" / "index.html"
    if not html_path.exists():
        html_path = Path(__file__).parent.parent / "temp" / "index.html"
    if html_path.exists():
        return HTMLResponse(html_path.read_text(encoding="utf-8"))
    return HTMLResponse("<h1>自在网盘</h1>")


# ==================== 文件列表 ====================

@app.get("/api/list/{share_name}")
def api_list(share_name: str, path: str = "", username: str = Depends(get_current_user)):
    if share_name not in SHARES:
        return JSONResponse({"error": "无效的盘符"}, status_code=404)
    result = list_directory(share_name, path)
    if result is None:
        return JSONResponse({"error": "目录不存在"}, status_code=404)
    if isinstance(result, dict) and "error" in result:
        return JSONResponse(result, status_code=403)
    return {"items": result, "share": share_name, "path": path}


# ==================== 容量 ====================

@app.get("/api/usage")
def api_usage(username: str = Depends(get_current_user)):
    usage = get_all_usage()
    if usage:
        return {
            "total": usage["total"],
            "used": usage["used"],
            "free": usage["free"],
            "total_str": format_size(usage["total"]),
            "used_str": format_size(usage["used"]),
            "free_str": format_size(usage["free"]),
            "percent": round(usage["used"] / usage["total"] * 100, 1) if usage["total"] > 0 else 0,
        }
    return {"total": 0, "used": 0, "free": 0, "total_str": "获取中...", "used_str": "--", "free_str": "--", "percent": 0}


@app.get("/api/usage/{share_name}")
def api_share_usage(share_name: str, username: str = Depends(get_current_user)):
    if share_name not in SHARES:
        return JSONResponse({"error": "无效的盘符"}, status_code=404)
    info = get_share_usage(share_name)
    if info:
        return {
            "total": info["total"],
            "used": info["used"],
            "free": info["free"],
            "total_str": format_size(info["total"]),
            "used_str": format_size(info["used"]),
            "free_str": format_size(info["free"]),
            "percent": round(info["used"] / info["total"] * 100, 1) if info["total"] > 0 else 0,
        }
    return {"total": 0, "used": 0, "free": 0, "total_str": "获取中...", "used_str": "--", "free_str": "--", "percent": 0}


# ==================== 下载 ====================

@app.get("/api/download/{share_name}")
def api_download(share_name: str, path: str = "", username: str = Depends(get_current_user)):
    if share_name not in SHARES:
        return JSONResponse({"error": "无效的盘符"}, status_code=404)

    base = SHARES[share_name]
    full_path = os.path.join(base, path)

    real_base = os.path.realpath(base)
    real_path = os.path.realpath(full_path)
    if not real_path.startswith(real_base):
        return JSONResponse({"error": "禁止访问"}, status_code=403)

    if not os.path.isfile(full_path):
        return JSONResponse({"error": "文件不存在"}, status_code=404)

    filename = os.path.basename(full_path)
    encoded_filename = quote(filename)
    return FileResponse(
        full_path, filename=filename, media_type="application/octet-stream",
        headers={"Content-Disposition": f"attachment; filename*=UTF-8''{encoded_filename}"}
    )


# ==================== 上传 ====================

@app.post("/api/upload/{share_name}")
async def api_upload(share_name: str, path: str = "", files: list[UploadFile] = File(...), username: str = Depends(get_current_user)):
    if share_name not in SHARES:
        return JSONResponse({"error": "无效的盘符"}, status_code=404)

    base = SHARES[share_name]
    dest_dir = os.path.join(base, path) if path else base

    real_base = os.path.realpath(base)
    real_dest = os.path.realpath(dest_dir)
    if not real_dest.startswith(real_base):
        return JSONResponse({"error": "禁止访问"}, status_code=403)

    if not os.path.exists(dest_dir):
        os.makedirs(dest_dir, exist_ok=True)

    uploaded = []
    failed = []
    for file in files:
        file_path = os.path.join(dest_dir, file.filename)
        try:
            with open(file_path, "wb") as f:
                shutil.copyfileobj(file.file, f)
            uploaded.append(file.filename)
        except Exception as e:
            failed.append({"name": file.filename, "error": str(e)})

    return {"uploaded": uploaded, "failed": failed}


# ==================== 删除 ====================

@app.delete("/api/delete/{share_name}")
def api_delete(share_name: str, path: str = "", username: str = Depends(get_current_user)):
    if share_name not in SHARES:
        return JSONResponse({"error": "无效的盘符"}, status_code=404)

    base = SHARES[share_name]
    full_path = os.path.join(base, path)

    real_base = os.path.realpath(base)
    real_path = os.path.realpath(full_path)
    if not real_path.startswith(real_base):
        return JSONResponse({"error": "禁止访问"}, status_code=403)

    if not os.path.exists(full_path):
        return JSONResponse({"error": "文件不存在"}, status_code=404)

    try:
        if os.path.isdir(full_path):
            shutil.rmtree(full_path)
        else:
            os.remove(full_path)
        return {"success": True, "deleted": os.path.basename(full_path)}
    except Exception as e:
        return JSONResponse({"error": str(e)}, status_code=500)


# ==================== 新建文件夹 ====================

@app.post("/api/mkdir/{share_name}")
def api_mkdir(share_name: str, path: str = "", name: str = "", username: str = Depends(get_current_user)):
    if share_name not in SHARES:
        return JSONResponse({"error": "无效的盘符"}, status_code=404)

    base = SHARES[share_name]
    parent_dir = os.path.join(base, path) if path else base
    new_dir = os.path.join(parent_dir, name)

    real_base = os.path.realpath(base)
    real_new = os.path.realpath(new_dir)
    if not real_new.startswith(real_base):
        return JSONResponse({"error": "禁止访问"}, status_code=403)

    try:
        os.makedirs(new_dir, exist_ok=True)
        return {"success": True, "path": os.path.join(path, name) if path else name}
    except Exception as e:
        return JSONResponse({"error": str(e)}, status_code=500)


# ==================== 搜索（单盘） ====================

@app.get("/api/search/{share_name}")
def api_search(share_name: str, q: str = "", path: str = "", username: str = Depends(get_current_user)):
    if share_name not in SHARES:
        return JSONResponse({"error": "无效的盘符"}, status_code=404)
    if not q:
        return {"items": []}

    base = SHARES[share_name]
    search_dir = os.path.join(base, path) if path else base

    results = []
    keyword = q.lower()
    try:
        for root, dirs, files in os.walk(search_dir):
            depth = root.replace(search_dir, "").count(os.sep)
            if depth > 6:
                dirs.clear()
                continue
            for name in dirs + files:
                if keyword in name.lower():
                    full = os.path.join(root, name)
                    rel = os.path.relpath(full, base)
                    stat = os.stat(full)
                    results.append({
                        "name": name, "path": rel,
                        "type": "folder" if os.path.isdir(full) else "file",
                        "size_str": format_size(stat.st_size) if os.path.isfile(full) else "",
                        "modified": datetime.fromtimestamp(stat.st_mtime).strftime("%Y-%m-%d %H:%M"),
                    })
            if len(results) >= 200:
                break
    except Exception:
        pass

    return {"items": results, "share": share_name}


# ==================== 全局搜索（跨盘） ====================

@app.get("/api/search_all")
def api_search_all(q: str = "", username: str = Depends(get_current_user)):
    if not q:
        return {"items": []}

    all_results = []
    keyword = q.lower()
    for share_name, base in SHARES.items():
        try:
            count_before = len(all_results)
            for root, dirs, files in os.walk(base):
                depth = root.replace(base, "").count(os.sep)
                if depth > 5:
                    dirs.clear()
                    continue
                for name in dirs + files:
                    if keyword in name.lower():
                        full = os.path.join(root, name)
                        rel = os.path.relpath(full, base)
                        stat = os.stat(full)
                        all_results.append({
                            "name": name, "path": rel, "share": share_name,
                            "share_label": SHARE_LABELS.get(share_name, share_name),
                            "type": "folder" if os.path.isdir(full) else "file",
                            "size_str": format_size(stat.st_size) if os.path.isfile(full) else "",
                            "modified": datetime.fromtimestamp(stat.st_mtime).strftime("%Y-%m-%d %H:%M"),
                        })
                if len(all_results) - count_before >= 50:
                    break
        except Exception:
            pass
        if len(all_results) >= 200:
            break

    return {"items": all_results, "query": q}


# ==================== 高级查找 ====================

@app.get("/api/find/{share_name}")
def api_find(
    share_name: str,
    path: str = "",
    name_contains: str = "",
    ext: str = "",
    min_size: int = 0,
    max_size: int = 0,
    modified_after: str = "",
    modified_before: str = "",
    file_type: str = "",
    limit: int = 200,
    username: str = Depends(get_current_user)
):
    if share_name not in SHARES:
        return JSONResponse({"error": "无效的盘符"}, status_code=404)

    base = SHARES[share_name]
    search_dir = os.path.join(base, path) if path else base

    after_ts = 0
    before_ts = 0
    if modified_after:
        try:
            after_ts = datetime.strptime(modified_after, "%Y-%m-%d").timestamp()
        except ValueError:
            pass
    if modified_before:
        try:
            before_ts = datetime.strptime(modified_before, "%Y-%m-%d").timestamp()
        except ValueError:
            pass

    results = []
    ext_lower = ext.lower().lstrip(".")
    name_lower = name_contains.lower()

    try:
        for root, dirs, files in os.walk(search_dir):
            depth = root.replace(search_dir, "").count(os.sep)
            if depth > 8:
                dirs.clear()
                continue

            candidates = []
            if file_type != "folder":
                candidates.extend(files)
            if file_type != "file":
                candidates.extend(dirs)

            for name in candidates:
                full = os.path.join(root, name)
                rel = os.path.relpath(full, base)
                is_dir = os.path.isdir(full)

                if name_lower and name_lower not in name.lower():
                    continue

                if ext_lower and not is_dir:
                    if not name.lower().endswith("." + ext_lower):
                        continue

                stat = os.stat(full)
                fsize = stat.st_size if not is_dir else 0

                if min_size > 0 and fsize < min_size:
                    continue
                if max_size > 0 and fsize > max_size:
                    continue

                mtime = stat.st_mtime
                if after_ts and mtime < after_ts:
                    continue
                if before_ts and mtime > before_ts:
                    continue

                results.append({
                    "name": name, "path": rel,
                    "type": "folder" if is_dir else "file",
                    "size": fsize,
                    "size_str": format_size(fsize) if not is_dir else "",
                    "modified": datetime.fromtimestamp(mtime).strftime("%Y-%m-%d %H:%M"),
                    "modified_ts": mtime,
                })

                if len(results) >= limit:
                    break
            if len(results) >= limit:
                break
    except Exception:
        pass

    return {"items": results, "share": share_name, "count": len(results)}


# ==================== 挂载到本地 ====================

@app.post("/api/mount/{share_name}")
def api_mount(share_name: str, drive_letter: str = "", username: str = Depends(get_current_user)):
    if share_name not in SHARES:
        return JSONResponse({"error": "无效的盘符"}, status_code=404)

    unc_path = SHARES[share_name]

    if not drive_letter:
        used = set()
        try:
            result = subprocess.run(
                ["powershell", "-NoProfile", "-Command",
                 "Get-PSDrive -PSProvider FileSystem | ForEach-Object { $_.Name }"],
                capture_output=True, text=True, timeout=5
            )
            for line in result.stdout.strip().split("\n"):
                used.add(line.strip().upper() + ":")
        except Exception:
            used = set("C: D: E:".split())

        for letter_code in range(ord("Z"), ord("E"), -1):
            letter = chr(letter_code) + ":"
            if letter not in used:
                drive_letter = letter
                break

    if not drive_letter:
        return JSONResponse({"error": "没有可用的盘符"}, status_code=500)

    subprocess.run(f'net use {drive_letter} /delete /y', shell=True, capture_output=True)

    result = subprocess.run(
        f'net use {drive_letter} "{unc_path}" /persistent:yes',
        shell=True, capture_output=True, text=True, timeout=15
    )

    if result.returncode == 0:
        _mounted_drives[share_name] = drive_letter
        return {
            "success": True,
            "drive": drive_letter,
            "unc": unc_path,
            "share": share_name,
            "message": f"已挂载为 {drive_letter} 盘"
        }
    else:
        err = result.stderr or result.stdout
        return JSONResponse({"error": f"挂载失败: {err.strip()}"}, status_code=500)


@app.get("/api/mounts")
def api_list_mounts(username: str = Depends(get_current_user)):
    mounts = []
    for share_name, letter in _mounted_drives.items():
        mounts.append({
            "share": share_name,
            "label": SHARE_LABELS.get(share_name, share_name),
            "drive": letter,
            "unc": SHARES[share_name],
        })

    try:
        result = subprocess.run(
            ["powershell", "-NoProfile", "-Command",
             "Get-PSDrive -PSProvider FileSystem | Where-Object { $_.DisplayRoot -like '*192.168.1.200*' } | ForEach-Object { Write-Host ('{0}|{1}' -f $_.Name, $_.DisplayRoot) }"],
            capture_output=True, text=True, timeout=5
        )
        for line in result.stdout.strip().split("\n"):
            if "|" in line:
                name, root = line.split("|", 1)
                if not any(m["drive"] == name + ":" for m in mounts):
                    mounts.append({
                        "share": root.split("\\")[-1],
                        "label": root.split("\\")[-1],
                        "drive": name + ":",
                        "unc": root,
                    })
    except Exception:
        pass

    return {"mounts": mounts}


@app.delete("/api/mount/{share_name}")
def api_unmount(share_name: str, username: str = Depends(get_current_user)):
    letter = _mounted_drives.get(share_name, "")
    if not letter:
        unc = SHARES[share_name]
        try:
            result = subprocess.run(
                ["powershell", "-NoProfile", "-Command",
                 f"Get-PSDrive -PSProvider FileSystem | Where-Object {{ $_.DisplayRoot -eq '{unc}' }} | ForEach-Object {{ $_.Name }}"],
                capture_output=True, text=True, timeout=5
            )
            name = result.stdout.strip()
            if name:
                letter = name + ":"
        except Exception:
            pass

    if not letter:
        return JSONResponse({"error": "未挂载"}, status_code=404)

    result = subprocess.run(
        f'net use {letter} /delete /y',
        shell=True, capture_output=True, text=True, timeout=10
    )

    if result.returncode == 0:
        _mounted_drives.pop(share_name, None)
        return {"success": True, "message": f"已断开 {letter}"}
    else:
        return JSONResponse({"error": result.stderr.strip()}, status_code=500)


# ==================== 刷新服务器连接 ====================

@app.post("/api/refresh/{share_name}")
def api_refresh(share_name: str, username: str = Depends(get_current_user)):
    if share_name not in SHARES:
        return JSONResponse({"error": "无效的盘符"}, status_code=404)

    unc_path = SHARES[share_name]
    try:
        if os.path.exists(unc_path):
            os.listdir(unc_path)
            global _usage_cache, _usage_cache_time
            _usage_cache.pop(share_name, None)
            _usage_cache_time = 0
            return {"success": True, "share": share_name, "status": "已连接"}
        else:
            return {"success": False, "share": share_name, "status": "无法访问"}
    except Exception as e:
        return {"success": False, "share": share_name, "status": str(e)}


@app.post("/api/refresh_all")
def api_refresh_all(username: str = Depends(get_current_user)):
    results = {}
    for name, unc in SHARES.items():
        try:
            if os.path.exists(unc):
                os.listdir(unc)
                results[name] = "已连接"
            else:
                results[name] = "无法访问"
        except Exception as e:
            results[name] = str(e)

    global _usage_cache, _usage_cache_time
    _usage_cache.clear()
    _usage_cache_time = 0

    all_ok = all(v == "已连接" for v in results.values())
    return {"results": results, "all_ok": all_ok}


# ==================== 个人网盘 ====================
PERSONAL_QUOTA = 1024 * 1024 * 1024 * 1024  # 1TB

def get_personal_dir(username):
    path = os.path.join(PERSONAL_SHARE, username)
    os.makedirs(path, exist_ok=True)
    return path


def get_personal_usage(username):
    path = get_personal_dir(username)
    total_size = 0
    for dirpath, dirnames, filenames in os.walk(path):
        for f in filenames:
            try:
                total_size += os.path.getsize(os.path.join(dirpath, f))
            except OSError:
                pass
    return {
        "total": PERSONAL_QUOTA,
        "used": total_size,
        "total_str": "1.0 TB",
        "used_str": format_size(total_size),
        "percent": round(total_size / PERSONAL_QUOTA * 100, 1) if PERSONAL_QUOTA > 0 else 0,
    }


@app.get("/api/personal/list")
def api_personal_list(path: str = "", username: str = Depends(get_current_user)):
    base = get_personal_dir(username)
    full_path = os.path.join(base, path) if path else base

    real_base = os.path.realpath(base)
    real_full = os.path.realpath(full_path)
    if not real_full.startswith(real_base):
        return JSONResponse({"error": "禁止访问"}, status_code=403)

    if not os.path.exists(full_path):
        return JSONResponse({"error": "目录不存在"}, status_code=404)

    items = []
    try:
        for entry in os.scandir(full_path):
            stat = entry.stat()
            items.append({
                "name": entry.name,
                "type": "folder" if entry.is_dir() else "file",
                "size": stat.st_size if entry.is_file() else 0,
                "size_str": format_size(stat.st_size) if entry.is_file() else "",
                "modified": datetime.fromtimestamp(stat.st_mtime).strftime("%Y-%m-%d %H:%M"),
                "modified_ts": stat.st_mtime,
            })
    except PermissionError:
        return JSONResponse({"error": "无权限访问此目录"}, status_code=403)

    items.sort(key=lambda x: (0 if x["type"] == "folder" else 1, x["name"].lower()))
    return {"items": items, "share": "personal", "path": path}


@app.get("/api/personal/usage")
def api_personal_usage(username: str = Depends(get_current_user)):
    return get_personal_usage(username)


@app.get("/api/personal/download")
def api_personal_download(path: str = "", username: str = Depends(get_current_user)):
    base = get_personal_dir(username)
    full_path = os.path.join(base, path)
    real_base = os.path.realpath(base)
    real_full = os.path.realpath(full_path)
    if not real_full.startswith(real_base):
        return JSONResponse({"error": "禁止访问"}, status_code=403)
    if not os.path.isfile(full_path):
        return JSONResponse({"error": "文件不存在"}, status_code=404)
    filename = os.path.basename(full_path)
    return FileResponse(
        full_path, filename=filename, media_type="application/octet-stream",
        headers={"Content-Disposition": f"attachment; filename*=UTF-8''{quote(filename)}"}
    )


@app.post("/api/personal/upload")
async def api_personal_upload(path: str = "", files: list[UploadFile] = File(...), username: str = Depends(get_current_user)):
    base = get_personal_dir(username)
    dest_dir = os.path.join(base, path) if path else base
    real_base = os.path.realpath(base)
    real_dest = os.path.realpath(dest_dir)
    if not real_dest.startswith(real_base):
        return JSONResponse({"error": "禁止访问"}, status_code=403)
    os.makedirs(dest_dir, exist_ok=True)

    uploaded = []
    failed = []
    for file in files:
        file_path = os.path.join(dest_dir, file.filename)
        try:
            with open(file_path, "wb") as f:
                shutil.copyfileobj(file.file, f)
            uploaded.append(file.filename)
        except Exception as e:
            failed.append({"name": file.filename, "error": str(e)})
    return {"uploaded": uploaded, "failed": failed}


@app.delete("/api/personal/delete")
def api_personal_delete(path: str = "", username: str = Depends(get_current_user)):
    base = get_personal_dir(username)
    full_path = os.path.join(base, path) if path else base
    real_base = os.path.realpath(base)
    real_full = os.path.realpath(full_path)
    if not real_full.startswith(real_base):
        return JSONResponse({"error": "禁止访问"}, status_code=403)
    if not os.path.exists(full_path):
        return JSONResponse({"error": "文件不存在"}, status_code=404)
    try:
        if os.path.isdir(full_path):
            shutil.rmtree(full_path)
        else:
            os.remove(full_path)
        return {"success": True, "deleted": os.path.basename(full_path)}
    except Exception as e:
        return JSONResponse({"error": str(e)}, status_code=500)


@app.post("/api/personal/mkdir")
def api_personal_mkdir(path: str = "", name: str = "", username: str = Depends(get_current_user)):
    base = get_personal_dir(username)
    parent_dir = os.path.join(base, path) if path else base
    new_dir = os.path.join(parent_dir, name)
    real_base = os.path.realpath(base)
    real_new = os.path.realpath(new_dir)
    if not real_new.startswith(real_base):
        return JSONResponse({"error": "禁止访问"}, status_code=403)
    os.makedirs(new_dir, exist_ok=True)
    return {"success": True, "path": os.path.join(path, name) if path else name}


@app.get("/api/personal/search")
def api_personal_search(q: str = "", path: str = "", username: str = Depends(get_current_user)):
    if not q:
        return {"items": []}
    base = get_personal_dir(username)
    search_dir = os.path.join(base, path) if path else base
    results = []
    keyword = q.lower()
    try:
        for root, dirs, files in os.walk(search_dir):
            depth = root.replace(search_dir, "").count(os.sep)
            if depth > 6:
                dirs.clear()
                continue
            for name in dirs + files:
                if keyword in name.lower():
                    full = os.path.join(root, name)
                    rel = os.path.relpath(full, base)
                    stat = os.stat(full)
                    results.append({
                        "name": name, "path": rel,
                        "type": "folder" if os.path.isdir(full) else "file",
                        "size_str": format_size(stat.st_size) if os.path.isfile(full) else "",
                        "modified": datetime.fromtimestamp(stat.st_mtime).strftime("%Y-%m-%d %H:%M"),
                    })
            if len(results) >= 200:
                break
    except Exception:
        pass
    return {"items": results, "share": "personal"}


# ==================== 分享功能 ====================

import json
import threading
import zipfile

SHARE_DATA_FILE = os.path.join(os.path.dirname(os.path.abspath(__file__)), "shares.json")
SHARE_TEMP_DIR = os.path.join(os.path.dirname(os.path.abspath(__file__)), "share_temp")
os.makedirs(SHARE_TEMP_DIR, exist_ok=True)

_shares = {}
_share_lock = threading.Lock()

def load_shares():
    global _shares
    if os.path.exists(SHARE_DATA_FILE):
        try:
            with open(SHARE_DATA_FILE, 'r', encoding='utf-8') as f:
                _shares = json.load(f)
        except:
            _shares = {}

def save_shares():
    with _share_lock:
        try:
            with open(SHARE_DATA_FILE, 'w', encoding='utf-8') as f:
                json.dump(_shares, f, ensure_ascii=False, indent=2)
        except:
            pass

load_shares()

def zip_folder(folder_path: str, output_path: str):
    """将文件夹打包为 zip 文件，返回 (成功, 大小)"""
    with zipfile.ZipFile(output_path, 'w', zipfile.ZIP_DEFLATED) as zf:
        for root, dirs, files in os.walk(folder_path):
            for fname in files:
                full = os.path.join(root, fname)
                arcname = os.path.relpath(full, folder_path)
                zf.write(full, arcname)
    return os.path.getsize(output_path)

@app.post("/api/share/create")
def api_create_share(
    share_name: str = Form(""),
    path: str = Form(""),
    share_type: str = Form("personal"),
    username: str = Depends(get_current_user)
):
    """
    创建分享链接。share_type: "shared" | "personal"
    path: 文件或文件夹相对路径
    """
    # 确定实际路径
    if share_type == "personal":
        base = get_personal_dir(username)
        if not base:
            raise HTTPException(status_code=500, detail="个人目录不可用")
    elif share_name in SHARES:
        base = SHARES[share_name]
    else:
        raise HTTPException(status_code=400, detail="无效的盘符或分享类型")

    target_path = os.path.join(base, path.lstrip("\\").lstrip("/"))

    # 安全检查：防止路径穿越
    real_target = os.path.realpath(target_path)
    real_base = os.path.realpath(base)
    if not real_target.startswith(real_base):
        raise HTTPException(status_code=403, detail="路径越权")

    if not os.path.exists(real_target):
        raise HTTPException(status_code=404, detail="文件或文件夹不存在")

    is_folder = os.path.isdir(real_target)
    share_id = uuid.uuid4().hex[:12]
    file_name = os.path.basename(real_target)
    file_size = 0
    share_path = real_target  # 实际可下载路径

    if is_folder:
        # 打包文件夹
        zip_name = f"{share_id}.zip"
        zip_full = os.path.join(SHARE_TEMP_DIR, zip_name)
        try:
            file_size = zip_folder(real_target, zip_full)
            share_path = zip_full
        except Exception as e:
            raise HTTPException(status_code=500, detail=f"打包失败: {str(e)}")
    else:
        file_size = os.path.getsize(real_target)

    record = {
        "share_id": share_id,
        "file_name": file_name,
        "is_folder": is_folder,
        "file_size": file_size,
        "file_size_str": format_size(file_size),
        "share_path": share_path,
        "share_name": share_name,
        "share_type": share_type,
        "created_at": datetime.now().strftime("%Y-%m-%d %H:%M:%S"),
        "owner": username,
        "downloads": 0,
    }

    _shares[share_id] = record
    save_shares()

    base_url = f"http://192.168.1.23:8889"
    return {
        "share_id": share_id,
        "link": f"{base_url}/s/{share_id}",
        "file_name": file_name,
        "is_folder": is_folder,
        "file_size_str": format_size(file_size),
        "created_at": record["created_at"],
    }


@app.get("/api/share/list")
def api_list_shares(username: str = Depends(get_current_user)):
    """列出当前用户的所有分享"""
    items = []
    for sid, rec in _shares.items():
        if rec["owner"] == username:
            items.append({
                "share_id": rec["share_id"],
                "file_name": rec["file_name"],
                "is_folder": rec["is_folder"],
                "file_size_str": rec["file_size_str"],
                "created_at": rec["created_at"],
                "downloads": rec["downloads"],
                "link": f"http://192.168.1.23:8889/s/{rec['share_id']}",
            })
    items.sort(key=lambda x: x["created_at"], reverse=True)
    return {"items": items}


@app.get("/api/share/info/{share_id}")
def api_share_info(share_id: str):
    """获取分享信息（无需登录）"""
    rec = _shares.get(share_id)
    if not rec:
        raise HTTPException(status_code=404, detail="分享链接不存在或已失效")
    return {
        "share_id": rec["share_id"],
        "file_name": rec["file_name"],
        "is_folder": rec["is_folder"],
        "file_size_str": rec["file_size_str"],
        "created_at": rec["created_at"],
        "owner": rec["owner"],
        "downloads": rec["downloads"],
    }


@app.get("/api/share/download/{share_id}")
def api_share_download(share_id: str):
    """下载分享文件（无需登录）"""
    rec = _shares.get(share_id)
    if not rec:
        raise HTTPException(status_code=404, detail="分享链接不存在或已失效")

    share_path = rec["share_path"]
    if not os.path.exists(share_path):
        raise HTTPException(status_code=404, detail="文件已被删除或移动")

    _shares[share_id]["downloads"] = _shares[share_id].get("downloads", 0) + 1
    save_shares()

    download_name = rec["file_name"]
    if rec["is_folder"]:
        download_name += ".zip"

    return FileResponse(
        share_path,
        filename=download_name,
        media_type="application/octet-stream",
    )


@app.delete("/api/share/revoke/{share_id}")
def api_share_revoke(share_id: str, username: str = Depends(get_current_user)):
    """撤销分享"""
    rec = _shares.get(share_id)
    if not rec:
        raise HTTPException(status_code=404, detail="分享不存在")
    if rec["owner"] != username:
        raise HTTPException(status_code=403, detail="只能撤销自己的分享")

    # 删除打包的 zip 文件
    if rec["is_folder"] and os.path.exists(rec["share_path"]):
        try:
            os.remove(rec["share_path"])
        except:
            pass

    del _shares[share_id]
    save_shares()
    return {"ok": True}


@app.get("/s/{share_id}")
def share_download_page(share_id: str):
    """分享下载页面"""
    rec = _shares.get(share_id)
    if not rec:
        return HTMLResponse("""
<!DOCTYPE html>
<html lang="zh-CN">
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>分享已失效 - 自在网盘</title>
<style>
*{margin:0;padding:0;box-sizing:border-box}
body{background:linear-gradient(135deg,#0a0e27 0%,#1a1040 50%,#0d1b2a 100%);min-height:100vh;display:flex;align-items:center;justify-content:center;font-family:'Segoe UI','PingFang SC','Microsoft YaHei',sans-serif}
.card{background:rgba(255,255,255,0.06);backdrop-filter:blur(24px);border:1px solid rgba(255,255,255,0.1);border-radius:24px;padding:60px 48px;text-align:center;max-width:480px;width:90%}
h1{color:#e0e0e0;font-size:28px;margin-bottom:12px}
p{color:#888;font-size:16px}
.icon{font-size:64px;margin-bottom:24px}
</style></head>
<body>
<div class="card">
<div class="icon">&#128533;</div>
<h1>分享链接已失效</h1>
<p>该分享可能已被撤销或链接不存在</p>
</div>
</body></html>""", status_code=404)

    return HTMLResponse(f"""<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>{rec['file_name']} - 自在网盘分享</title>
<style>
*{{margin:0;padding:0;box-sizing:border-box}}
body{{background:linear-gradient(135deg,#0a0e27 0%,#1a1040 50%,#0d1b2a 100%);min-height:100vh;display:flex;align-items:center;justify-content:center;font-family:'Segoe UI','PingFang SC','Microsoft YaHei',sans-serif}}
.card{{background:rgba(255,255,255,0.06);backdrop-filter:blur(24px);border:1px solid rgba(255,255,255,0.12);border-radius:24px;padding:48px;max-width:500px;width:90%}}
.icon{{font-size:56px;margin-bottom:16px;text-align:center}}
.file-name{{color:#f0f0f0;font-size:22px;text-align:center;margin-bottom:8px;word-break:break-all}}
.meta{{display:flex;justify-content:center;gap:24px;margin:16px 0 24px;color:#888;font-size:14px}}
.meta span{{display:flex;align-items:center;gap:6px}}
.btn{{display:block;width:100%;padding:16px;background:linear-gradient(135deg,#667eea 0%,#764ba2 100%);border:none;border-radius:14px;color:#fff;font-size:18px;cursor:pointer;text-align:center;text-decoration:none;transition:all 0.3s;margin-bottom:12px}}
.btn:hover{{transform:translateY(-2px);box-shadow:0 8px 32px rgba(102,126,234,0.4)}}
.btn:active{{transform:scale(0.98)}}
.hint{{color:#666;font-size:13px;text-align:center}}
.progress{{display:none;margin-top:16px;height:8px;background:rgba(255,255,255,0.1);border-radius:4px;overflow:hidden}}
.progress-bar{{height:100%;background:linear-gradient(135deg,#667eea 0%,#764ba2 100%);width:0;border-radius:4px;transition:width 0.3s}}
</style>
</head>
<body>
<div class="card">
<div class="icon">{'&#128193;' if rec['is_folder'] else '&#128196;'}</div>
<div class="file-name">{rec['file_name']}{'.zip' if rec['is_folder'] else ''}</div>
<div class="meta">
<span>&#128100; {rec['owner']}</span>
<span>&#128196; {rec['file_size_str']}</span>
<span>&#128197; {rec['created_at']}</span>
</div>
<a class="btn" href="/api/share/download/{share_id}" download>下载文件</a>
<p class="hint">来自自在网盘 · {rec['downloads']} 次下载</p>
</div>
</body></html>""")


if __name__ == "__main__":
    uvicorn.run(app, host="0.0.0.0", port=8889)
