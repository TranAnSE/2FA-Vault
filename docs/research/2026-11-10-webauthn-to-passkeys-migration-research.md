# Research: Migration laragear/webauthn → laravel/passkeys (2FA-Vault)

**Ngày nghiên cứu**: 2026-11-10
**Phạm vi**: Đánh giá toàn diện việc thay `laragear/webauthn` (abandoned) bằng `laravel/passkeys` (first-party) trong ngữ cảnh 2FA-Vault — fork của Bubka/2FAuth, Laravel 13, PHP 8.4, SPA Vue + session guard.

## TL;DR

`laragear/webauthn` đã **archived 2026-05-08** và tác giả tuyên bố rõ: **không còn security patch nữa**. Với app chuyên về bảo mật (vault 2FA), đây là rủi ro phải xử lý. Phần thay thế first-party `laravel/passkeys` là lựa chọn duy nhất được tác giả cũ chỉ định, có đủ escape hatch (ignoreRoutes, custom Actions/Responses) để giữ các flow riêng của app — nhưng đang **v0.2.1 (pre-1.0)**, schema đơn giản hóa hoàn toàn (JSON blob), và upstream 2FAuth **chưa migrate** nên fork sẽ diverge ở tầng auth. Hành trình migration khả thi nhưng có 1 rủi ro kỹ thuật lớn: **convert existing credentials sang định dạng PublicKeyCredentialSource của webauthn-lib** — sai là user bị lock out (có recovery flow mitigations).

## 1. Bối cảnh: vì sao laragear/webauthn bị bỏ

| Sự kiện | Chi tiết |
|---|---|
| Abandoned | 2026-05-08, release cuối v5.0.2, repo GitHub **ARCHIVED** |
| Lý do | "In light of the recent release of the official Laravel Passkeys implementation, this package is now unmaintained" — chính DarkGhostHunter chỉ định `laravel/passkeys` |
| Security | "Security patches won't be released in the future" — lời của tác giả |
| Packagist | Đánh dấu abandoned, gợi ý thay bằng `laravel/passkeys`; Aikido health score 8% |

