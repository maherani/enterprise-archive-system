<?php
declare(strict_types=1);
/** @var array $_ */
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Enterprise Archive - AI API Documentation & Testing</title>
    <style>
        :root {
            --bg-color: #0d1117;
            --panel-bg: #161b22;
            --border-color: #30363d;
            --text-main: #c9d1d9;
            --text-muted: #8b949e;
            --accent-blue: #58a6ff;
            --accent-green: #238636;
            --accent-purple: #8957e5;
            --accent-red: #f85149;
            --get-color: #1f6feb;
            --get-bg: rgba(31, 111, 235, 0.1);
        }
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
            background: var(--bg-color);
            color: var(--text-main);
            line-height: 1.5;
            padding: 0;
            margin: 0;
        }
        .topbar {
            background: #010409;
            border-bottom: 1px solid var(--border-color);
            padding: 14px 28px;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        .topbar-brand {
            display: flex;
            align-items: center;
            gap: 12px;
            font-weight: 700;
            font-size: 1.15rem;
            color: #fff;
        }
        .topbar-brand span.badge {
            font-size: 0.75rem;
            background: var(--accent-purple);
            color: #fff;
            padding: 2px 8px;
            border-radius: 12px;
            font-weight: 600;
        }
        .auth-btn {
            background: var(--accent-green);
            color: #fff;
            border: 1px solid rgba(255,255,255,0.1);
            padding: 7px 18px;
            border-radius: 6px;
            font-weight: 600;
            font-size: 0.9rem;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 8px;
            transition: all 0.2s;
        }
        .auth-btn:hover { background: #2ea043; }
        .auth-btn.active {
            background: #388bfd;
        }
        .container {
            max-width: 1100px;
            margin: 30px auto;
            padding: 0 20px;
        }
        .info-card {
            background: var(--panel-bg);
            border: 1px solid var(--border-color);
            border-radius: 8px;
            padding: 24px;
            margin-bottom: 24px;
        }
        .info-card h1 {
            font-size: 1.8rem;
            margin-bottom: 8px;
            color: #fff;
        }
        .info-card p {
            color: var(--text-muted);
            margin-bottom: 14px;
        }
        .spec-url {
            display: inline-block;
            font-size: 0.85rem;
            color: var(--accent-blue);
            text-decoration: none;
            background: rgba(88, 166, 255, 0.1);
            padding: 4px 10px;
            border-radius: 4px;
            border: 1px solid rgba(88, 166, 255, 0.2);
        }
        .endpoint-card {
            background: var(--panel-bg);
            border: 1px solid var(--border-color);
            border-radius: 8px;
            margin-bottom: 18px;
            overflow: hidden;
        }
        .endpoint-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 14px 20px;
            background: var(--get-bg);
            border-bottom: 1px solid var(--border-color);
            cursor: pointer;
            user-select: none;
        }
        .endpoint-left {
            display: flex;
            align-items: center;
            gap: 14px;
        }
        .method-tag {
            background: var(--get-color);
            color: #fff;
            padding: 4px 12px;
            border-radius: 4px;
            font-weight: 700;
            font-size: 0.85rem;
            text-transform: uppercase;
        }
        .endpoint-path {
            font-family: SFMono-Regular, Consolas, "Liberation Mono", Menlo, monospace;
            font-weight: 600;
            font-size: 1rem;
            color: #fff;
        }
        .endpoint-desc {
            color: var(--text-muted);
            font-size: 0.9rem;
        }
        .endpoint-body {
            padding: 20px;
        }
        .section-title {
            font-size: 0.95rem;
            font-weight: 600;
            color: var(--text-main);
            margin-bottom: 12px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .param-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 20px;
        }
        .param-table th, .param-table td {
            text-align: left;
            padding: 10px 14px;
            border-bottom: 1px solid var(--border-color);
            font-size: 0.9rem;
        }
        .param-table th {
            color: var(--text-muted);
            background: #0d1117;
        }
        .param-input {
            background: #0d1117;
            border: 1px solid var(--border-color);
            color: #fff;
            padding: 8px 12px;
            border-radius: 6px;
            font-size: 0.9rem;
            width: 100%;
            font-family: inherit;
        }
        .param-input:focus {
            outline: none;
            border-color: var(--accent-blue);
        }
        .actions-row {
            display: flex;
            gap: 12px;
            margin-bottom: 20px;
        }
        .btn-try {
            background: transparent;
            border: 1px solid var(--accent-blue);
            color: var(--accent-blue);
            padding: 8px 20px;
            border-radius: 6px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s;
        }
        .btn-try:hover { background: rgba(88, 166, 255, 0.1); }
        .btn-execute {
            background: var(--get-color);
            border: none;
            color: #fff;
            padding: 8px 24px;
            border-radius: 6px;
            font-weight: 600;
            cursor: pointer;
            display: none;
        }
        .btn-execute:hover { background: #388bfd; }
        .response-box {
            background: #010409;
            border: 1px solid var(--border-color);
            border-radius: 6px;
            padding: 16px;
            margin-top: 16px;
            display: none;
        }
        .response-status {
            display: inline-block;
            padding: 4px 10px;
            border-radius: 4px;
            font-weight: 700;
            font-size: 0.85rem;
            margin-bottom: 12px;
        }
        .status-200 { background: rgba(35, 134, 54, 0.2); color: #3fb950; border: 1px solid #238636; }
        .status-401, .status-403 { background: rgba(248, 81, 73, 0.2); color: #f85149; border: 1px solid #da3633; }
        .status-404 { background: rgba(210, 153, 34, 0.2); color: #d29922; border: 1px solid #9e6a03; }
        pre.code-output {
            background: #0d1117;
            border: 1px solid var(--border-color);
            padding: 12px;
            border-radius: 6px;
            font-family: SFMono-Regular, Consolas, monospace;
            font-size: 0.85rem;
            color: #58a6ff;
            overflow-x: auto;
            max-height: 350px;
        }
        .btn-download {
            background: var(--accent-green);
            color: #fff;
            padding: 8px 18px;
            border-radius: 6px;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            font-weight: 600;
            margin-top: 10px;
        }
        /* Modal for Authentication */
        .modal-overlay {
            position: fixed;
            top: 0; left: 0; right: 0; bottom: 0;
            background: rgba(0, 0, 0, 0.7);
            display: none;
            align-items: center;
            justify-content: center;
            z-index: 9999;
        }
        .modal {
            background: var(--panel-bg);
            border: 1px solid var(--border-color);
            border-radius: 8px;
            width: 500px;
            max-width: 90%;
            padding: 24px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.5);
        }
        .modal h2 { margin-bottom: 16px; color: #fff; font-size: 1.3rem; }
        .modal-tabs { display: flex; gap: 10px; margin-bottom: 16px; border-bottom: 1px solid var(--border-color); padding-bottom: 8px; }
        .tab-btn { background: none; border: none; color: var(--text-muted); padding: 6px 12px; cursor: pointer; font-weight: 600; font-size: 0.9rem; }
        .tab-btn.active { color: var(--accent-blue); border-bottom: 2px solid var(--accent-blue); }
        .form-group { margin-bottom: 14px; }
        .form-group label { display: block; margin-bottom: 6px; font-size: 0.85rem; color: var(--text-muted); }
        .modal-actions { display: flex; justify-content: flex-end; gap: 10px; margin-top: 20px; }
    </style>
</head>
<body>

<header class="topbar">
    <div class="topbar-brand">
        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 2L2 7l10 5 10-5-10-5zM2 17l10 5 10-5M2 12l10 5 10-5"/></svg>
        <span>Enterprise Archive AI Swagger UI</span>
        <span class="badge">OpenAPI 3.0.3</span>
    </div>
    <button id="btn-auth" class="auth-btn" onclick="openAuthModal()">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
        <span id="auth-status-text">Authorize</span>
    </button>
</header>

<div class="container">
    <div class="info-card">
        <h1>Enterprise Archive AI File Retrieval API</h1>
        <p>Air-Gapped, secure, permission-enforced API providing stream delivery of archive documents to local AI assistants with strict ACL boundaries, zero data egress, and automated audit trails.</p>
        <a class="spec-url" href="/index.php/apps/archive_autotag/api/openapi.json" target="_blank">
            Raw OpenAPI 3.0 Spec (JSON)
        </a>
    </div>

    <!-- Endpoint 1: Stream File -->
    <div class="endpoint-card">
        <div class="endpoint-header" onclick="toggleEndpoint('ep1')">
            <div class="endpoint-left">
                <span class="method-tag">GET</span>
                <span class="endpoint-path">/api/v1/ai/files/{fileId}</span>
                <span class="endpoint-desc">Stream Authorized Archive File</span>
            </div>
            <span>▾</span>
        </div>
        <div id="ep1-body" class="endpoint-body">
            <div class="section-title">Parameters</div>
            <table class="param-table">
                <thead>
                    <tr><th>Name</th><th>In</th><th>Type</th><th>Value</th><th>Description</th></tr>
                </thead>
                <tbody>
                    <tr>
                        <td><strong>fileId</strong> *</td>
                        <td>path</td>
                        <td>integer</td>
                        <td><input type="number" id="ep1-fileId" class="param-input" placeholder="e.g. 660" disabled /></td>
                        <td>Target archive file numeric identifier</td>
                    </tr>
                    <tr>
                        <td>X-On-Behalf-Of</td>
                        <td>header</td>
                        <td>string</td>
                        <td><input type="text" id="ep1-onbehalf" class="param-input" placeholder="e.g. archive_user1 (optional)" disabled /></td>
                        <td>Delegated user UID when using AI Service Token</td>
                    </tr>
                    <tr>
                        <td>X-Client-ID</td>
                        <td>header</td>
                        <td>string</td>
                        <td><input type="text" id="ep1-clientid" class="param-input" placeholder="swagger-ui" value="swagger-ui" disabled /></td>
                        <td>Calling agent identifier for audit trail</td>
                    </tr>
                </tbody>
            </table>

            <div class="actions-row">
                <button id="ep1-btn-try" class="btn-try" onclick="enableTry('ep1')">Try it out</button>
                <button id="ep1-btn-exec" class="btn-execute" onclick="executeGetFile()">Execute</button>
            </div>

            <div id="ep1-response" class="response-box">
                <div class="section-title">Server Response</div>
                <div>Status: <span id="ep1-status" class="response-status"></span></div>
                <div style="margin: 8px 0; color: var(--text-muted); font-size: 0.85rem;">Response Headers:</div>
                <pre id="ep1-headers" class="code-output" style="max-height: 120px;"></pre>
                <div style="margin: 8px 0; color: var(--text-muted); font-size: 0.85rem;">Response Body:</div>
                <div id="ep1-file-download-area" style="display:none;"></div>
                <pre id="ep1-body-output" class="code-output"></pre>
            </div>
        </div>
    </div>

    <!-- Endpoint 2: Metadata -->
    <div class="endpoint-card">
        <div class="endpoint-header" onclick="toggleEndpoint('ep2')">
            <div class="endpoint-left">
                <span class="method-tag">GET</span>
                <span class="endpoint-path">/api/v1/ai/files/{fileId}/metadata</span>
                <span class="endpoint-desc">Get File Metadata & Tags</span>
            </div>
            <span>▾</span>
        </div>
        <div id="ep2-body" class="endpoint-body">
            <div class="section-title">Parameters</div>
            <table class="param-table">
                <thead>
                    <tr><th>Name</th><th>In</th><th>Type</th><th>Value</th><th>Description</th></tr>
                </thead>
                <tbody>
                    <tr>
                        <td><strong>fileId</strong> *</td>
                        <td>path</td>
                        <td>integer</td>
                        <td><input type="number" id="ep2-fileId" class="param-input" placeholder="e.g. 660" disabled /></td>
                        <td>Target archive file numeric identifier</td>
                    </tr>
                </tbody>
            </table>

            <div class="actions-row">
                <button id="ep2-btn-try" class="btn-try" onclick="enableTry('ep2')">Try it out</button>
                <button id="ep2-btn-exec" class="btn-execute" onclick="executeGetMetadata()">Execute</button>
            </div>

            <div id="ep2-response" class="response-box">
                <div class="section-title">Server Response</div>
                <div>Status: <span id="ep2-status" class="response-status"></span></div>
                <pre id="ep2-body-output" class="code-output"></pre>
            </div>
        </div>
    </div>
</div>

<!-- Modal Authorize -->
<div id="auth-modal" class="modal-overlay">
    <div class="modal">
        <h2>Available Authorizations</h2>
        <div class="modal-tabs">
            <button id="tab-basic-btn" class="tab-btn active" onclick="switchAuthTab('basic')">Basic Auth (User)</button>
            <button id="tab-bearer-btn" class="tab-btn" onclick="switchAuthTab('bearer')">Bearer / API Token</button>
        </div>

        <div id="tab-basic">
            <p style="font-size: 0.85rem; color: var(--text-muted); margin-bottom: 12px;">Nextcloud username and password or personal App Password.</p>
            <div class="form-group">
                <label>Username:</label>
                <input type="text" id="auth-user" class="param-input" placeholder="e.g. archive_user1 or admin" />
            </div>
            <div class="form-group">
                <label>Password / App Token:</label>
                <input type="password" id="auth-pass" class="param-input" placeholder="••••••••" />
            </div>
        </div>

        <div id="tab-bearer" style="display:none;">
            <p style="font-size: 0.85rem; color: var(--text-muted); margin-bottom: 12px;">Dedicated AI Service Bearer Token.</p>
            <div class="form-group">
                <label>Bearer Token / API Key:</label>
                <input type="text" id="auth-token" class="param-input" placeholder="ai_sec_token_..." />
            </div>
        </div>

        <div class="modal-actions">
            <button class="btn-try" onclick="closeAuthModal()">Cancel</button>
            <button class="auth-btn" onclick="saveAuth()">Authorize & Save</button>
        </div>
    </div>
</div>

<script>
let authConfig = {
    type: 'none',
    user: '',
    pass: '',
    token: ''
};

function toggleEndpoint(id) {
    const el = document.getElementById(id + '-body');
    el.style.display = el.style.display === 'none' ? 'block' : 'none';
}

function openAuthModal() {
    document.getElementById('auth-modal').style.display = 'flex';
}

function closeAuthModal() {
    document.getElementById('auth-modal').style.display = 'none';
}

function switchAuthTab(tab) {
    document.getElementById('tab-basic-btn').classList.toggle('active', tab === 'basic');
    document.getElementById('tab-bearer-btn').classList.toggle('active', tab === 'bearer');
    document.getElementById('tab-basic').style.display = tab === 'basic' ? 'block' : 'none';
    document.getElementById('tab-bearer').style.display = tab === 'bearer' ? 'block' : 'none';
}

function saveAuth() {
    const isBasic = document.getElementById('tab-basic-btn').classList.contains('active');
    if (isBasic) {
        authConfig.type = 'basic';
        authConfig.user = document.getElementById('auth-user').value.trim();
        authConfig.pass = document.getElementById('auth-pass').value.trim();
    } else {
        authConfig.type = 'bearer';
        authConfig.token = document.getElementById('auth-token').value.trim();
    }

    const btn = document.getElementById('btn-auth');
    const text = document.getElementById('auth-status-text');
    if ((authConfig.type === 'basic' && authConfig.user) || (authConfig.type === 'bearer' && authConfig.token)) {
        btn.classList.add('active');
        text.innerText = 'Authorized (' + (authConfig.type === 'basic' ? authConfig.user : 'Bearer Token') + ')';
    } else {
        btn.classList.remove('active');
        text.innerText = 'Authorize';
    }
    closeAuthModal();
}

function enableTry(ep) {
    const inputs = document.querySelectorAll('#' + ep + '-body .param-input');
    inputs.forEach(inp => inp.disabled = false);
    document.getElementById(ep + '-btn-try').style.display = 'none';
    document.getElementById(ep + '-btn-exec').style.display = 'inline-block';
}

function getRequestHeaders(extraHeaders = {}) {
    const headers = { ...extraHeaders };
    if (authConfig.type === 'basic' && authConfig.user && authConfig.pass) {
        headers['Authorization'] = 'Basic ' + btoa(authConfig.user + ':' + authConfig.pass);
    } else if (authConfig.type === 'bearer' && authConfig.token) {
        headers['Authorization'] = 'Bearer ' + authConfig.token;
    }
    return headers;
}

async function executeGetFile() {
    const fileId = document.getElementById('ep1-fileId').value.trim();
    if (!fileId) { alert('Please enter fileId'); return; }

    const onBehalf = document.getElementById('ep1-onbehalf').value.trim();
    const clientId = document.getElementById('ep1-clientid').value.trim();

    const extra = {};
    if (onBehalf) extra['X-On-Behalf-Of'] = onBehalf;
    if (clientId) extra['X-Client-ID'] = clientId;

    const url = '/index.php/apps/archive_autotag/api/v1/ai/files/' + fileId;
    const respBox = document.getElementById('ep1-response');
    const statusEl = document.getElementById('ep1-status');
    const headersEl = document.getElementById('ep1-headers');
    const bodyEl = document.getElementById('ep1-body-output');
    const dlArea = document.getElementById('ep1-file-download-area');

    respBox.style.display = 'block';
    statusEl.innerText = 'Loading...';
    bodyEl.innerText = '';
    dlArea.style.display = 'none';
    dlArea.innerHTML = '';

    try {
        const res = await fetch(url, {
            headers: getRequestHeaders(extra)
        });

        statusEl.innerText = res.status + ' ' + res.statusText;
        statusEl.className = 'response-status status-' + res.status;

        // Print Headers
        let headerStr = '';
        res.headers.forEach((v, k) => { headerStr += k + ': ' + v + '\n'; });
        headersEl.innerText = headerStr;

        const contentType = res.headers.get('content-type') || '';
        if (res.status === 200 && !contentType.includes('application/json')) {
            const blob = await res.blob();
            const blobUrl = URL.createObjectURL(blob);
            dlArea.style.display = 'block';
            dlArea.innerHTML = '<a href="' + blobUrl + '" download="archive_file_' + fileId + '" class="btn-download"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg> Download Streamed File (' + Math.round(blob.size / 1024) + ' KB)</a>';
            bodyEl.innerText = '[Binary Stream Received: ' + blob.size + ' bytes, Type: ' + contentType + ']';
        } else {
            const text = await res.text();
            try {
                bodyEl.innerText = JSON.stringify(JSON.parse(text), null, 2);
            } catch (e) {
                bodyEl.innerText = text;
            }
        }
    } catch (err) {
        statusEl.innerText = 'Network / Fetch Error';
        statusEl.className = 'response-status status-401';
        bodyEl.innerText = err.message;
    }
}

async function executeGetMetadata() {
    const fileId = document.getElementById('ep2-fileId').value.trim();
    if (!fileId) { alert('Please enter fileId'); return; }

    const url = '/index.php/apps/archive_autotag/api/v1/ai/files/' + fileId + '/metadata';
    const respBox = document.getElementById('ep2-response');
    const statusEl = document.getElementById('ep2-status');
    const bodyEl = document.getElementById('ep2-body-output');

    respBox.style.display = 'block';
    statusEl.innerText = 'Loading...';

    try {
        const res = await fetch(url, {
            headers: getRequestHeaders()
        });

        statusEl.innerText = res.status + ' ' + res.statusText;
        statusEl.className = 'response-status status-' + res.status;

        const data = await res.json();
        bodyEl.innerText = JSON.stringify(data, null, 2);
    } catch (err) {
        statusEl.innerText = 'Network / Fetch Error';
        statusEl.className = 'response-status status-401';
        bodyEl.innerText = err.message;
    }
}
</script>

</body>
</html>
