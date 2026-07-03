import http.server
import socketserver
import os

PORT = 8899
DIR = os.path.dirname(os.path.abspath(__file__))

class MyHandler(http.server.SimpleHTTPRequestHandler):
    protocol_version = 'HTTP/1.1'
    
    def __init__(self, *args, **kwargs):
        super().__init__(*args, directory=DIR, **kwargs)
    
    def end_headers(self):
        self.send_header('Access-Control-Allow-Origin', '*')
        self.send_header('Cache-Control', 'no-store')
        super().end_headers()
    
    def log_message(self, format, *args):
        print(f"[{self.client_address[0]}] {format % args}")

if __name__ == '__main__':
    os.chdir(DIR)
    with socketserver.ThreadingTCPServer(('0.0.0.0', PORT), MyHandler) as httpd:
        print(f"服务器运行中 → http://0.0.0.0:{PORT}")
        print(f"文件目录: {DIR}")
        try:
            httpd.serve_forever()
        except KeyboardInterrupt:
            print("\n服务器已停止")
