# 2FA-Vault - Architecture

> Zero-knowledge 2FA manager with E2EE, multi-user, browser extension, and PWA support

---

## 📐 Current Architecture (from 2FAuth)

### Tech Stack

| Layer | Technology |
|-------|-----------|
| **Backend** | Laravel 12 (PHP 8.4) |
| **Frontend** | Vue 3 + Pinia + Vue Router + Vite |
| **Database** | MySQL/SQLite/PostgreSQL |
| **Auth** | WebAuthn, OAuth (Socialite), Password, Reverse Proxy |
| **API** | Laravel Passport (OAuth2) |
| **Testing** | PHPUnit, Larastan (PHPStan) |
| **Deployment** | Docker, Heroku, self-hosted |

### Data Flow (Current)

```
User Input → Vue Components → API (Laravel) → Database
                                      ↓
                              (Secrets stored in plaintext or optional encryption)
```

### Security (Current)

- ✅ WebAuthn/Passkeys support
- ✅ CSRF protection
- ✅ Rate limiting
- ✅ Auto-logout
- ✅ Auth logging
- ❌ **Not zero-knowledge** (server can see secrets unless encryption enabled)
- ❌ Encryption optional (off by default)

---

## 🔐 Target Architecture (2FA-Vault)

### Core Principles

1. **Zero-Knowledge:** Server never sees unencrypted secrets
2. **Client-Side Encryption:** All secrets encrypted before leaving browser
3. **Multi-User:** Support teams and shared vaults
4. **Cross-Platform:** Web, Browser Extension, PWA

### Updated Tech Stack

| Layer | Technology | Notes |
|-------|-----------|-------|
| **Backend** | Laravel 12 (PHP 8.4) | API only, no secrets |
| **Frontend** | Vue 3 + Pinia + Vue Router + Vite | E2EE layer |
| **Encryption** | Web Crypto API, libsodium-wrappers | Client-side |
| **Database** | MySQL/PostgreSQL | Encrypted data only |
| **Auth** | WebAuthn, OAuth, Password | Multi-user |
| **API** | Laravel Passport (OAuth2) | + E2EE endpoints |
| **Testing** | PHPUnit, Playwright, Vitest | Backend + Frontend |
| **Extension** | Manifest V3, WebExtensions | Chrome/Edge/Firefox |
| **PWA** | Service Worker, Web App Manifest | Offline support |

---

## 🔒 E2EE Design (Phase 1)

### Encryption Flow

```
┌─────────────────────────────────────────────────────────────┐
│                    CLIENT (Browser/Extension)               │
├─────────────────────────────────────────────────────────────┤
│                                                             │
│  User inputs Master Password                                │
│         ↓                                                   │
│  Argon2id Derivation (100k iterations)                      │
│         ↓                                                   │
│  Encryption Key (256-bit)                                   │
│         ↓                                                   │
│  ┌─────────────────────────────────────┐                   │
│  │  Web Crypto API / libsodium         │                   │
│  │  - AES-256-GCM encryption           │                   │
│  │  - Per-account unique IV            │                   │
│  └─────────────────────────────────────┘                   │
│         ↓                                                   │
│  Encrypted Secret (Ciphertext)                              │
│         ↓                                                   │
└─────────────────┬───────────────────────────────────────────┘
                  │
                  │ HTTP POST /api/v1/accounts
                  │
┌─────────────────▼───────────────────────────────────────────┐
│                      SERVER (Laravel)                        │
├─────────────────────────────────────────────────────────────┤
│  Receives encrypted data only                               │
│  Stores in database (cannot decrypt)                        │
│  Returns encrypted data on request                          │
└─────────────────────────────────────────────────────────────┘
```

### Key Derivation

