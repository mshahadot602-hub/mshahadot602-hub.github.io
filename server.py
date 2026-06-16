import http.server
import socketserver
import os

PORT = 8018
DIR = os.path.dirname(os.path.abspath(__file__))

class Handler(http.server.SimpleHTTPRequestHandler):
    def __init__(self, *args, **kwargs):
        super().__init__(*args, directory=DIR, **kwargs)

    def log_message(self, format, *args):
        print(f"[{self.client_address[0]}] {args[0]}")

if __name__ == '__main__':
    print(f"自在空间本地服务器已启动")
    print(f"访问地址: http://localhost:{PORT}")
    print(f"按 Ctrl+C 停止服务器")
    print()
    with socketserver.TCPServer(("", PORT), Handler) as httpd:
        try:
            httpd.serve_forever()
        except KeyboardInterrupt:
            print("\n服务器已停止")
