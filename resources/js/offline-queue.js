// Offline operation queue for the technician interface (REQ-0495): operations are stored in IndexedDB with a
// client-generated UUID and replayed to /api/tech/sync when the connection returns; the server applies each id once.
const DB_NAME = 'tech-offline', STORE = 'ops';
function db() {
  return new Promise((resolve, reject) => {
    const r = indexedDB.open(DB_NAME, 1);
    r.onupgradeneeded = () => r.result.createObjectStore(STORE, { keyPath: 'id' });
    r.onsuccess = () => resolve(r.result); r.onerror = () => reject(r.error);
  });
}
export async function enqueue(op) {
  const d = await db(); const item = { id: crypto.randomUUID(), ...op };
  await new Promise((res, rej) => { const t = d.transaction(STORE, 'readwrite'); t.objectStore(STORE).put(item); t.oncomplete = res; t.onerror = () => rej(t.error); });
  if (navigator.onLine) flush();
  return item.id;
}
export async function flush() {
  const d = await db();
  const ops = await new Promise((res) => { const r = d.transaction(STORE).objectStore(STORE).getAll(); r.onsuccess = () => res(r.result); });
  if (!ops.length) return {};
  const token = document.querySelector('meta[name="csrf-token"]')?.content;
  const resp = await fetch('/api/tech/sync', { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': token, Accept: 'application/json' }, body: JSON.stringify({ ops }) });
  if (!resp.ok) return {};
  const results = await resp.json();
  const t = d.transaction(STORE, 'readwrite');
  Object.keys(results).forEach((id) => t.objectStore(STORE).delete(id));
  return results;
}
window.addEventListener('online', flush);
navigator.serviceWorker?.addEventListener('message', (e) => { if (e.data === 'flush-queue') flush(); });
if ('serviceWorker' in navigator) navigator.serviceWorker.register('/sw.js');
