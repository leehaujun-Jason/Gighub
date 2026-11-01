"""Loopback-only, single-request-at-a-time PHP CGI test host. No access logging."""
import argparse, hmac, http.server, json, os, pathlib, subprocess, urllib.parse

ROOT = pathlib.Path(__file__).resolve().parents[2]
PHP = r'C:\xampp\php\php.exe'
CGI = r'C:\xampp\php\php-cgi.exe'
PRIVATE = pathlib.Path(r'C:\MariaDB_Recovery\GigHub_Clean_Test\http-acceptance')

def minimal_env(run):
    return {'SystemRoot': r'C:\Windows', 'WINDIR': r'C:\Windows', 'PATH': r'C:\Windows\System32',
            'TEMP': str(PRIVATE / run), 'TMP': str(PRIVATE / run), 'GIGHUB_HTTP_RUN': run}

def preflight_control(state):
    browsing = state.get('job_browsing_enabled', False)
    if not isinstance(browsing, bool): raise ValueError('Invalid browsing mode')
    if browsing:
        if state.get('job_posting_enabled', False) is not False: raise ValueError('Conflicting test modes')
        return 'browse-control.php'
    return 'job-control.php' if state.get('job_posting_enabled') is True else 'control.php'

def main():
    parser = argparse.ArgumentParser(); parser.add_argument('--run', required=True); args = parser.parse_args()
    import re
    if not re.fullmatch(r'accept-[0-9a-f]{16}', args.run): raise SystemExit('Invalid run')
    directory = PRIVATE / args.run
    state = json.loads((directory / 'run.json').read_text(encoding='utf-8-sig'))
    if state['port'] != 8765 or state['run'] != args.run: raise SystemExit('Invalid runtime')
    if preflight_control(state) == 'browse-control.php':
        import sys
        sys.dont_write_bytecode = True
        from browse_lifecycle import BrowseLifecycle
        lifecycle = BrowseLifecycle(directory, args.run, [])
        try:
            lifecycle.begin()
            serve(args, state, directory, lifecycle)
        finally:
            lifecycle.finish()
    else:
        serve(args, state, directory)

def serve(args, state, directory, lifecycle=None):
    # No listening socket exists until authenticated identity/denial checks pass.
    control = preflight_control(state)
    command = [PHP, '-d', 'log_errors=0', str(pathlib.Path(__file__).with_name(control)), 'preflight', args.run]
    children = lifecycle.children if lifecycle is not None else []
    if lifecycle is not None:
        preflight = subprocess.Popen(command, env=minimal_env(args.run), stdout=subprocess.PIPE, stderr=subprocess.PIPE)
        children.append(preflight)
        try:
            output, error = preflight.communicate(timeout=30)
        except subprocess.TimeoutExpired:
            raise SystemExit('Startup preflight timeout; no forced termination or cleanup authorization')
        result = subprocess.CompletedProcess(command, preflight.returncode, output, error)
    else:
        result = subprocess.run(command, env=minimal_env(args.run), capture_output=True)
    if result.returncode or result.stdout.strip() != b'PASS': raise SystemExit('Isolated startup preflight failed')
    stop_key = (directory / 'stop.key').read_text().strip()
    class Handler(http.server.BaseHTTPRequestHandler):
        # Bound idle/speculative browser connections; no connection may monopolize this host.
        timeout = 5
        def log_message(self, *args): pass
        def respond(self, status, body=b''):
            self.send_response(status); self.send_header('Content-Length', str(len(body))); self.end_headers(); self.wfile.write(body)
        def do_GET(self): self.handle_test()
        def do_POST(self): self.handle_test()
        def handle_test(self):
            if self.client_address[0] != '127.0.0.1' or self.headers.get('Host') != '127.0.0.1:8765': return self.respond(403)
            parsed = urllib.parse.urlsplit(self.path)
            if parsed.path == '/_shutdown':
                if self.command != 'POST' or not hmac.compare_digest(self.headers.get('X-Test-Control', ''), stop_key): return self.respond(403)
                self.respond(200, b'Stopping'); self.server.stopping = True; return
            if parsed.path.startswith('/assets/'):
                name = parsed.path.removeprefix('/assets/')
                if '/' in name or '\\' in name or not name.endswith(('.css', '.js')): return self.respond(404)
                file = ROOT / 'public' / 'assets' / name
                if not file.is_file() or file.resolve().parent != (ROOT / 'public' / 'assets').resolve(): return self.respond(404)
                body = file.read_bytes(); self.send_response(200); self.send_header('Content-Type', 'text/css' if name.endswith('.css') else 'application/javascript'); self.send_header('Content-Length', str(len(body))); self.end_headers(); self.wfile.write(body); return
            if parsed.path not in ('/', '/index.php'): return self.respond(404)
            length = int(self.headers.get('Content-Length', '0'))
            if length < 0 or length > 16384: return self.respond(413)
            env = minimal_env(args.run)
            env.update({'REDIRECT_STATUS':'200', 'SCRIPT_FILENAME':str(pathlib.Path(__file__).with_name('front.php')), 'SCRIPT_NAME':'/index.php',
                        'REQUEST_METHOD':self.command, 'QUERY_STRING':parsed.query, 'REQUEST_URI':self.path, 'SERVER_NAME':'127.0.0.1',
                        'SERVER_PORT':'8765', 'SERVER_PROTOCOL':'HTTP/1.1', 'GATEWAY_INTERFACE':'CGI/1.1', 'REMOTE_ADDR':'127.0.0.1',
                        'HTTP_HOST':'127.0.0.1:8765', 'HTTP_COOKIE':self.headers.get('Cookie',''),
                        'CONTENT_TYPE':self.headers.get('Content-Type',''), 'CONTENT_LENGTH':str(length)})
            child = subprocess.Popen([CGI, '-d', 'display_errors=0', '-d', 'log_errors=0'], stdin=subprocess.PIPE, stdout=subprocess.PIPE, stderr=subprocess.PIPE, env=env)
            children.append(child)
            try: output, _ = child.communicate(self.rfile.read(length), timeout=45)
            except subprocess.TimeoutExpired:
                self.server.stopping = True; return self.respond(503, b'Test process timeout; no forced termination.')
            if child.returncode: return self.respond(503, b'Isolated CGI process failed.')
            head, sep, body = output.partition(b'\r\n\r\n')
            if not sep: return self.respond(503, b'Invalid CGI response.')
            status = 200; headers = []
            for line in head.decode('latin1').split('\r\n'):
                key, _, value = line.partition(':')
                if key.lower() == 'status': status = int(value.strip().split()[0])
                elif key.lower() not in ('x-powered-by', 'content-length'): headers.append((key, value.strip()))
            self.send_response(status)
            for key, value in headers: self.send_header(key, value)
            self.send_header('Content-Length',str(len(body))); self.end_headers(); self.wfile.write(body)
    with http.server.HTTPServer(('127.0.0.1',8765), Handler) as server:
        server.stopping = False
        (directory / 'server-ready.json').write_text(json.dumps({'pid':os.getpid(), 'bind':'127.0.0.1:8765', 'database':'gighub_schema_test'}))
        if lifecycle is not None: lifecycle.mark_ready()
        while not server.stopping: server.handle_request()
    unfinished = [p.pid for p in children if p.poll() is None]
    (directory / 'server-stopped.json').write_text(json.dumps({'graceful':not unfinished,'unfinished_children':unfinished}))

if __name__ == '__main__': main()