```javascript
// Client-side
const keyMaterial = await crypto.subtle.importKey(
  'raw',
  encoder.encode(masterPassword),
  { name: 'PBKDF2' },
  false,
  ['deriveKey']
)

const encryptionKey = await crypto.subtle.deriveKey(
  {
    name: 'PBKDF2',
    salt: userSalt,  // Per-user random salt (stored on server)
    iterations: 100000,
    hash: 'SHA-256'
  },
  keyMaterial,
  { name: 'AES-GCM', length: 256 },
  false,
  ['encrypt', 'decrypt']
)
```

### Data Structure

#### Encrypted Account
```sql
CREATE TABLE twofaccounts (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT UNSIGNED NOT NULL,
  service TEXT NOT NULL,              -- encrypted
  account TEXT NOT NULL,              -- encrypted
  secret TEXT NOT NULL,               -- ENCRYPTED SECRET
  algorithm VARCHAR(20) NOT NULL,     -- encrypted (or public metadata)
  digits TINYINT NOT NULL,            -- public metadata
  period SMALLINT DEFAULT 30,         -- public metadata
  counter BIGINT UNSIGNED DEFAULT 0,  -- encrypted for HOTP
  icon_id BIGINT UNSIGNED,
  group_id BIGINT UNSIGNED,
  created_at TIMESTAMP,
  updated_at TIMESTAMP,
  INDEX idx_user_id (user_id)
);
```

#### User (Updated)
```sql
CREATE TABLE users (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  email VARCHAR(255) UNIQUE NOT NULL,
  password_hash VARCHAR(255),          -- Laravel default hash
  encryption_salt VARCHAR(255),        -- Per-user salt for key derivation
  encryption_version INT DEFAULT 1,    -- For future compatibility
  created_at TIMESTAMP,
  updated_at TIMESTAMP
);
```

---

## 👥 Multi-User Architecture (Phase 2)

### Database Schema

```sql
-- Teams/Organizations
CREATE TABLE teams (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(255) NOT NULL,
  owner_id BIGINT UNSIGNED NOT NULL,
  slug VARCHAR(255) UNIQUE,
  created_at TIMESTAMP,
  updated_at TIMESTAMP,
  FOREIGN KEY (owner_id) REFERENCES users(id)
);

-- Team Members
CREATE TABLE team_users (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  team_id BIGINT UNSIGNED NOT NULL,
  user_id BIGINT UNSIGNED NOT NULL,
  role ENUM('owner', 'admin', 'member', 'viewer') DEFAULT 'member',
  joined_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (team_id) REFERENCES teams(id),
  FOREIGN KEY (user_id) REFERENCES users(id),
  UNIQUE KEY unique_team_user (team_id, user_id)
);

-- Shared Vaults
CREATE TABLE shared_vaults (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  team_id BIGINT UNSIGNED NOT NULL,
  name VARCHAR(255) NOT NULL,
  created_by BIGINT UNSIGNED NOT NULL,
  created_at TIMESTAMP,
  updated_at TIMESTAMP,
  FOREIGN KEY (team_id) REFERENCES teams(id),
  FOREIGN KEY (created_by) REFERENCES users(id)
);

-- Vault Access with Per-User Encryption Keys
CREATE TABLE vault_access (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  vault_id BIGINT UNSIGNED NOT NULL,
  user_id BIGINT UNSIGNED NOT NULL,
  encrypted_vault_key TEXT NOT NULL,  -- Encrypted with user's master key
  role ENUM('admin', 'contributor', 'viewer') DEFAULT 'viewer',
  added_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (vault_id) REFERENCES shared_vaults(id),
  FOREIGN KEY (user_id) REFERENCES users(id),
  UNIQUE KEY unique_vault_user (vault_id, user_id)
);

-- Shared Accounts
CREATE TABLE shared_accounts (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  vault_id BIGINT UNSIGNED NOT NULL,
  twofaccount_id BIGINT UNSIGNED,  -- Link to original account
  encrypted_secret TEXT NOT NULL,  -- Encrypted with vault key
  encrypted_service TEXT NOT NULL,
  encrypted_account TEXT NOT NULL,
  created_by BIGINT UNSIGNED NOT NULL,
  created_at TIMESTAMP,
  FOREIGN KEY (vault_id) REFERENCES shared_vaults(id),
  FOREIGN KEY (created_by) REFERENCES users(id)
);
```

