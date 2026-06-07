// Service Worker for 2FA-Vault PWA
const CACHE_VERSION = 'v1';
const CACHE_NAME = `2fa-vault-${CACHE_VERSION}`;
const APP_SHELL_CACHE = `app-shell-${CACHE_VERSION}`;

// In-memory key storage for offline OTP generation
let vaultKey = null;
let encryptedAccounts = [];

// Files to cache for offline functionality
const APP_SHELL_FILES = [
  '/',
  '/css/app.css',
  '/js/app.js',
  '/manifest.json',
  '/icons/pwa-48x48.png',
  '/icons/pwa-72x72.png',
  '/icons/pwa-96x96.png',
  '/icons/pwa-128x128.png',
  '/icons/pwa-144x144.png',
  '/icons/pwa-152x152.png',
  '/icons/pwa-192x192.png',
  '/icons/pwa-384x384.png',
  '/icons/pwa-512x512.png'
];

// Install event - pre-cache app shell
self.addEventListener('install', (event) => {
  console.log('[Service Worker] Installing...');

  event.waitUntil(
    caches.open(APP_SHELL_CACHE)
      .then((cache) => {
        console.log('[Service Worker] Caching app shell');
        return cache.addAll(APP_SHELL_FILES);
      })
      .then(() => {
        console.log('[Service Worker] App shell cached successfully');
        return self.skipWaiting();
      })
      .catch((error) => {
        console.error('[Service Worker] Cache failed:', error);
      })
  );
});

// Activate event - clean old caches
self.addEventListener('activate', (event) => {
  console.log('[Service Worker] Activating...');

  event.waitUntil(
    caches.keys()
      .then((cacheNames) => {
        return Promise.all(
          cacheNames.map((cacheName) => {
            if (cacheName !== APP_SHELL_CACHE && cacheName !== CACHE_NAME) {
              console.log('[Service Worker] Deleting old cache:', cacheName);
              return caches.delete(cacheName);
            }
          })
        );
      })
      .then(() => {
        console.log('[Service Worker] Activated successfully');
        return self.clients.claim();
      })
  );
});

// Fetch event - route-based caching strategy
self.addEventListener('fetch', (event) => {
  const { request } = event;
  const url = new URL(request.url);

  // Skip cross-origin requests
  if (url.origin !== location.origin) {
    return;
  }

  // API requests - network-first strategy
  if (url.pathname.startsWith('/api/')) {
    event.respondWith(
      fetch(request)
        .then((response) => {
          // Clone the response before caching
          const responseToCache = response.clone();
          caches.open(CACHE_NAME).then((cache) => {
            cache.put(request, responseToCache);
          });
          return response;
        })
        .catch(() => {
          // Fallback to cache if network fails
          return caches.match(request);
        })
    );
    return;
  }

  // App shell - cache-first strategy
  event.respondWith(
    caches.match(request)
      .then((cachedResponse) => {
        if (cachedResponse) {
          // Return cached version and update cache in background
          fetch(request).then((response) => {
            caches.open(APP_SHELL_CACHE).then((cache) => {
              cache.put(request, response);
            });
          }).catch(() => {
            // Ignore network errors when updating cache
          });
          return cachedResponse;
        }

        // Not in cache - fetch from network
        return fetch(request)
          .then((response) => {
            // Cache static assets
            if (request.method === 'GET' &&
                (request.url.endsWith('.css') ||
                 request.url.endsWith('.js') ||
                 request.url.endsWith('.woff') ||
                 request.url.endsWith('.woff2'))) {
              const responseToCache = response.clone();
              caches.open(APP_SHELL_CACHE).then((cache) => {
                cache.put(request, responseToCache);
              });
            }
            return response;
          });
      })
  );
});

// Handle messages from clients (for vault key management)
self.addEventListener('message', (event) => {
  if (event.data && event.data.type === 'SKIP_WAITING') {
    self.skipWaiting();
  }

  // Save vault key for offline OTP generation
  if (event.data && event.data.type === 'SAVE_VAULT_KEY') {
    vaultKey = event.data.key;
    console.log('[Service Worker] Vault key saved for offline use');
    resetAutoLockTimeout();
  }

  // Clear vault key (lock vault)
  if (event.data && event.data.type === 'CLEAR_VAULT_KEY') {
    vaultKey = null;
    encryptedAccounts = [];
    console.log('[Service Worker] Vault key cleared');
    if (autoLockTimeout) {
      clearTimeout(autoLockTimeout);
      autoLockTimeout = null;
    }
  }

  // Save encrypted accounts for offline access
  if (event.data && event.data.type === 'SAVE_ENCRYPTED_ACCOUNTS') {
    encryptedAccounts = event.data.accounts;
    console.log('[Service Worker] Saved', encryptedAccounts.length, 'encrypted accounts for offline');
  }

  // Generate TOTP for offline account
  if (event.data && event.data.type === 'GENERATE_TOTP') {
    event.waitUntil(handleGenerateTotp(event));
  }

  // Heartbeat to reset auto-lock timeout
  if (event.data && event.data.type === 'HEARTBEAT') {
    resetAutoLockTimeout();
  }
});

