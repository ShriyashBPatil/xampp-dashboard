import os
import pty
import select
import struct
import fcntl
import termios
import time
import signal
import uuid
from collections import deque
from flask import Flask, request, jsonify
from flask_socketio import SocketIO, emit

app = Flask(__name__)
app.config['SECRET_KEY'] = 'xampp-terminal-secret-key-12345'
socketio = SocketIO(app, cors_allowed_origins="*", async_mode='eventlet')

# Store active terminal sessions:
# session_id -> {
#     'id': session_id,
#     'fd': fd,
#     'pid': pid,
#     'sid': sid (or None if detached),
#     'created_at': float,
#     'last_active': float,
#     'history': deque(maxlen=2000),
#     'cols': int,
#     'rows': int,
#     'cwd': str,
#     'is_alive': bool
# }
sessions = {}

# Map sid -> set of session_ids attached
sid_to_sessions = {}

def set_winsize(fd, row, col, xpix=0, ypix=0):
    try:
        winsize = struct.pack("HHHH", row, col, xpix, ypix)
        fcntl.ioctl(fd, termios.TIOCSWINSZ, winsize)
    except Exception:
        pass

def terminate_session(session_id):
    """Safely terminate a session, kill child process, and close file descriptor."""
    sess = sessions.get(session_id)
    if not sess:
        return
    sess['is_alive'] = False
    sess['sid'] = None
    
    # Close FD
    if sess.get('fd') is not None:
        try:
            os.close(sess['fd'])
        except Exception:
            pass
        sess['fd'] = None

    # Kill Process
    pid = sess.get('pid')
    if pid:
        try:
            os.kill(pid, signal.SIGTERM)
            time.sleep(0.05)
            os.kill(pid, signal.SIGKILL)
        except Exception:
            pass
        try:
            os.waitpid(pid, os.WNOHANG)
        except Exception:
            pass
        sess['pid'] = None

    sessions.pop(session_id, None)

def read_and_forward_pty(session_id):
    """Background reader greenlet that reads from OS PTY, saves scrollback history, and emits to attached client."""
    max_read_bytes = 1024 * 20
    
    while True:
        if session_id not in sessions:
            break
        sess = sessions[session_id]
        if not sess.get('is_alive') or sess.get('fd') is None:
            break
        
        fd = sess['fd']
        socketio.sleep(0.015)
        try:
            r, _, _ = select.select([fd], [], [], 0.05)
            if r:
                try:
                    data = os.read(fd, max_read_bytes)
                except (OSError, IOError):
                    # EOF or PTY closed by child process
                    break
                
                if not data:
                    break
                
                output = data.decode('utf-8', errors='replace')
                sess['history'].append(output)
                sess['last_active'] = time.time()
                
                # If a client is currently attached, emit in real-time
                current_sid = sess.get('sid')
                if current_sid:
                    socketio.emit('pty-output', {'session_id': session_id, 'output': output}, to=current_sid)
        except Exception:
            break

    # If loop exits, the process ended
    if session_id in sessions:
        sess = sessions[session_id]
        sess['is_alive'] = False
        term_msg = '\r\n\x1b[1;31m[Process Exited / Session Ended]\x1b[0m\r\n'
        sess['history'].append(term_msg)
        current_sid = sess.get('sid')
        if current_sid:
            socketio.emit('pty-output', {'session_id': session_id, 'output': term_msg}, to=current_sid)
            socketio.emit('session-ended', {'session_id': session_id}, to=current_sid)
        
        # Clean up fd/pid
        if sess.get('fd') is not None:
            try:
                os.close(sess['fd'])
            except Exception:
                pass
            sess['fd'] = None
        if sess.get('pid') is not None:
            try:
                os.waitpid(sess['pid'], os.WNOHANG)
            except Exception:
                pass
            sess['pid'] = None

@app.route('/health')
def health():
    active_count = sum(1 for s in sessions.values() if s.get('is_alive'))
    return jsonify({
        "status": "running",
        "active_sessions": active_count,
        "total_sessions": len(sessions)
    })

@app.route('/api/sessions', methods=['GET'])
def api_list_sessions():
    res = []
    for sid_key, sess in sessions.items():
        res.append({
            "session_id": sid_key,
            "pid": sess.get('pid'),
            "is_alive": sess.get('is_alive', False),
            "attached": sess.get('sid') is not None,
            "created_at": sess.get('created_at'),
            "last_active": sess.get('last_active'),
            "cwd": sess.get('cwd', '/root')
        })
    return jsonify({"sessions": res})

@socketio.on('connect')
def handle_connect():
    sid_to_sessions[request.sid] = set()