### Permission Matrix

| Role | View | Create | Edit | Delete | Share | Admin |
|------|------|--------|------|--------|-------|-------|
| Owner | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ |
| Admin | ✅ | ✅ | ✅ | ✅ | ✅ | ❌ |
| Member | ✅ | ✅ | ✅ | ❌ | ❌ | ❌ |
| Viewer | ✅ | ❌ | ❌ | ❌ | ❌ | ❌ |

---

## 🧩 Browser Extension Architecture (Phase 4)

### Components

```
┌─────────────────────────────────────────────────────────────┐
│                    Browser Extension                         │
├─────────────────────────────────────────────────────────────┤
│                                                             │
│  ┌──────────────┐  ┌──────────────┐  ┌──────────────┐     │
│  │   Popup UI   │  │  Content     │  │  Background  │     │
│  │  (Quick      │  │  Script      │  │  Service     │     │
│  │   View)      │  │  (Autofill)  │  │  Worker)     │     │
│  └──────┬───────┘  └──────┬───────┘  └──────┬───────┘     │
│         │                  │                  │             │
│         └──────────────────┴──────────────────┘             │
│                            │                                │
│         ┌──────────────────▼──────────────────┐             │
│         │  Shared E2EE Module (same as web)   │             │
│         └──────────────────┬──────────────────┘             │
│                            │                                │
│         ┌──────────────────▼──────────────────┐             │
│         │  API Client (HTTPS, OAuth2 token)   │             │
│         └──────────────────┬──────────────────┘             │
│                            │                                │
└────────────────────────────┼────────────────────────────────┘
                             │
                             │ HTTPS
                             │
┌────────────────────────────▼────────────────────────────────┐
│                   Self-Hosted Server                         │
│              (Same API as web app)                           │
└─────────────────────────────────────────────────────────────┘
```

### Communication Flow

```
1. User clicks extension icon
2. Popup loads → requests OTP codes (encrypted)
3. Background worker calls API with OAuth token
4. Server returns encrypted data
5. Client decrypts with master password (cached)
6. Popup displays OTP codes
7. User clicks "Copy" → autofills into active tab (content script)
```

### Storage Strategy

- **Master Password:** Session-only (memory, not persistent)
- **Encryption Key:** Derived on each session start
- **OAuth Token:** Encrypted storage (chrome.storage.local)
- **Cache:** Encrypted in IndexedDB (optional)

---

## 📱 PWA Architecture (Phase 5)

### Service Worker Strategy

```javascript
// Service Worker
self.addEventListener('install', (event) => {
  event.waitUntil(
    caches.open('2fauth-v1').then((cache) => {
      return cache.addAll([
        '/',
        '/app.js',
        '/styles.css',
        '/icons/...'
      ])
    })
  )
})

self.addEventListener('fetch', (event) => {
  // Network-first for API, Cache-first for assets
  if (event.request.url.includes('/api/')) {
    event.respondWith(fetch(event.request))
  } else {
    event.respondWith(
      caches.match(event.request).then((response) => {
        return response || fetch(event.request)
      })
    )
  }
})
```

### Offline OTP Generation

```
Service Worker caches:
  └─ Encrypted accounts
  └─ User salt
  └─ App bundle

User offline:
  1. Enter master password (session)
  2. Derive encryption key
  3. Decrypt cached accounts
  4. Generate OTP codes (TOTP algorithm client-side)
  5. Display to user

Sync when online:
  1. Push local changes (if any)
  2. Fetch latest encrypted data
  3. Update cache
```

### Web Push Notifications

```javascript
// Use cases:
- Backup reminders
- Security alerts (new device login)
- Team invitations
- Vault sharing requests
```

---

