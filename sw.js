// service worker, only used by firefox/safari to stream big downloads straight to disk.
// the page decrypts chunk by chunk and hands the plain data over here, nothing is stored and it never sees the key.
const pending = new Map();

self.addEventListener('install', () => self.skipWaiting());
self.addEventListener('activate', (e) => e.waitUntil(self.clients.claim()));

self.addEventListener('message', (e) => {
  const { id, name, size, port } = e.data || {};
  if (!id || !port) return;
  const stream = new ReadableStream({
    start(ctrl) {
      port.onmessage = (m) => {
        if (m.data === null) ctrl.close();
        else if (m.data === 'abort') ctrl.error(new Error('aborted'));
        else ctrl.enqueue(m.data);
      };
    },
    pull() { port.postMessage('ok'); },     // lets the page know it can send the next chunk
    cancel() { port.postMessage('cancel'); },
  }, { highWaterMark: 1 });
  pending.set(id, { stream, name, size });
  setTimeout(() => pending.delete(id), 60000);
});

self.addEventListener('fetch', (e) => {
  const u = new URL(e.request.url);
  if (!u.pathname.startsWith('/_dl/')) return;
  const id = u.pathname.slice(5), entry = pending.get(id);
  if (!entry) return;
  pending.delete(id);
  e.respondWith(new Response(entry.stream, {
    headers: {
      'Content-Type': 'application/octet-stream',
      'Content-Length': String(entry.size),
      'Cache-Control': 'no-store',
      'Content-Disposition': "attachment; filename*=UTF-8''" + encodeURIComponent(entry.name).replace(/['()]/g, escape),
    },
  }));
});
