# Phase 4 — CLI Tool (Go binary)

## Context Links

- Plan overview: [plan.md](plan.md)
- Brainstorm: [brainstorm-report.md](brainstorm-report.md) (Feature 3)
- Existing API consumed (no backend change): `routes/api/v1.php`
  - `GET /api/v1/twofaccounts` (list) — `TwoFAccountController@index`
  - `GET /api/v1/twofaccounts/{id}/otp` (OTP) — route line 68, `->where('id','[0-9]+')`
- Auth: Laravel Passport Personal Access Token (PAT) sent as `Authorization: Bearer <PAT>`.

## Overview

- **Priority:** P2
- **Status:** pending
- **Effort:** 3.5d
- **Description:** Standalone Go binary `2fv` authenticating via PAT to generate OTPs from the
  terminal. New sibling repo `D:/2FA-Vault/2FA-Vault-CLI/`. Thin HTTP client; NO backend changes.
  v1 supports non-E2EE vaults only (master password must not pass through CLI args/config).

## Key Insights

- Zero backend work: CLI consumes existing endpoints. Account lookup by name is client-side: fetch
  list, match `service`/`account` against the user query.
- `{id}/otp` requires numeric id, so `otp <name>` must resolve name → id via the list first.
- E2EE vaults return ciphertext secrets the server cannot turn into OTPs server-side; therefore v1
  is explicitly non-E2EE-only. Detect and error clearly if the account/secret looks E2EE-encrypted.
- PAT stored in a config file with strict file perms (0600). Never echo the token back.
- Keep each `cmd/*.go` focused and small; HTTP concerns isolated in `internal/client`.

## Requirements

**Functional**
- `2fv config set host <url>` / `2fv config set token <PAT>` — persist to config file.
- `2fv list [--search <query>]` — list accounts (service/account, id).
- `2fv otp <account-name> [--watch] [--copy]` — current OTP; `--watch` refreshes each period;
  `--copy` copies to clipboard.

**Non-functional**
- Single static binary, cross-platform (linux/macos/windows, amd64+arm64).
- Config: `~/.config/2fv/config.toml` (XDG) / `%USERPROFILE%\.2fv\config.toml` on Windows.
- Token file perms 0600; HTTPS enforced (warn/refuse plain http unless `--insecure`).
- Clear, table-driven-testable command logic.

## Architecture

```
main.go -> cmd/root.go (cobra) -> cmd/{config,list,otp}.go
                                      |
                                      v
                          internal/client/api-client.go (net/http, Bearer PAT)
config: viper -> config.toml  (host, token)
```

`otp` flow: load config → GET /twofaccounts → match name → GET /twofaccounts/{id}/otp →
print password (+ remaining seconds); `--watch` loops aligned to `period`; `--copy` to clipboard.

## Related Code Files (all new, in `D:/2FA-Vault/2FA-Vault-CLI/`)

**Create**
- `go.mod` (module path, Go 1.23+; deps: cobra, viper, `golang-design/clipboard` for cross-platform native clipboard).
- `main.go` — calls `cmd.Execute()`.
- `cmd/root.go` — root cobra command, global `--host`/`--token`/`--insecure` flags, config load.
- `cmd/config.go` — `config set host|token`.
- `cmd/list.go` — `list [--search]`.
- `cmd/otp.go` — `otp <name> [--watch] [--copy]`.
- `internal/client/api-client.go` — `New(host, token, insecure)`, `ListAccounts(search)`,
  `GetOTP(id)`; handles Bearer auth, JSON decode, error mapping (401/404/429).
- `internal/client/api-client_test.go` — table-driven tests against `httptest.Server`.
- `cmd/otp_test.go`, `cmd/list_test.go` — command logic tests (name matching, search filter).
- `README.md` — install, config, usage, non-E2EE limitation, security notes.
- `.github/workflows/release.yml` — GoReleaser action, cross-platform build matrix (linux/darwin/windows × amd64/arm64) + artifact upload on tag.

**Cross-repo**
- `2FA-Vault-Docs/` — add CLI installation + usage page (user-visible command); `npm run validate:docs`.

## Implementation Steps

1. `go mod init`; add cobra, viper, clipboard deps.
2. `internal/client/api-client.go`: HTTP client with Bearer header, host normalization, HTTPS guard,
   `ListAccounts`/`GetOTP`, structured errors.
3. `cmd/root.go`: cobra root + viper config binding (file + env + flags precedence).
4. `cmd/config.go`: write host/token to config.toml with 0600 perms.
5. `cmd/list.go`: fetch + optional client-side `--search` filter; tabular output.
6. `cmd/otp.go`: resolve name → id, fetch OTP; implement `--watch` (period-aligned loop) and `--copy`.
7. Detect E2EE/ciphertext secret and emit a clear "CLI v1 does not support E2EE vaults" error.
8. Table-driven tests via `httptest.Server`; `go test ./...`.
9. `.github/workflows/release.yml`: use **GoReleaser** (`goreleaser/goreleaser-action`) — matrix build (linux/darwin/windows × amd64/arm64) on tag push, `-ldflags="-s -w"` to reduce binary to ~5-10MB.
10. Write README; add CLI docs page in `2FA-Vault-Docs`.

## Todo List

- [ ] go.mod + deps
- [ ] internal/client api-client + tests
- [ ] cobra root + viper config
- [ ] config / list / otp commands
- [ ] --watch and --copy
- [ ] E2EE detection + clear error
- [ ] go test ./... green
- [ ] release.yml cross-platform build
- [ ] README + docs page

## Success Criteria

- `2fv config set host/token` persists to 0600 config file.
- `2fv list` and `2fv list --search github` return expected accounts.
- `2fv otp "GitHub"` prints a valid current OTP; `--watch` refreshes; `--copy` copies.
- E2EE vault → clear, actionable error (no crash, no secret leakage).
- `go test ./...` passes; CI builds all target platforms.

## Risk Assessment

- **Risk:** Token leakage via shell history (`config set token` arg). **Mitigation:** support stdin/
  prompt entry; document history risk; never print token.
- **Risk:** Name collisions across accounts. **Mitigation:** if >1 match, list candidates with ids,
  require disambiguation (use id or refine search).
- **Risk:** HOTP accounts (no period) under `--watch`. **Mitigation:** disable `--watch` for HOTP,
  print once with a note.
- **Risk:** HIBP/rate limits — N/A here; but handle 429 with Retry-After messaging.

## Security Considerations

- PAT scope: document creating a least-privilege PAT.
- Config file 0600; HTTPS enforced unless explicit `--insecure`.
- No E2EE master password ever accepted via CLI (v1 limitation by design).
- OTP values may land in clipboard/terminal — warn in README.

## Next Steps

- v2 (out of scope): optional E2EE support via client-side decryption in the CLI.
- Consider Homebrew/Scoop packaging after CI artifacts exist.