Nguồn: [Releases · Laragear/WebAuthn](https://github.com/Laragear/WebAuthn/releases), [Packagist](https://packagist.org/packages/laragear/webauthn), [Aikido Intel](https://intel.aikido.dev/packages/packagist/laragear/webauthn)

## 2. laravel/passkeys — hiện trạng

### 2.1 Sức khỏe package

| Tiêu chí | Giá trị | Đánh giá |
|---|---|---|
| Version | v0.2.1 (2026-05-18) | **Pre-1.0** — kỳ vọng breaking changes |
| Repo | laravel/passkeys-server, tạo 2026-01-29 | Trẻ (~10 tháng) |
| Maintainers | benbjurstrom, joetannenbaum (senior Laravel maintainers), taylorotwell (2 commits) | First-party thực sự |
| Stars / issues | 114 / 7 open | Adoption sớm |
| Compat | PHP ^8.2, illuminate ^11\|^12\|^13, `web-auth/webauthn-lib` 5.3.x | ✅ Khớp Laravel 13/PHP 8.4 |
| Fortify | Là đường "blessed" (Features::passkeys()) — app này KHÔNG dùng Fortify | Dùng standalone + escape hatches |

Nguồn: [GitHub](https://github.com/laravel/passkeys-server), [Packagist](https://packagist.org/packages/laravel/passkeys), [Laravel News](https://laravel-news.com/laravel-introduces-first-party-passkey-authentication-support), [kawax.biz](https://kawax.biz/en/blog/passkeys-introduction)

### 2.2 API surface chính

- **Model**: trait `PasskeyAuthenticatable` + contract `PasskeyUser` trên User; override được `getPasskeyDisplayName()` / `getPasskeyUsername()`
- **Routes mặc định**: `GET/POST /passkeys/login*`, `/passkeys/confirm*`, `GET/POST/DELETE /user/passkeys*` — tắt được bằng `Passkeys::ignoreRoutes()`
- **Actions thay thế được** (bind trong service provider): `GenerateRegistrationOptions`, `GenerateVerificationOptions`, `StorePasskey`, `VerifyPasskey`, `DeletePasskey`
- **Responses thay thế được**: `PasskeyLoginResponse`, `PasskeyConfirmationResponse`, `PasskeyRegistrationResponse`, `PasskeyDeletedResponse`
- **Login authorization callback**: `Passkeys::authorizeLoginUsing()` — chặn login user bị ban sau khi assertion hợp lệ
- **Events**: `PasskeyRegistered`, `PasskeyVerified`, `PasskeyDeleted`
- **User-bound verification** (không login lại — dùng cho reauth/step-up): `GenerateVerificationOptions($user)` + `VerifyPasskey($credential, $options, $user)`
- **Challenge storage**: **session** key `passkey.verification_options` (laragear dùng cache key `_webauthn`) — tương thích SPA session của app
- **Config**: `relying_party_id`, `allowed_origins`, `user_handle_secret` (HMAC-derived user handle — không lưu per-credential như laragear), `timeout`, `guard`, `middleware`, `management_middleware` (mặc định `password.confirm`), `throttle`, `redirect`
- **JS client `@laravel/passkeys`**: `Passkeys.register()/verify()/autofill()/cancel()`, typed errors (NotSupported/UserCancelled/PasskeyExists/InvalidDomain), React/Vue/Svelte hooks SSR-safe, **route overrides per-call**

### 2.3 Hạn chế của laravel/passkeys so với laragear/webauthn

1. **Không có Auth UserProvider integration** — laragear có `WebAuthnUserProvider` cắm vào guard (`Auth::attempt` với signed challenge). passkeys verify xong trả về `Passkey`, app tự `Auth::login()`. → App phải viết lại tầng login controller thay vì override provider (thực tế code sẽ SẠCH HƠN — bỏ được custom provider hack hiện tại).
2. **Không có recovery/device-lost flow** — nhưng đây là flow app tự viết (CredentialBroker pattern), không phụ thuộc package nhiều.
3. **Không có rename credential** — passkeys có cột `name` nhưng route mặc định chỉ có POST/DELETE. Rename = tự viết route + `$passkey->update(['name' => ...])`. Nhẹ.
4. **`userVerification` hardcoded `REQUIRED`** trong `GenerateVerificationOptions` — app hiện có config `webauthn.user_verification` (Discouraged = fastLogin / Required = secureLogin). Giữ behavior phải override Action.
5. **Không có "counter/clone detection" cột riêng** — webauthn-lib tự check counter trong assertion verification (đủ).

## 3. So sánh schema & rủi ro chuyển dữ liệu

### 3.1 Schema

```text
webauthn_credentials (laragear — 15 cột normalized)   passkeys (laravel/passkeys)
──────────────────────────────────────────────        ────────────────────────────
id (string 510, PK — credential id base64url)   →     credential_id (string, unique)
authenticatable_type/id (morphs)                →     user_id (FK + cascade)
user_id (uuid — user handle per credential)     →     (không lưu; HMAC-derived)
alias (string nullable)                         →     name (string NOT nullable)
counter (bigint)                                →     trong credential JSON blob
rp_id, origin (string)                          →     (không lưu riêng)
transports (json)                               →     trong credential JSON blob
aaguid (uuid)                                   →     trong credential JSON blob
public_key (text — format riêng của laragear)   →     credential (json — PublicKeyCredentialSource chuẩn webauthn-lib)
attestation_format (default 'none'), certificates(json) → trong credential JSON blob
disabled_at (soft-disable)                      →     (không có — chỉ DELETE)
timestamps                                      →     timestamps + last_used_at
PK kép (id, user_id)                            →     id auto-increment
```

### 3.2 Rủi ro chuyển dữ liệu — điểm kỹ thuật lớn nhất

- `passkeys.credential` phải là **PublicKeyCredentialSource** (mảng JSON chuẩn của webauthn-lib 5.x): publicKeyPem/publicKey, counter, aaguid, transports, attestation... 
- laragear lưu `public_key` theo format riêng (base64 key material) + các cột rời. **Chưa có tool convert chính thức** — phải tự viết converter và **spike chứng minh được trước khi tiến hành**.
- Nếu convert sai → user hiện hữu không verify được → **lock out** (mitigations: password fallback cho user không bật `useWebauthnOnly`, recovery flow qua email, device-lost flow).
- Phương án thay thế nếu convert rủi ro quá: dual-stack ngắn hạn (đọc cả 2 bảng) hoặc **force re-registration toàn bộ** (UX tệ với vault app — không khuyến nghị trừ khi spike thất bại).
- **User handle**: authenticator cũ nhớ handle UUID cũ của laragear; passkeys derive handle bằng HMAC(secret). ~~Với login email-scoped thì userHandle mismatch không chặn verify.~~ **[SỬA 2026-11-10 sau red-team C2 — claim cũ SAI với webauthn-lib 5.3]**: `VerifyPasskey` luôn truyền `userHandle: $source->userHandle` vào assertion validation, và `CheckUserHandle` byte-so sánh (`hash_equals`) handle lưu với bytes authenticator trả về (platform authenticators luôn trả userHandle) → mismatch **có** chặn verify (`InvalidUserHandleException`). Hơn nữa laragear truyền dạng **undashed 32-hex** (`Uuid::getHex()`, `AddUserDescriptor.php:37,40`), không phải UUID dashed — ETL phải lưu đúng bytes này. Derived-handle HMAC của passkeys chỉ ảnh hưởng đăng ký mới (flow usernameless vẫn không dùng).

## 4. Phạm vi code ảnh hưởng trong 2FA-Vault (đã scan)

**Backend (17 file PHP core):**
- 5 controllers: `WebAuthnLogin/Register/Manage/Recovery/DeviceLostController` — Login viết lại nhiều nhất (bỏ AssertionRequest/AssertedRequest của laragear, gọi Actions trực tiếp)
- `WebauthnTwoFAuthUserProvider`, `WebauthnCredentialBroker` (Extensions) — provider **bỏ được**; broker giữ
- Model: `User` (trait `WebAuthnAuthentication` → `PasskeyAuthenticatable`), `WebAuthnAuthenticatable` contract riêng, trait `WebAuthnManageCredentials` (renameCredential/flushCredential/flushCredentials)
- 6 FormRequests (Asserted/Attestation/Attested/DeviceLost/Recovery/Rename)
- Routes `web.php` (giữ nguyên path `/webauthn/*` để không phá SPA + extension), `api/v1.php` (admin revoke)
- Auth flow riêng phải giữ: throttle theo user+ip, enumeration protection (A8 fix), session regeneration, `vault_locked` logic, `RejectIfSsoOnlyAndNotForAdmin`, `last_seen_at`, recovery bằng password broker
- `AuthServiceProvider` (đăng ký custom provider), `SystemController` (health check webauthn), `UserManagerController` (admin revoke), `ResetTrait`, `UserObserver`

**Frontend (JS):**
- `resources/js/services/webauthn/webauthnService.js` — tự implement base64url, gọi `/webauthn/*` endpoints. **Response format đổi** (webauthn-lib JSON) → phải sửa encode/decode + error mapping (23 locale files có error messages)
- 2 file error identification, abort service, biometric service, userService (list/revoke credentials)

**Tests:** 15+ file test Feature (5 controller test suites, request tests, permissions, rate-limit, notification, e2e fixtures) — mock/fixture dữ liệu WebAuthn phải build lại theo format webauthn-lib.

**Sibling repos:** Extension (2FA-Vault-WebExtension) — cần check có gọi `/webauthn/*` không; Docs (2FA-Vault-Docs) — nếu có trang hướng dẫn WebAuthn; API specs — nếu `/webauthn/*` được spec hóa.

## 5. Upstream 2FAuth — yếu tố chiến lược

- Upstream `Bubka/2FAuth` master **vẫn dùng `laragear/webauthn ^5.0`**, không có issue/PR/discussion nào về migrate passkeys (tìm 2026-11-10).
- Hệ quả: nếu migrate, **fork diverge vĩnh viễn ở tầng auth** so với upstream — mọi pull upstream đụng auth (controllers, recovery, tests, User model) sẽ conflict nhiều hơn hiện tại.
- Ngược lại: ở lại laragear = dùng thư viện WebAuthn không được patch security trong một app bảo mật — mâu thuẫn trực tiếp với tính chất sản phẩm.
- Gợi ý chiến lược: migrate theo hướng **giữ nguyên route contract + tách lớp adapter** để diff với upstream nằm gọn trong ít file nhất có thể; theo dõi upstream có migrate thì đối chiếu lại.

## 6. Đánh giá tổng hợp

| Phương án | Ưu | Nhược | Verdict |
|---|---|---|---|
| **A. Migrate sang laravel/passkeys** | First-party, được bảo trì, chuẩn webauthn-lib, bỏ được custom provider hack, long-term an toàn | v0.x breaking changes; convert dữ liệu rủi ro; diverge upstream; effort lớn (BE+FE+tests) | **Khuyến nghị** — có đủ escape hatch, spike trước |
| B. Fork laragear/webauthn tự duy trì | Không đổi app code; giữ sync upstream | Tự gánh security maintenance của lib WebAuthn — trái tinh thần YAGNI/không đủ manpower | Không khuyến nghị |
| C. Ở yên + theo dõi | Zero effort hôm nay | Lib unpatched vô thời hạn trong app bảo mật; cảnh báo audit mỗi tuần | Chỉ chấp nhận ngắn hạn |

## 7. Unresolved questions (mang sang brainstorm)

1. **Credential data migration**: convert in-place (rủi ro lock-out, cần spike) vs dual-read transition vs force re-register? Spike số-1 phải trả lời được format `public_key` của laragear convert được bao nhiêu % sang PublicKeyCredentialSource.
2. **JS**: thay `webauthnService` tự viết bằng `@laravel/passkeys` client (route overrides) hay giữ service tự viết chỉ đổi serializer? (Error messages 23 ngôn ngữ đang map theo lỗi tự định nghĩa.)
3. **Route contract**: giữ nguyên `/webauthn/*` paths (không phá SPA/extension) và `ignoreRoutes()` — hay chấp nhận đường mới `/passkeys/*`?
4. **Thời điểm**: làm ngay (v0.2.1, chấp nhận bump theo minor) vs chờ v1.0 (rủi ro bị unpatched lâu hơn)?
5. **`useWebauthnOnly` preference**: laragear có password-fallback flag ở provider — sang passkeys logic này đặt ở đâu (login controller tự xử lý)?
6. **Extension repo** có phụ thuộc endpoint/response format webauthn không — cần check trước khi chốt phạm vi.

## Sources

- [Laragear/WebAuthn releases — abandonment notice](https://github.com/Laragear/WebAuthn/releases)
- [laragear/webauthn trên Packagist](https://packagist.org/packages/laragear/webauthn)
- [laravel/passkeys-server GitHub](https://github.com/laravel/passkeys-server)
- [laravel/passkeys Packagist](https://packagist.org/packages/laravel/passkeys)
- [Laravel News — First-party Passkey support](https://laravel-news.com/laravel-introduces-first-party-passkey-authentication-support)
- [kawax.biz — passkeys introduction](https://kawax.biz/en/blog/passkeys-introduction)
- [nazarboyko.com — Passkeys with Fortify guide](https://www.nazarboyko.com/articles/laravel-first-party-passkeys-webauthn-guide)
- [qadrlabs.com — Passkeys in starter kits](https://qadrlabs.com/post/passkins-in-laravel-starter-kits-a-first-look)
- [Aikido Intel — package health](https://intel.aikido.dev/packages/packagist/laragear/webauthn)
