<?php
$uploadDir = __DIR__ . '/u/';
$baseUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http')
    . '://' . $_SERVER['HTTP_HOST'];

function rand_str($len = 16) {
    $chars = 'abcdefghijklmnopqrstuvwxyz0123456789';
    $out = '';
    for ($i = 0; $i < $len; $i++) $out .= $chars[random_int(0, strlen($chars) - 1)];
    return $out;
}

function human_bytes($bytes) {
    $units = ['B','KB','MB','GB','TB'];
    $i = 0;
    while ($bytes >= 1024 && $i < count($units)-1) { $bytes /= 1024; $i++; }
    return sprintf('%.2f %s', $bytes, $units[$i]);
}

// Ensure base upload directory exists
if (!is_dir($uploadDir)) @mkdir($uploadDir, 0755, true);

// POST Handling (Standard & Chunked)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_GET['ajax'])) {
    header('Content-Type: application/json; charset=utf-8');

    if (!is_writable($uploadDir)) {
        echo json_encode(['ok' => false, 'error' => 'Upload directory not writable.']); exit;
    }

    $action = $_POST['action'] ?? 'standard';

    // Action A: Standard Single-Request Upload (Fast Mode)
    if ($action === 'standard') {
        if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
            echo json_encode(['ok' => false, 'error' => 'Upload failed.']); exit;
        }
        $size = (int)($_FILES['file']['size'] ?? 0);
        $id   = rand_str(16);
        $dest = $uploadDir . $id;

        if (!move_uploaded_file($_FILES['file']['tmp_name'], $dest)) {
            echo json_encode(['ok' => false, 'error' => 'Could not save file.']); exit;
        }
        @chmod($dest, 0644);

        echo json_encode([
            'ok'   => true,
            'id'   => $id,
            'base' => $baseUrl . '/fileshare/?v=' . $id,
            'size' => human_bytes($size),
        ]);
        exit;
    }

    // Action B: Save Individual Chunk to a Folder (Deep Mode)
    if ($action === 'chunk') {
        $fileId = preg_replace('/[^a-z0-9]/', '', $_POST['file_id'] ?? '');
        if (!$fileId || strlen($fileId) !== 16 || !isset($_FILES['file_chunk'])) {
            echo json_encode(['ok' => false, 'error' => 'Chunk upload failed.']); exit;
        }

        $chunkIndex = (int)($_POST['chunk_index'] ?? 0);
        $targetDir = $uploadDir . $fileId;

        // Create a folder specifically for this file's chunks
        if (!is_dir($targetDir)) { @mkdir($targetDir, 0755, true); }

        $chunkDest = $targetDir . '/' . $chunkIndex;

        if (!move_uploaded_file($_FILES['file_chunk']['tmp_name'], $chunkDest)) {
            echo json_encode(['ok' => false, 'error' => 'Could not save chunk.']); exit;
        }
        echo json_encode(['ok' => true]);
        exit;
    }

    // Action C: Finalize (Virtual Stitching)
    if ($action === 'finalize') {
        $fileId = preg_replace('/[^a-z0-9]/', '', $_POST['file_id'] ?? '');
        $totalChunks = (int)($_POST['total_chunks'] ?? 0);
        $targetDir = $uploadDir . $fileId;

        if (!$fileId || strlen($fileId) !== 16 || !is_dir($targetDir)) {
            echo json_encode(['ok' => false, 'error' => 'Invalid file ID or missing chunks.']); exit;
        }

        // Verify all chunks exist and calculate total size
        $totalSize = 0;
        for ($i = 0; $i < $totalChunks; $i++) {
            $chunkPath = $targetDir . '/' . $i;
            if (!file_exists($chunkPath)) {
                echo json_encode(['ok' => false, 'error' => 'Missing chunk part ' . $i]); exit;
            }
            $totalSize += filesize($chunkPath);
        }

        // Save a tiny metadata file so the fetcher knows how to serve it
        $chunkSize = (int)($_POST['chunk_size'] ?? 1048576);
        $meta = ['chunks' => $totalChunks, 'size' => $totalSize, 'chunk_size' => $chunkSize];
        file_put_contents($targetDir . '/meta.json', json_encode($meta));

        echo json_encode([
            'ok'   => true,
            'id'   => $fileId,
            'base' => $baseUrl . '/fileshare/?v=' . $fileId,
            'size' => human_bytes($totalSize),
        ]);
        exit;
    }

    echo json_encode(['ok' => false, 'error' => 'Unknown action.']); exit;
}