// Auto-lock timeout (5 minutes of inactivity)
let autoLockTimeout = null;

function resetAutoLockTimeout() {
  if (autoLockTimeout) {
    clearTimeout(autoLockTimeout);
  }

  autoLockTimeout = setTimeout(() => {
    vaultKey = null;
    encryptedAccounts = [];
    console.log('[Service Worker] Vault auto-locked after 5 minutes of inactivity');
    notifyClients('VAULT_LOCKED');
  }, 5 * 60 * 1000); // 5 minutes
}

function notifyClients(type) {
  self.clients.matchAll().then(clients => {
    clients.forEach(client => {
      client.postMessage({ type });
    });
  });
}

async function handleGenerateTotp(event) {
  const { accountId } = event.data;
  const account = encryptedAccounts.find(acc => acc.id === accountId);
  const port = event.ports[0];

  if (!port) {
    return;
  }

  if (!account || !account.secret || !vaultKey) {
    port.postMessage({
      type: 'TOTP_RESULT',
      accountId,
      totp: null,
      error: 'Account not found or vault locked'
    });
    return;
  }

  try {
    const secret = await getAccountSecret(account);
    const totp = await generateTOTP(secret, account);

    port.postMessage({
      type: 'TOTP_RESULT',
      accountId,
      totp,
      error: null
    });
  } catch (error) {
    port.postMessage({
      type: 'TOTP_RESULT',
      accountId,
      totp: null,
      error: error.message
    });
  }
}

async function getAccountSecret(account) {
  if (typeof account.secret !== 'string' || !account.secret.startsWith('{')) {
    return account.secret;
  }

  const encryptedData = JSON.parse(account.secret);
  return decryptSecret(encryptedData, vaultKey);
}

async function decryptSecret(encryptedData, key) {
  const ciphertext = base64ToBytes(encryptedData.ciphertext);
  const iv = base64ToBytes(encryptedData.iv);
  const authTag = base64ToBytes(encryptedData.authTag);
  const combined = new Uint8Array(ciphertext.length + authTag.length);
  combined.set(ciphertext);
  combined.set(authTag, ciphertext.length);

  const plaintext = await crypto.subtle.decrypt(
    { name: 'AES-GCM', iv, tagLength: 128 },
    key,
    combined
  );

  return new TextDecoder().decode(plaintext);
}

async function generateTOTP(secret, account) {
  const key = base32Decode(secret);
  const counter = Math.floor(Math.floor(Date.now() / 1000) / (account.period || 30));
  const counterBytes = counterToBytes(counter);
  const algorithm = normalizeHashAlgorithm(account.algorithm || 'SHA1');
  const cryptoKey = await crypto.subtle.importKey(
    'raw',
    key,
    { name: 'HMAC', hash: algorithm },
    false,
    ['sign']
  );
  const signature = await crypto.subtle.sign({ name: 'HMAC', hash: algorithm }, cryptoKey, counterBytes);
  const hmac = new Uint8Array(signature);
  const offset = hmac[hmac.length - 1] & 0x0f;
  const code = (
    ((hmac[offset] & 0x7f) << 24) |
    ((hmac[offset + 1] & 0xff) << 16) |
    ((hmac[offset + 2] & 0xff) << 8) |
    (hmac[offset + 3] & 0xff)
  );
  const digits = account.digits || 6;

  return (code % (10 ** digits)).toString().padStart(digits, '0');
}

function normalizeHashAlgorithm(algorithm) {
  const normalized = algorithm.toUpperCase().replace('SHA', 'SHA-');

  if (!['SHA-1', 'SHA-256', 'SHA-512'].includes(normalized)) {
    throw new Error(`Unsupported offline TOTP algorithm: ${algorithm}`);
  }

  return normalized;
}

function counterToBytes(counter) {
  const bytes = new Uint8Array(8);
  let value = BigInt(counter);

  for (let i = 7; i >= 0; i--) {
    bytes[i] = Number(value & 0xffn);
    value >>= 8n;
  }

  return bytes;
}

function base32Decode(str) {
  const alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
  const normalized = str.toUpperCase().replace(/=+$/g, '').replace(/\s+/g, '');
  let bits = 0;
  let value = 0;
  const output = [];

  for (let i = 0; i < normalized.length; i++) {
    const val = alphabet.indexOf(normalized[i]);

    if (val === -1) continue;

    value = (value << 5) | val;
    bits += 5;

    if (bits >= 8) {
      output.push((value >>> (bits - 8)) & 0xff);
      bits -= 8;
    }
  }

  return new Uint8Array(output);
}

function base64ToBytes(base64) {
  const binString = atob(base64);
  return Uint8Array.from(binString, char => char.charCodeAt(0));
}