@socketio.on('start-terminal')
def handle_start_terminal(data):
    sid = request.sid
    session_id = data.get('session_id')
    force_new = data.get('force_new', False)
    cols = int(data.get('cols', 100))
    rows = int(data.get('rows', 30))
    cwd = data.get('cwd', '/root')
    if not os.path.isdir(cwd):
        cwd = '/root'

    # If session already exists and alive, and not forcing new: resume existing session!
    if session_id and session_id in sessions and sessions[session_id].get('is_alive') and not force_new:
        sess = sessions[session_id]
        sess['sid'] = sid
        sess['cols'] = cols
        sess['rows'] = rows
        if sess.get('fd') is not None:
            set_winsize(sess['fd'], rows, cols)
        
        sid_to_sessions.setdefault(sid, set()).add(session_id)
        
        # Send back session-resumed with the complete scrollback history
        full_history = ''.join(sess['history'])
        emit('session-resumed', {
            'session_id': session_id,
            'history': full_history,
            'cols': cols,
            'rows': rows,
            'cwd': sess.get('cwd', '/root'),
            'pid': sess.get('pid')
        })
        return

    # If session exists but forcing new, clean up old session
    if session_id and session_id in sessions:
        terminate_session(session_id)

    if not session_id:
        session_id = f"term-{uuid.uuid4().hex[:8]}"

    # Fork a new pseudo-terminal
    (pid, fd) = pty.fork()
    if pid == 0:
        # Child Process
        os.chdir(cwd)
        os.environ['TERM'] = 'xterm-256color'
        os.environ['HOME'] = '/root'
        os.environ['PATH'] = '/root/.local/bin:/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin'
        os.environ['LANG'] = 'en_US.UTF-8'
        os.environ['LC_ALL'] = 'en_US.UTF-8'
        
        shell = os.environ.get('SHELL', '/bin/bash')
        os.execlp(shell, shell, "-l")
    else:
        # Parent Process
        set_winsize(fd, rows, cols)
        sessions[session_id] = {
            'id': session_id,
            'fd': fd,
            'pid': pid,
            'sid': sid,
            'created_at': time.time(),
            'last_active': time.time(),
            'history': deque(maxlen=3000),
            'cols': cols,
            'rows': rows,
            'cwd': cwd,
            'is_alive': True
        }
        sid_to_sessions.setdefault(sid, set()).add(session_id)
        
        socketio.start_background_task(read_and_forward_pty, session_id)
        
        emit('session-started', {
            'session_id': session_id,
            'cols': cols,
            'rows': rows,
            'cwd': cwd,
            'pid': pid
        })

@socketio.on('resume-terminal')
def handle_resume_terminal(data):
    sid = request.sid
    session_id = data.get('session_id')
    cols = int(data.get('cols', 100))
    rows = int(data.get('rows', 30))
    
    if session_id and session_id in sessions and sessions[session_id].get('is_alive'):
        sess = sessions[session_id]
        sess['sid'] = sid
        sess['cols'] = cols
        sess['rows'] = rows
        if sess.get('fd') is not None:
            set_winsize(sess['fd'], rows, cols)
            
        sid_to_sessions.setdefault(sid, set()).add(session_id)
        
        full_history = ''.join(sess['history'])
        emit('session-resumed', {
            'session_id': session_id,
            'history': full_history,
            'cols': cols,
            'rows': rows,
            'cwd': sess.get('cwd', '/root'),
            'pid': sess.get('pid')
        })
    else:
        emit('session-not-found', {'session_id': session_id})

@socketio.on('list-sessions')
def handle_list_sessions():
    res = []
    for sid_key, sess in sessions.items():
        if sess.get('is_alive'):
            res.append({
                "session_id": sid_key,
                "pid": sess.get('pid'),
                "attached": sess.get('sid') is not None,
                "created_at": sess.get('created_at'),
                "last_active": sess.get('last_active'),
                "cwd": sess.get('cwd', '/root')
            })
    emit('sessions-list', {'sessions': res})

@socketio.on('kill-session')
def handle_kill_session(data):
    session_id = data.get('session_id')
    if session_id and session_id in sessions:
        terminate_session(session_id)
        emit('session-killed', {'session_id': session_id})

@socketio.on('pty-input')
def handle_pty_input(data):
    session_id = data.get('session_id')
    inp = data.get('input', '')
    if not inp:
        return

    # If session_id not specified, find by sid
    sess = None
    if session_id and session_id in sessions:
        sess = sessions[session_id]
    else:
        # Look up by sid
        for s in sessions.values():
            if s.get('sid') == request.sid and s.get('is_alive'):
                sess = s
                break

    if sess and sess.get('is_alive') and sess.get('fd') is not None:
        try:
            os.write(sess['fd'], inp.encode('utf-8'))
        except Exception:
            pass

@socketio.on('resize')
def handle_resize(data):
    session_id = data.get('session_id')
    cols = int(data.get('cols', 80))
    rows = int(data.get('rows', 24))
    
    sess = None
    if session_id and session_id in sessions:
        sess = sessions[session_id]
    else:
        for s in sessions.values():
            if s.get('sid') == request.sid and s.get('is_alive'):
                sess = s
                break

    if sess and sess.get('fd') is not None:
        sess['cols'] = cols
        sess['rows'] = rows
        set_winsize(sess['fd'], rows, cols)

@socketio.on('disconnect')
def handle_disconnect():
    sid = request.sid
    # Detach socket sid from all its sessions, but DO NOT kill the terminal sessions!
    # Background commands and shells will continue running.
    attached_sessions = sid_to_sessions.pop(sid, set())
    for s_id in attached_sessions:
        if s_id in sessions and sessions[s_id].get('sid') == sid:
            sessions[s_id]['sid'] = None

    # Also check any session that had this sid
    for s_id, sess in sessions.items():
        if sess.get('sid') == sid:
            sess['sid'] = None

if __name__ == '__main__':
    print("Starting Terminal Bridge with session persistence on 0.0.0.0:8765...")
    socketio.run(app, host='0.0.0.0', port=8765, debug=False)