// Serve raw ciphertext (The Virtual Stream)
if (isset($_GET['fetch']) && preg_match('/^[a-z0-9]{16}$/', $_GET['fetch'])) {
    $path = $uploadDir . $_GET['fetch'];

    // Scenario 1: It is a standard single file
    if (is_file($path)) {
        header('Content-Type: application/octet-stream');
        header('Content-Length: ' . filesize($path));
        header('Cache-Control: no-store');
        header('X-Chunked: false');
        readfile($path);
        exit;
    }
    // Scenario 2: It is a folder of chunks (Virtual Stream)
    elseif (is_dir($path) && file_exists($path . '/meta.json')) {
        $meta = json_decode(file_get_contents($path . '/meta.json'), true);

        header('Content-Type: application/octet-stream');
        header('Content-Length: ' . $meta['size']);
        header('Cache-Control: no-store');
        header('X-Chunked: true');
        header('X-Chunk-Size: ' . ($meta['chunk_size'] ?? 1048576));

        // Rapidly output chunks directly to the browser one after another
        for ($i = 0; $i < $meta['chunks']; $i++) {
            readfile($path . '/' . $i);
        }
        exit;
    }

    http_response_code(404); exit;
}

// Download page
$viewId = null;
if (isset($_GET['v']) && preg_match('/^[a-z0-9]{16}$/', $_GET['v'])) {
    $viewId = $_GET['v'];
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title><?= $viewId ? 'File' : 'Upload' ?> - kei.mt</title>
  <style>
    * { box-sizing: border-box; margin: 0; padding: 0; }
    body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif; font-size: 15px; background: #111; color: #d0d0d0; min-height: 100vh; display: flex; align-items: center; justify-content: center; padding: 32px 20px; }
    .wrap { width: 100%; max-width: 520px; }
    h1 { font-size: 1.1rem; font-weight: 600; margin-bottom: 4px; color: #fff; }
    .sub { color: #555; font-size: 0.82rem; margin-bottom: 32px; }
    .drop { border: 1.5px dashed #2e2e2e; border-radius: 8px; padding: 32px 20px; text-align: center; color: #555; font-size: 0.85rem; cursor: pointer; position: relative; transition: border-color .15s, color .15s; }
    .drop:hover, .drop.over { border-color: #555; color: #999; }
    .drop input { position: absolute; inset: 0; opacity: 0; cursor: pointer; }
    .drop .picked { margin-top: 8px; font-size: 0.78rem; color: #888; min-height: 1em; }
    .mode-sel { display: flex; gap: 12px; margin-top: 16px; margin-bottom: 20px; }
    .mode-lbl { flex: 1; border: 1.5px solid #2e2e2e; border-radius: 8px; padding: 12px; cursor: pointer; position: relative; background: #161616; transition: border-color .15s, background .15s; }
    .mode-lbl:hover { border-color: #555; background: #1a1a1a; }
    .mode-lbl input { display: none; }
    .mode-lbl input:checked + .txt .m-title { color: #fff; }
    .mode-lbl input:checked ~ .ind { background: #e0e0e0; border-color: #e0e0e0; }
    .m-title { font-size: 0.85rem; font-weight: 600; color: #888; margin-bottom: 4px; transition: color .15s; }
    .m-desc { font-size: 0.72rem; color: #555; line-height: 1.4; }
    .ind { position: absolute; top: 14px; right: 14px; width: 12px; height: 12px; border-radius: 50%; border: 1.5px solid #444; transition: all .15s; }
    .actions { display: flex; gap: 8px; margin-top: 16px; }
    button { padding: 9px 18px; border-radius: 6px; border: 1.5px solid #2e2e2e; background: #1a1a1a; color: #aaa; cursor: pointer; font-size: 0.82rem; font-family: inherit; transition: background .12s, border-color .12s, color .12s; }
    button:hover:not(:disabled) { background: #222; border-color: #444; color: #ddd; }
    button.primary { background: #e0e0e0; color: #111; border-color: #e0e0e0; }
    button.primary:hover:not(:disabled) { background: #fff; border-color: #fff; }
    button:disabled { opacity: .35; cursor: not-allowed; }
    .progress { display: none; margin-top: 20px; }
    .track { height: 2px; background: #222; border-radius: 2px; overflow: hidden; margin-bottom: 8px; }
    .fill { height: 100%; width: 0; background: #e0e0e0; transition: width .1s linear; }
    .stats { display: flex; gap: 16px; font-size: 0.75rem; color: #444; flex-wrap: wrap; }
    .stats span b { color: #888; font-weight: 500; }
    .result { margin-top: 24px; }
    .result-link { display: flex; align-items: center; gap: 8px; background: #161616; border: 1.5px solid #2a2a2a; border-radius: 6px; padding: 10px 12px; margin-top: 10px; }
    .result-link code { flex: 1; font-size: 0.75rem; word-break: break-all; color: #888; }
    .result-link button { padding: 5px 12px; font-size: 0.75rem; }
    .notice { font-size: 0.78rem; color: #444; margin-top: 8px; }
    .msg { padding: 10px 14px; border-radius: 6px; font-size: 0.82rem; margin-top: 16px; }
    .msg.ok  { background: #0e1f0e; border: 1px solid #1a3a1a; color: #6abf6a; }
    .msg.err { background: #1f0e0e; border: 1px solid #3a1a1a; color: #bf6a6a; }
    .file-card { border: 1.5px solid #222; border-radius: 8px; padding: 20px; margin-bottom: 20px; background: #161616; }
    .file-name { font-weight: 600; margin-bottom: 4px; word-break: break-all; color: #fff; }
    .file-meta { font-size: 0.78rem; color: #555; }
  </style>
</head>
<body>
<div class="wrap">

<?php if ($viewId): ?>

<h1>File</h1>
<p class="sub">kei.mt</p>

<div class="file-card">
  <div class="file-name" id="fname">Loading…</div>
  <div class="file-meta" id="fmeta"></div>
</div>

<div class="progress" id="prog">
  <div class="track"><div class="fill" id="fill"></div></div>
  <div class="stats">
    <span><b id="ppct">0%</b></span>
    <span id="pstatus"></span>
  </div>
</div>

<div id="out"></div>

<div class="actions" style="margin-top:20px">
  <button class="primary" id="dl-btn">Download</button>
</div>

<script>
(function(){
  const id      = <?= json_encode($viewId) ?>;
  const fnameEl = document.getElementById('fname');
  const fmetaEl = document.getElementById('fmeta');
  const prog    = document.getElementById('prog');
  const fill    = document.getElementById('fill');
  const ppct    = document.getElementById('ppct');
  const pstatus = document.getElementById('pstatus');
  const dlBtn   = document.getElementById('dl-btn');
  const out     = document.getElementById('out');

  function esc(s) { return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); }

  function b64uDec(s) {
    const bin = atob(s.replace(/-/g,'+').replace(/_/g,'/'));
    const out = new Uint8Array(bin.length);
    for (let i = 0; i < bin.length; i++) out[i] = bin.charCodeAt(i);
    return out;
  }

  async function importKey(b64) {
    return crypto.subtle.importKey('raw', b64uDec(b64), {name:'AES-GCM'}, false, ['decrypt']);
  }

  async function decrypt(key, data) {
    const iv = data.slice(0, 12);
    const ct = data.slice(12);
    return new Uint8Array(await crypto.subtle.decrypt({name:'AES-GCM', iv}, key, ct));
  }

  const params = new URLSearchParams(location.hash.slice(1));
  const keyB64 = params.get('k');
  const origName = params.get('n') || 'file';

  if (!keyB64) {
    fnameEl.textContent = 'Invalid link';
    fmetaEl.textContent = 'The link is missing its key. Make sure you copied the full URL.';
    dlBtn.disabled = true;
    return;
  }

  fnameEl.textContent = origName;
  fmetaEl.textContent = ' ';

  dlBtn.onclick = async () => {
    dlBtn.disabled = true;
    prog.style.display = 'block';
    out.innerHTML = '';

    try {
      const key = await importKey(keyB64);
      pstatus.textContent = 'Downloading…';

      const resp = await fetch('?fetch=' + encodeURIComponent(id));
      if (!resp.ok) throw new Error('File not found (HTTP ' + resp.status + ')');

      const len    = parseInt(resp.headers.get('Content-Length') || '0');
      const reader = resp.body.getReader();
      const chunks = [];
      let got = 0;

      while (true) {
        const {done, value} = await reader.read();
        if (done) break;
        chunks.push(value);
        got += value.length;
        if (len) {
          const p = got / len * 100;
          fill.style.width = p + '%';
          ppct.textContent = p.toFixed(0) + '%';
        }
      }

      pstatus.textContent = 'Decrypting…';

      const buf = new Uint8Array(got);
      let off = 0;
      for (const c of chunks) { buf.set(c, off); off += c.length; }

      const isChunked = resp.headers.get('X-Chunked') === 'true';
      let plain;

      if (!isChunked) {
        // Fast Upload: single AES-GCM blob
        plain = await decrypt(key, buf);
      } else {
        // Deep Upload: decrypt each chunk independently (each is: 12B IV + data + 16B GCM tag)
        const chunkSize = parseInt(resp.headers.get('X-Chunk-Size') || '1048576');
        const encChunkSize = chunkSize + 12 + 16;
        const plaintextParts = [];
        let pos = 0;
        while (pos < buf.length) {
          const slice = buf.slice(pos, pos + encChunkSize);
          const partPlain = await decrypt(key, slice);
          plaintextParts.push(partPlain);
          pos += slice.length;
        }
        const totalPlain = plaintextParts.reduce((a, b) => a + b.length, 0);
        plain = new Uint8Array(totalPlain);
        let poff = 0;
        for (const p of plaintextParts) { plain.set(p, poff); poff += p.length; }
      }

      fill.style.width = '100%';
      ppct.textContent = '100%';
      pstatus.textContent = 'Done';

      const blob = new Blob([plain]);
      const url  = URL.createObjectURL(blob);
      const a    = document.createElement('a');
      a.href = url;
      a.download = origName;
      document.body.appendChild(a);
      a.click();
      setTimeout(() => { URL.revokeObjectURL(url); a.remove(); }, 2000);

      dlBtn.textContent = 'Download again';
      dlBtn.disabled = false;

    } catch(e) {
      out.innerHTML = '<div class="msg err">Failed: ' + esc(String(e)) + '</div>';
      dlBtn.disabled = false;
    }
  };
})();
</script>

<?php else: ?>

<h1>Upload</h1>
<p class="sub">kei.mt</p>

<div class="drop" id="drop">
  <input type="file" id="file">
  Drop a file here, or click to choose
  <div class="picked" id="picked"></div>
</div>

<div class="mode-sel">
  <label class="mode-lbl">
    <input type="radio" name="up_mode" value="normal" checked>
    <div class="txt">
      <div class="m-title">Fast Upload</div>
      <div class="m-desc">Max 100MB.<br>One swift request.</div>
    </div>
    <div class="ind"></div>
  </label>
  <label class="mode-lbl">
    <input type="radio" name="up_mode" value="chunked">
    <div class="txt">
      <div class="m-title">Deep Upload</div>
      <div class="m-desc">Max 10GB+.<br>Slower, bypasses limits.</div>
    </div>
    <div class="ind"></div>
  </label>
</div>

<div class="actions">
  <button class="primary" id="btn">Upload</button>
  <button id="cancel" disabled>Cancel</button>
</div>

<div class="progress" id="prog">
  <div class="track"><div class="fill" id="fill"></div></div>
  <div class="stats">
    <span><b id="ppct">0%</b></span>
    <span><b id="psent">0 B</b> / <b id="ptotal">0 B</b></span>
    <span><b id="pspeed">—</b></span>
    <span><b id="peta">—</b></span>
    <span id="pstatus"></span>
  </div>
</div>

<div class="result" id="result"></div>

<script>
(function(){
  const fileEl  = document.getElementById('file');
  const picked  = document.getElementById('picked');
  const drop    = document.getElementById('drop');
  const btn     = document.getElementById('btn');
  const cancel  = document.getElementById('cancel');
  const prog    = document.getElementById('prog');
  const fill    = document.getElementById('fill');
  const ppct    = document.getElementById('ppct');
  const psent   = document.getElementById('psent');
  const ptotal  = document.getElementById('ptotal');
  const pspeed  = document.getElementById('pspeed');
  const peta    = document.getElementById('peta');
  const pstatus = document.getElementById('pstatus');
  const result  = document.getElementById('result');

  let currentXhr = null;
  let isCancelled = false;

  drop.addEventListener('dragover', e => { e.preventDefault(); drop.classList.add('over'); });
  drop.addEventListener('dragleave', () => drop.classList.remove('over'));
  drop.addEventListener('drop', e => {
    e.preventDefault(); drop.classList.remove('over');
    if (e.dataTransfer.files[0]) { fileEl.files = e.dataTransfer.files; picked.textContent = e.dataTransfer.files[0].name; }
  });
  fileEl.addEventListener('change', () => { if (fileEl.files[0]) picked.textContent = fileEl.files[0].name; });

  function fmt(b) {
    const u = ['B','KB','MB','GB']; let i = 0;
    while (b >= 1024 && i < u.length-1) { b /= 1024; i++; }
    return (i ? b.toFixed(2) : b.toFixed(0)) + ' ' + u[i];
  }
  function fmtT(s) {
    if (!isFinite(s) || s < 0) return '—';
    s = Math.round(s);
    const m = Math.floor(s / 60), ss = s % 60;
    return m ? `${m}m ${ss}s` : `${ss}s`;
  }
  function esc(s) { return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); }

  function randId() {
    const chars = 'abcdefghijklmnopqrstuvwxyz0123456789';
    let out = '';
    for (let i = 0; i < 16; i++) out += chars[Math.floor(Math.random() * chars.length)];
    return out;
  }

  function b64uEnc(u8) {
    let bin = '';
    const chunk = 8192;
    for (let i = 0; i < u8.length; i += chunk)
      bin += String.fromCharCode(...u8.subarray(i, i + chunk));
    return btoa(bin).replace(/\+/g,'-').replace(/\//g,'_').replace(/=+$/,'');
  }

  async function encryptFile(bytes) {
    const rawKey = crypto.getRandomValues(new Uint8Array(32));
    const iv     = crypto.getRandomValues(new Uint8Array(12));
    const key    = await crypto.subtle.importKey('raw', rawKey, {name:'AES-GCM'}, false, ['encrypt']);
    const ct     = new Uint8Array(await crypto.subtle.encrypt({name:'AES-GCM', iv}, key, bytes));
    const out    = new Uint8Array(12 + ct.length);
    out.set(iv, 0); out.set(ct, 12);
    return { enc: out, keyB64: b64uEnc(rawKey) };
  }

  cancel.addEventListener('click', () => {
    isCancelled = true;
    if (currentXhr) currentXhr.abort();
  });

  function showSuccess(res, origName, keyB64) {
    fill.style.width = '100%'; ppct.textContent = '100%'; pstatus.textContent = 'Done';
    const link = res.base + '#k=' + keyB64 + '&n=' + encodeURIComponent(origName);

    result.innerHTML = `
      <div style="font-size:.82rem;color:#555;margin-bottom:6px">${esc(origName)} — ${esc(res.size)}</div>
      <div class="result-link">
        <code>${esc(link)}</code>
        <button id="cpybtn">Copy</button>
      </div>
      <div class="notice">Anyone with this link can download the file.</div>
    `;
    document.getElementById('cpybtn').onclick = () => {
      navigator.clipboard.writeText(link).then(() => {
        document.getElementById('cpybtn').textContent = 'Copied';
        setTimeout(() => document.getElementById('cpybtn').textContent = 'Copy', 2000);
      });
    };
  }

  const sleep = ms => new Promise(r => setTimeout(r, ms));

  btn.addEventListener('click', async () => {
    const f = fileEl.files[0];
    if (!f) { result.innerHTML = '<div class="msg err">Please select a file first.</div>'; return; }

    const mode = document.querySelector('input[name="up_mode"]:checked').value;

    result.innerHTML = '';
    prog.style.display = 'block';
    fill.style.width = '0%'; ppct.textContent = '0%';
    psent.textContent = '0 B'; ptotal.textContent = '0 B';
    pspeed.textContent = '—'; peta.textContent = '—';
    btn.disabled = true; cancel.disabled = false;
    pstatus.textContent = 'Encrypting file…';
    isCancelled = false;

    // ──────────────────────────────────────────────────────────
    // METHOD A: FAST (SINGLE REQUEST)
    // ──────────────────────────────────────────────────────────
    if (mode === 'normal') {
      let enc, keyB64;
      try {
        const bytes = new Uint8Array(await f.arrayBuffer());
        ({enc, keyB64} = await encryptFile(bytes));
      } catch(e) {
        result.innerHTML = '<div class="msg err">Encryption failed: ' + esc(e) + '</div>';
        btn.disabled = false; cancel.disabled = true; return;
      }

      const totalBytes = enc.length;
      let bytesSentAccumulated = 0;
      const startTime = Date.now();

      pstatus.textContent = 'Uploading (Fast)…';

      const fd = new FormData();
      fd.append('action', 'standard');
      fd.append('file', new Blob([enc], {type: 'application/octet-stream'}), 'file');

      currentXhr = new XMLHttpRequest();
      currentXhr.open('POST', '?ajax=1', true);
      currentXhr.responseType = 'json';

      currentXhr.upload.onprogress = ev => {
        if (!ev.lengthComputable) return;
        const p = ev.loaded / ev.total * 100;
        fill.style.width = p + '%'; ppct.textContent = p.toFixed(1) + '%';
        psent.textContent = fmt(ev.loaded); ptotal.textContent = fmt(ev.total);
        const now = Date.now(), dt = (now - startTime) / 1000;
        if (dt > 0.25) {
          const spd = ev.loaded / dt;
          pspeed.textContent = fmt(spd) + '/s';
          peta.textContent   = fmtT((ev.total - ev.loaded) / (spd || 1));
        }
      };

      currentXhr.onload = () => {
        cancel.disabled = true; btn.disabled = false;
        const res = currentXhr.response;
        if (!res?.ok) {
          result.innerHTML = '<div class="msg err">' + esc(res?.error || 'Upload failed.') + '</div>';
          pstatus.textContent = 'Failed'; return;
        }
        showSuccess(res, f.name, keyB64);
        currentXhr = null;
      };

      currentXhr.onerror = () => { cancel.disabled = true; btn.disabled = false; pstatus.textContent = 'Error'; result.innerHTML = '<div class="msg err">Network error. Is it over 100MB? Try Deep Upload.</div>'; currentXhr = null; };
      currentXhr.onabort = () => { cancel.disabled = true; btn.disabled = false; pstatus.textContent = 'Cancelled'; result.innerHTML = '<div class="msg err">Cancelled.</div>'; currentXhr = null; };

      currentXhr.send(fd);
    }

    // ──────────────────────────────────────────────────────────
    // METHOD B: DEEP (CHUNK-BY-CHUNK, NO FULL FILE IN RAM)
    // ──────────────────────────────────────────────────────────
    else {
      let cryptoKey, keyB64;
      try {
        const rawKey = crypto.getRandomValues(new Uint8Array(32));
        keyB64 = b64uEnc(rawKey);
        cryptoKey = await crypto.subtle.importKey('raw', rawKey, {name:'AES-GCM'}, false, ['encrypt']);
      } catch(e) {
        result.innerHTML = '<div class="msg err">Key generation failed: ' + esc(e) + '</div>';
        btn.disabled = false; cancel.disabled = true; return;
      }

      const CHUNK_SIZE = 1 * 1024 * 1024;
      const totalChunks = Math.ceil(f.size / CHUNK_SIZE);
      // Each encrypted chunk is CHUNK_SIZE + 12 (IV) + 16 (GCM tag) bytes
      const totalBytes = f.size + totalChunks * (12 + 16);
      let bytesSentAccumulated = 0;
      const startTime = Date.now();
      const uploadId = randId();

      const uploadChunk = (chunkIndex, blobChunk) => {
        return new Promise((resolve, reject) => {
          if (isCancelled) return reject(new Error('Cancelled'));

          const fd = new FormData();
          fd.append('action', 'chunk');
          fd.append('file_id', uploadId);
          fd.append('chunk_index', chunkIndex);
          fd.append('file_chunk', blobChunk);

          const xhr = new XMLHttpRequest();
          currentXhr = xhr;
          xhr.open('POST', '?ajax=1', true);
          xhr.responseType = 'json';

          xhr.upload.onprogress = ev => {
            if (!ev.lengthComputable) return;
            const totalSentSoFar = bytesSentAccumulated + ev.loaded;
            const p = (totalSentSoFar / totalBytes) * 100;

            fill.style.width = p + '%'; ppct.textContent = p.toFixed(1) + '%';
            psent.textContent = fmt(totalSentSoFar); ptotal.textContent = fmt(totalBytes);

            const now = Date.now(), dt = (now - startTime) / 1000;
            if (dt > 0.5) {
              const spd = totalSentSoFar / dt;
              pspeed.textContent = fmt(spd) + '/s';
              peta.textContent   = fmtT((totalBytes - totalSentSoFar) / (spd || 1));
            }
          };

          xhr.onload = () => {
            if (xhr.status === 200 && xhr.response?.ok) {
              bytesSentAccumulated += blobChunk.size;
              resolve();
            } else {
              reject(new Error(xhr.response?.error || 'Server rejected chunk'));
            }
          };

          xhr.onerror = () => reject(new Error('Network error on chunk ' + chunkIndex));
          xhr.onabort = () => reject(new Error('Cancelled'));
          xhr.send(fd);
        });
      };

      try {
        for (let i = 0; i < totalChunks; i++) {
          if (isCancelled) throw new Error('Cancelled');
          pstatus.textContent = `Deep Upload: Part ${i + 1} of ${totalChunks}…`;

          // Read only this chunk from disk — never loads the whole file into RAM
          const start = i * CHUNK_SIZE;
          const end = Math.min(start + CHUNK_SIZE, f.size);
          const rawChunk = new Uint8Array(await f.slice(start, end).arrayBuffer());

          // Encrypt this chunk independently
          const iv = crypto.getRandomValues(new Uint8Array(12));
          const ct = new Uint8Array(await crypto.subtle.encrypt({name:'AES-GCM', iv}, cryptoKey, rawChunk));
          const encChunk = new Uint8Array(12 + ct.length);
          encChunk.set(iv, 0);
          encChunk.set(ct, 12);
          const blobChunk = new Blob([encChunk], {type: 'application/octet-stream'});

          let success = false;
          let attempts = 0;

          while (!success && attempts < 3) {
            try {
              await uploadChunk(i, blobChunk);
              success = true;
            } catch (err) {
              attempts++;
              if (attempts >= 3) throw err;
              pstatus.textContent = `Connection blip. Retrying part ${i + 1} (Attempt ${attempts}/3)…`;
              await sleep(2000);
            }
          }

          await sleep(500);
        }

        pstatus.textContent = 'Finalizing file stream on server…';
        const finalFd = new FormData();
        finalFd.append('action', 'finalize');
        finalFd.append('file_id', uploadId);
        finalFd.append('total_chunks', totalChunks);
        finalFd.append('chunk_size', CHUNK_SIZE);

        const finalizeXhr = new XMLHttpRequest();
        currentXhr = finalizeXhr;
        finalizeXhr.open('POST', '?ajax=1', true);
        finalizeXhr.responseType = 'json';

        finalizeXhr.onload = () => {
          cancel.disabled = true; btn.disabled = false;
          const res = finalizeXhr.response;
          if (!res?.ok) {
            result.innerHTML = '<div class="msg err">' + esc(res?.error || 'Finalize failed.') + '</div>';
            pstatus.textContent = 'Failed'; return;
          }
          showSuccess(res, f.name, keyB64);
          currentXhr = null;
        };

        finalizeXhr.onerror = () => {
          cancel.disabled = true; btn.disabled = false; pstatus.textContent = 'Error';
          result.innerHTML = '<div class="msg err">Network error during finalize.</div>';
        };

        finalizeXhr.send(finalFd);

      } catch (err) {
        cancel.disabled = true; btn.disabled = false;
        pstatus.textContent = isCancelled ? 'Cancelled' : 'Failed';
        result.innerHTML = '<div class="msg err">' + esc(err.message) + '</div>';
        currentXhr = null;
      }
    }
  });
})();
</script>

<?php endif; ?>
</div>
</body>
</html>
