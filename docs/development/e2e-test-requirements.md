# E2E Test Requirements & Coverage Assessment

Comprehensive E2E (end-to-end) testing plan for 2FA-Vault enterprise features.

## Current Test Coverage Summary

**Total Tests:** 124 test files
- ✅ Unit tests: 15+ files
- ✅ Feature/Integration tests: 60+ files  
- ✅ API endpoint tests: 25+ files
- ✅ Authentication tests: 13+ files
- ⚠️ E2E workflow tests: LIMITED

## Critical E2E Test Gaps

### 1. End-to-End Encryption (E2EE)

**Importance:** CRITICAL — Core security feature

#### Missing Tests
- [ ] Complete E2EE workflow: setup → encrypt account → fetch → decrypt
- [ ] Encryption key persistence across browser sessions (memory-only validation)
- [ ] Vault unlock flow with Argon2id derivation
- [ ] Account encryption state transition (plaintext → encrypted)
- [ ] Mixed encrypted/unencrypted accounts coexistence
- [ ] Encryption salt management and validation
- [ ] Test value verification for password validation
- [ ] Multiple devices with same E2EE key
- [ ] Account re-encryption after password change

#### Existing Coverage
- ✅ EncryptionControllerTest.php (11 tests + 9 new)
- ✅ Setup validation
- ✅ Vault locking/unlocking
- ✅ Verification endpoints
- ⚠️ Client-side crypto NOT tested (requires browser automation)

**Priority:** P0 (Blocking) - Need full integration tests including crypto operations

---

### 2. Multi-User & Team Management

**Importance:** HIGH — Enterprise feature

#### Missing Tests
- [ ] Team creation workflow
- [ ] User invitation flow (create → send → accept → verify)
- [ ] Role-based access control (owner, admin, member, viewer)
- [ ] Team member removal
- [ ] Account sharing within teams
- [ ] Permission inheritance in shared accounts
- [ ] Cross-team account isolation
- [ ] Team deletion and cascading deletes
- [ ] Team member role changes and permission updates

#### Existing Coverage
- ✅ TeamControllerTest.php (basic tests)
- ✅ UserManagerControllerTest.php (basic tests)
- ⚠️ Permission tests exist but limited scope
- ⚠️ Missing real workflow tests

**Priority:** P1 (High) - Multiple features dependent on team isolation

---

### 3. Encrypted Backups

**Importance:** HIGH — Data recovery critical path

#### Missing Tests
- [ ] Backup export workflow (trigger → encryption → download)
- [ ] Backup import workflow (upload → decrypt → verify → restore)
- [ ] Double encryption validation (master key + backup password)
- [ ] Backup file format (.vault) validation
- [ ] Backup migration across instances
- [ ] Backup with mixed encrypted/unencrypted accounts
- [ ] Large backup handling (1000+ accounts)
- [ ] Backup password change procedure
- [ ] Corrupted backup handling and error messages
- [ ] Backup with team shared accounts

#### Existing Coverage
- ✅ BackupControllerTest.php (basic tests)
- ⚠️ Missing full encryption lifecycle tests

**Priority:** P1 (High) - Backups are critical for data recovery

---

### 4. Browser Extension

**Importance:** MEDIUM — User convenience feature

#### Missing Tests
- [ ] Extension popup → fetch accounts workflow
- [ ] TOTP code generation in extension
- [ ] Auto-fill detection (when not available in Manifest v3)
- [ ] Extension message passing to web app
- [ ] Extension storage isolation
- [ ] Cross-domain security validation
- [ ] Extension updates and migration
- [ ] Error handling in extension

#### Existing Coverage
- ❌ No integration tests (requires browser automation/Selenium)
- ❌ No popup functionality tests
- ❌ No content script tests

**Priority:** P2 (Medium) - Would benefit from Playwright E2E tests

---

### 5. Progressive Web App (PWA)

**Importance:** MEDIUM — Offline functionality

#### Missing Tests
- [ ] Service worker installation
- [ ] Offline mode activation
- [ ] IndexedDB sync with server
- [ ] Account access without network
- [ ] Encryption key persistence in offline mode
- [ ] OTP generation offline
- [ ] Background sync queue
- [ ] Notification permissions and handling
- [ ] Install prompt display
- [ ] Update notifications

#### Existing Coverage
- ✅ PushSubscriptionTest.php (push notification tests)
- ⚠️ Missing offline/sync tests
- ⚠️ Missing service worker tests

**Priority:** P2 (Medium) - Nice to have but good for user experience

---

### 6. Authentication Flows

**Importance:** CRITICAL — Security gate

#### Missing Tests
- [ ] OAuth2 token lifecycle (generation → expiry → refresh)
- [ ] WebAuthn registration + authentication
- [ ] Social login (if enabled)
- [ ] Session expiry and auto-logout
- [ ] Concurrent login handling
- [ ] Device management (register, revoke, verify)
- [ ] Security key recovery codes
- [ ] Multi-device session tracking

#### Existing Coverage
- ✅ LoginTest.php
- ✅ WebAuthnRegisterControllerTest.php (5 tests)
- ✅ WebAuthnLoginControllerTest.php (3 tests)
- ✅ WebAuthnManageControllerTest.php (3 tests)
- ✅ WebAuthnRecoveryControllerTest.php (2 tests)
- ⚠️ Limited OAuth2 token management tests