## 🔄 Data Flow Summary

### Account Creation (E2EE)

```
User:
  1. Enter account details (service, account, secret)
  2. Master password already unlocked (session)
  3. Derive encryption key from master password + salt
  4. Encrypt secret with AES-256-GCM
  5. Send encrypted data to API

Server:
  1. Validate request (auth, rate limit)
  2. Store encrypted data in database
  3. Return success (no decryption possible)
```

### Account Retrieval (E2EE)

```
User:
  1. Request accounts list
  2. Send OAuth token (or session cookie)

Server:
  1. Validate auth
  2. Return encrypted accounts from database

User:
  1. Receive encrypted data
  2. Derive encryption key from master password
  3. Decrypt each account
  4. Generate OTP codes
  5. Display to user
```

### Shared Vault Access

```
User A (Creator):
  1. Create shared vault
  2. Generate random vault key
  3. Encrypt vault key with User A's master key
  4. Store in vault_access table

User B (Invited):
  1. Accept invitation
  2. User A re-encrypts vault key with User B's public key
  3. Store in vault_access table
  4. User B decrypts with their master key

All shared accounts:
  1. Encrypted with vault key (not user keys)
  2. Vault key encrypted per-user
  3. Each user can decrypt with their master key
```

---

## 🔐 Security Considerations

### Threat Model

| Threat | Mitigation |
|--------|------------|
| Server breach | Zero-knowledge: no unencrypted data |
| Database compromise | All secrets encrypted |
| XSS attacks | CSP headers, input sanitization |
| CSRF attacks | CSRF tokens, SameSite cookies |
| Password brute force | Argon2id (100k+ iterations), rate limiting |
| Key extraction | Memory-only keys, no persistence |
| Man-in-the-middle | HTTPS, certificate pinning |
| Backup theft | Encrypted backups, password-protected |

### Defense in Depth

1. **Encryption:** AES-256-GCM (military-grade)
2. **Key Derivation:** Argon2id (memory-hard, resistant to GPU/ASIC)
3. **Authentication:** WebAuthn (hardware keys), OAuth, 2FA
4. **Network:** HTTPS, TLS 1.3, HSTS
5. **Application:** Input validation, rate limiting, audit logging
6. **Infrastructure:** Secure hosting, regular backups, monitoring

---

## 📊 Performance Considerations

| Operation | Target | Optimization |
|-----------|--------|--------------|
| Account creation | < 1s | Lazy encryption, batch operations |
| Account list load | < 500ms | Pagination, lazy loading |
| OTP generation | < 100ms | Client-side, cached |
| Master password unlock | < 2s | Argon2id tuning (parallelism) |
| Offline load | < 1s | IndexedDB, service worker cache |

---

## 🚀 Deployment Architecture

### Production Setup

```
┌─────────────────────────────────────────────────────────────┐
│                        Load Balancer                        │
│                    (nginx, SSL termination)                  │
└─────────────────────────────────────────────────────────────┘
                            │
         ┌──────────────────┼──────────────────┐
         │                  │                  │
┌────────▼────────┐  ┌──────▼────────┐  ┌─────▼────────┐
│  App Server 1   │  │  App Server 2 │  │  App Server 3│
│  (PHP-FPM)      │  │  (PHP-FPM)    │  │  (PHP-FPM)   │
└────────┬────────┘  └──────┬────────┘  └─────┬────────┘
         │                  │                  │
         └──────────────────┼──────────────────┘
                            │
                   ┌────────▼────────┐
                   │  Database (RDS) │
                   │  (PostgreSQL)   │
                   └─────────────────┘

File Storage:
  - S3/R2 for backups
  - CDN for assets
```

### Scalability

- **Horizontal:** Stateless app servers, add more as needed
- **Database:** Read replicas, connection pooling
- **Caching:** Redis for sessions, rate limiting
- **CDN:** Cloudflare for static assets, DDoS protection

---

*Last Updated: 2026-04-04*
