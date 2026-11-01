"""Browsing-only immutable lifecycle evidence; no processes are launched by this module."""
import hashlib
import hmac
import json
import os
import pathlib
import re
import secrets
import time


def startup_payload(run, pid, script, manifest_bytes, attempt, started_ms):
    if not re.fullmatch(r'accept-[0-9a-f]{16}', run): raise ValueError('Invalid run')
    manifest = json.loads(manifest_bytes)
    if set(manifest) != {'run', 'users', 'skills', 'jobs'} or manifest['run'] != run:
        raise ValueError('Invalid fixture manifest')
    for key in ('users', 'skills', 'jobs'):
        ids = manifest[key]
        if not isinstance(ids, list) or not ids or len(ids) > 500:
            raise ValueError('Incomplete fixture manifest')
        if any(type(value) is not int or value < 1 or value > 4294967295 for value in ids) or len(set(ids)) != len(ids):
            raise ValueError('Invalid fixture IDs')
    if type(pid) is not int or pid < 1 or not re.fullmatch(r'[0-9a-f]{32}', attempt) or type(started_ms) is not int or started_ms < 1:
        raise ValueError('Invalid startup identity')
    return {'version': 1, 'phase': 'attempted', 'run': run, 'attempt': attempt, 'pid': pid,
            'script': str(script).replace('\\', '/'), 'database': 'gighub_schema_test', 'bind': '127.0.0.1:8765',
            'fixture_sha256': hashlib.sha256(manifest_bytes).hexdigest(), 'started_ms': started_ms}


def terminal_payload(start, ready_reached, children, ended_ms):
    return {**start, 'phase': 'stopped' if ready_reached else 'failed_before_ready',
            'ready_reached': ready_reached, 'children': children, 'ended_ms': ended_ms}


def seal(payload, key):
    if not re.fullmatch(r'[0-9a-f]{64}', key): raise ValueError('Invalid private control key')
    raw = json.dumps(payload, sort_keys=True, separators=(',', ':'), ensure_ascii=True)
    return {'payload_json': raw, 'mac': hmac.new(key.encode(), raw.encode(), 'sha256').hexdigest()}


class BrowseLifecycle:
    def __init__(self, directory, run, children):
        self.directory = directory
        self.run = run
        self.children = children
        self.start = None
        self.ready = False
        self.key = None

    def write(self, name, payload):
        # Fail if any historical evidence exists; no retries or evidence replacement.
        with (self.directory / name).open('x', encoding='utf-8') as output:
            json.dump(seal(payload, self.key), output)

    def begin(self):
        expected = pathlib.Path(r'C:\MariaDB_Recovery\GigHub_Clean_Test\http-acceptance') / self.run
        if self.directory.resolve(strict=True) != expected.resolve(strict=True): raise ValueError('Unexpected runtime directory')
        for name in ('browse-startup.json', 'browse-terminal.json', 'browse-cleanup-claimed.json', 'server-ready.json', 'server-stopped.json'):
            if (self.directory / name).exists(): raise ValueError('Runtime has prior lifecycle evidence')
        self.key = (self.directory / 'stop.key').read_text().strip()
        data = startup_payload(self.run, os.getpid(), pathlib.Path(__file__).with_name('server.py').resolve(),
                               (self.directory / 'job-fixtures.json').read_bytes(), secrets.token_hex(16), time.time_ns() // 1000000)
        self.write('browse-startup.json', data)
        self.start = data

    def mark_ready(self):
        self.ready = True

    def finish(self):
        if self.start is None: return
        children = [{'pid': child.pid, 'returncode': child.poll()} for child in self.children]
        self.write('browse-terminal.json', terminal_payload(self.start, self.ready, children, time.time_ns() // 1000000))