**Priority:** P0 (Blocking) - Auth is security-critical

---

### 7. Account Operations with E2EE

**Importance:** CRITICAL — Core feature + encryption

#### Missing Tests
- [ ] Create account (encrypted)
- [ ] Update account (re-encrypt)
- [ ] Delete account
- [ ] Bulk import with encryption
- [ ] Bulk delete with encryption
- [ ] Search encrypted accounts
- [ ] Reorder accounts while encrypted
- [ ] Icon upload + encryption interaction
- [ ] QR code scanning → encryption
- [ ] Account migration (plaintext → encrypted)

#### Existing Coverage
- ✅ TwoFAccountControllerTest.php (CRUD tests)
- ✅ TwoFAccountServiceTest.php
- ⚠️ Tests don't include encryption payload validation
- ⚠️ Missing encrypted state assertions

**Priority:** P0 (Blocking) - Core feature with encryption

---

## Test Strategy Recommendations

### Phase 1: Critical Path (P0)
**Estimated effort:** 2-3 weeks

1. **E2EE complete workflows** (5 tests)
   - Setup → create encrypted account → fetch → verify decryption
   - Account type transitions
   - Key rotation scenarios

2. **Auth + E2EE integration** (3 tests)
   - Login → unlock vault → use encrypted accounts
   - Session + encryption key lifecycle
   - Device management with E2EE

3. **Account CRUD with encryption** (5 tests)
   - Create/read/update/delete with encrypted payload
   - Bulk operations validation
   - Search in encrypted accounts

### Phase 2: High Priority (P1)
**Estimated effort:** 2-3 weeks

1. **Team workflows** (6 tests)
   - Team creation → invite → accept → share account
   - Role-based access control
   - Team deletion cascades

2. **Backup complete cycle** (5 tests)
   - Export encrypted backup
   - Import and verify
   - Cross-instance migration
   - Password change

### Phase 3: Nice-to-Have (P2)
**Estimated effort:** 2-3 weeks

1. **PWA offline workflows** (4 tests)
   - Offline mode activation
   - Sync queue management
   - Notification handling

2. **Browser extension workflows** (3 tests)
   - Requires Playwright + browser automation
   - Popup interactions
   - Content script validation

---

## Recommended Tools & Approach

### Current Setup
- **PHPUnit** for backend tests (good for unit/feature/API)
- **Laravel Testing** for database interactions
- **Factories** for test data

### Recommended Additions
- **Playwright** or **Cypress** for browser E2E tests
  - E2EE crypto operations (client-side)
  - UI workflows (encryption, teams, backups)
  - Browser extension interactions
- **API Integration Tests** (expand current)
  - Full workflows combining multiple endpoints
  - Encryption payload validation
  - Team collaboration scenarios

### Example Test Structure

```php
// Backend E2E test (API + encryption validation)
class AccountEncryptionE2ETest extends TestCase {
    public function test_complete_encrypted_account_workflow() {
        // 1. Setup encryption
        // 2. Create encrypted account
        // 3. Fetch and verify encryption
        // 4. Update account
        // 5. Delete account
        // Assert each step persists correctly
    }
}

// Browser E2E test (Playwright - pseudo-code)
test('User can encrypt and use accounts', async ({ page }) => {
    // 1. Login
    // 2. Setup E2EE (client-side crypto)
    // 3. Create account (client encrypts, server stores)
    // 4. Fetch accounts (client decrypts)
    // 5. Generate OTP
    // Verify encryption happened client-side
})
```

---

## Implementation Roadmap

### Immediate (This sprint)
- [ ] Enhance EncryptionControllerTest with 8+ new tests ✅ DONE
- [ ] Create AccountEncryptionE2ETest (backend integration)
- [ ] Create TeamWorkflowE2ETest (backend integration)
- [ ] Create BackupE2ETest (backend integration)

### Next sprint
- [ ] Add Playwright/Cypress setup
- [ ] Create browser E2E tests for UI workflows
- [ ] Add encryption payload validation tests
- [ ] Add team collaboration E2E tests

### Future
- [ ] Add browser extension tests
- [ ] Add PWA offline E2E tests
- [ ] Performance/load testing with encryption

---

## Success Criteria

| Feature | Current | Target | Status |
|---------|---------|--------|--------|
| E2EE | ✅ API tests | ✅ Full workflow | 🟡 In progress |
| Teams | ⚠️ Basic tests | ✅ Complete workflows | 🔴 Todo |
| Backups | ⚠️ Basic tests | ✅ Import/export/migrate | 🔴 Todo |
| Auth | ✅ Good coverage | ✅ Maintain | ✅ Done |
| PWA | 🔴 No tests | ⚠️ Basic tests | 🔴 Todo |
| Extension | 🔴 No tests | ⚠️ Basic tests | 🔴 Todo |

---

## Notes

1. **Security Testing:** Encryption tests should validate that server NEVER receives plaintext secrets
2. **State Validation:** All E2E tests should assert database state changes, not just HTTP responses
3. **Real Cryptography:** Tests should use actual Argon2id + AES-GCM, not mocks
4. **Multi-device Scenarios:** Test same user across different browser sessions/devices
5. **Error Paths:** Every happy path needs corresponding error case tests
