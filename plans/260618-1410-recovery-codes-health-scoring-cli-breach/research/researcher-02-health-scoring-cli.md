# Research Report: 2FA Account Health Scoring & Go CLI Distribution

**Date:** 2026-06-18  
**Research Duration:** Single session  
**Status:** Complete

---

## Executive Summary

Two critical technical areas for 2FA-Vault CLI feature:

1. **Account Health Scoring**: Industry-standard vault health reports (Bitwarden, 1Password) detect weak TOTP secrets, inactive 2FA, and breached passwords. Scoring requires entropy assessment of Base32-encoded secrets against RFC 4226 minimum of 128 bits (recommended 160 bits = 32-char strings).

2. **Go CLI + REST API**: Cobra + Viper handle configuration elegantly. Cross-platform clipboard via `golang-design/clipboard` library. GitHub Releases + GoReleaser automate binary distribution. Typical uncompressed binary: 10-20MB (reducible to 5-10MB with -ldflags "-w -s").

**Recommendation**: Use Cobra + Viper for config, `golang-design/clipboard` for clipboard ops, and GoReleaser for GitHub Releases automation.

---

## Research Methodology

- **Sources Consulted**: 12+ authoritative sources
- **Date Range**: RFC 4226 (2005, stable standard); Bitwarden/1Password docs (2024-2026); Go libraries (2024-2025 versions)
- **Key Search Terms**: "account health scoring", "TOTP entropy RFC 4226", "Cobra Viper patterns", "Go clipboard cross-platform", "GitHub Releases automation"

---

## Key Findings

### Topic 1: 2FA Account Health Scoring

#### Health Report Models (Industry Standard)

**Bitwarden Vault Health Reports** (free for paid users):
- Exposed/weak passwords detected against HaveIBeenPwned
- Inactive 2FA flagging on accounts that support it
- Suspicious site detection
- Data breach reports

**1Password Watchtower** (paid plans):
- Breach detection (HaveIBeenPwned integration)
- Weak password identification
- Duplicate password flagging
- Inactive 2FA detection
- Expiring credit card warnings

**Implication**: 2FA-Vault should surface 2FA accounts missing required auth factors or using weak TOTP configuration.

#### TOTP Secret Strength & Entropy

**RFC 4226 Requirements** ([RFC 4226 - HOTP](https://hackerone.com/reports/435648)):
- Minimum: 128 bits of entropy
- **Recommended: 160 bits** (strongly recommended in RFC)
- Hash function output alignment: 256-bit secrets for SHA256

**Base32 Encoding Practical Reality**:
- 160 bits = 32 characters (5 bits per Base32 char)
- 128 bits = 26 characters
- Standard entry method: 32-char strings (160-bit keys)

**Weak TOTP Classification**:
- Secrets < 128 bits (< 26 Base32 chars)
- Non-randomly generated secrets
- Default/known shared secrets

#### Algorithm & Digit Strength

**Standard Configuration** (per RFC 4226 & Google Authenticator):
- Algorithm: SHA1 (most common), SHA256 (stronger)
- Digit count: 6 (standard), 8 (enterprise, more entropy-resistant)
- Time step: 30 seconds (universal default)

**Scoring Consideration**: 8-digit TOTP offers ~3x more resistance to guessing than 6-digit.

### Topic 2: Go CLI Tools for REST API

#### Configuration Management (Cobra + Viper)

**Standard Search Patterns** ([Cobra Docs](https://cobra.dev/docs/learning-resources/community-knowledge/), [Viper GitHub](https://github.com/spf13/viper)):

```
/etc/appname/          (system-wide, Linux/macOS)
~/.appname/            (user home, legacy)
~/.config/appname/     (XDG-compliant, modern)
./                     (project-local override)
Environment: APPNAME_KEY=value
Flags: --key value
```

**Integration Pattern**:
- Precedence: Flags > Env > Config > Defaults
- Use `PersistentPreRun()` on root command to load config
- Set env prefix with `viper.SetEnvPrefix("APPNAME")`
- Query Viper instance (not flag vars) for final values

#### Clipboard Management

**Recommended Library**: `golang-design/clipboard` ([GitHub](https://github.com/golang-design/clipboard))

**Features**:
- Cross-platform: Windows, macOS, Linux (X11 + Wayland), Android, iOS
- Native wire protocol (no libX11/libwayland dependencies)
- Watcher API for clipboard change detection
- Supports UTF-8 text + PNG images

**Alternative Options**:
- `antonmedv/clipboard`: Simpler API, smaller footprint
- `zyedidia/clipper`: Extensive fallback support (Plan9, Termux)

**Code Example**:
```go
import "golang.design/x/clipboard"

// Write to clipboard
clipboard.Write(clipboard.FmtText, []byte("token"))

// Read from clipboard
data, _ := clipboard.Read(clipboard.FmtText)
```

#### Binary Distribution & Optimization

**Build Size Baseline** (2024-2025 data):
- Uncompressed: 10-20 MB (typical Go CLI)
- With `-ldflags "-w -s"`: 5-10 MB (30-50% reduction)
- With UPX compression: ~2-5 MB (further halving for downloads)

**Optimization Tools**:
- `go-size-analyzer`: Dependency-level binary audit
- `goreleaser`: Automates multi-platform builds, GitHub Releases, checksums
- Linker optimization: ~20% size reduction via dead code elimination

**GitHub Release Workflow**:
1. Tag release: `git tag v1.0.0 && git push --tags`
2. GoReleaser config: `.goreleaser.yaml` (defines platforms, compression, artifacts)
3. GitHub Actions trigger: Auto-build & upload to Releases
4. Download script pattern: `curl -sL https://github.com/user/repo/releases/download/v1.0.0/app-linux-amd64 | tar xz`

**Typical Config Pattern** (.goreleaser.yaml):
```yaml
builds:
  - env: ["CGO_ENABLED=0"]
    goos: [linux, darwin, windows]
    goarch: [amd64, arm64]
    ldflags: ["-w", "-s"]

archives:
  - format: tar.gz
    name_template: "{{ .ProjectName }}-{{ .Version }}-{{ .Os }}-{{ .Arch }}"

release:
  github:
    owner: org
    name: repo
```

**Distribution Size Guidance**:
- Linux x86_64: ~8-12 MB (uncompressed)
- macOS arm64: ~10-14 MB (Apple Silicon native)
- Windows: ~10-15 MB
- Compressed downloads: 2-5 MB range acceptable for user distribution

---

## Implementation Recommendations

### For Health Scoring Feature

1. **Scoring Algorithm**:
   - Flag: TOTP secrets < 128 bits (warn); < 160 bits (caution)
   - Flag: TOTP algorithm != SHA1/SHA256
   - Flag: TOTP digit count < 6
   - Flag: No backup codes or recovery codes on account
   - Scale: 0-100 (100 = all TOTP strong + recovery codes present)

2. **Database Schema** (Laravel migration):
   ```sql
   ALTER TABLE two_factors ADD COLUMN health_score INT DEFAULT NULL;
   ALTER TABLE two_factors ADD COLUMN health_flags JSON DEFAULT NULL;
   -- health_flags = ["weak_entropy", "no_recovery_codes", "algorithm_sha1"]
   ```

3. **Service Layer** (PHP):
   ```php
   // Decode Base32 secret, measure entropy bits
   $bits = strlen(rtrim($base32Secret, '=')) * 5;
   $health['weak_entropy'] = $bits < 160;
   ```

### For CLI Tool (Go)

1. **Project Structure**:
   ```
   cmd/2fa-cli/
     main.go (entry point)
     cmd/
       root.go (Cobra root)
       login.go (API auth)
       get.go (fetch token)
       copy.go (clipboard ops)
   internal/
     config/config.go (Viper setup)
     api/api.go (REST client)
   ```

2. **Config Initialization**:
   ```go
   func initConfig() {
       viper.SetEnvPrefix("2FA")
       viper.BindEnv("api_url", "API_URL")
       viper.SetConfigName("config")
       viper.AddConfigPath("~/.config/2fa-cli")
       viper.AddConfigPath(".")
       viper.ReadInConfig()
   }
   ```

3. **Build & Release**:
   ```bash
   # Local test
   go build -ldflags="-w -s" -o 2fa-cli ./cmd/2fa-cli

   # GitHub Actions + GoReleaser
   goreleaser release --clean
   ```

---

## Common Pitfalls

| Pitfall | Solution |
|---------|----------|
| Storing API tokens in ~/.appname plaintext | Use system keyring (credential-management library) or encrypted config |
| Viper precedence confusion (flag vs env vs config) | Always read from Viper instance, not flag vars |
| Binary bloat from unused dependencies | Audit with `go-size-analyzer`, prune transitive deps |
| Clipboard lib incompatibility on CI/CD | Detect headless env, gracefully skip clipboard or use fallback |
| Config paths vary (XDG vs legacy home) | Follow XDG Base Directory Spec on Linux; test all paths |

---

## Resources & References

### Account Health Scoring

- [Bitwarden vs 1Password: Vault Health Reports Comparison](https://bitwarden.com/bitwarden-vs-1password/)
- [1Password Watchtower Features](https://www.passwordmanager.com/1password-vs-bitwarden/)
- [RFC 4226: HMAC-Based One-Time Password Algorithm](https://hackerone.com/reports/435648) (128-bit minimum, 160-bit recommended)
- [Google Authenticator Implementation Notes](https://waters.me/internet/google-authenticator-implementation-note-key-length-token-reuse/)
- [Base32 Entropy & Key Length Calculations](https://portal.smartertools.com/community/a94662/follow-rfc4226-recommendations-and-increase-shared-secret-key-to-160-bits.aspx)

### Go CLI Tools

- [Cobra: A Commander for Modern CLI Apps](https://github.com/spf13/cobra)
- [Viper: Go Configuration Management](https://github.com/spf13/viper)
- [golang-design/clipboard: Cross-Platform Clipboard](https://github.com/golang-design/clipboard)
- [GoReleaser: Automated Binary Distribution](https://goreleaser.com/)
- [Datadog: Reducing Go Binaries by 77%](https://www.datadoghq.com/blog/engineering/agent-go-binaries/)
- [Analyzing Go Binary Sizes](https://blog.howardjohn.info/posts/go-binary-size/)
- [Building Production-Ready Go CLI Tools (2026)](https://dev.to/young_gao/building-a-production-ready-cli-tool-with-go-from-zero-to-distribution-240)

---

## Unresolved Questions

1. **API Authentication in CLI**: Should CLI use OAuth 2.0 device flow, PAT (personal access token), or session-based auth? 2FA-Vault architecture decision needed.
2. **Config Encryption**: Should sensitive config (API keys) be encrypted at rest or stored in system keyring only? Security policy decision.
3. **Health Scoring Weighting**: How heavily should weak TOTP entropy be weighted vs. missing recovery codes in final score? Product decision.
4. **Cross-Platform Build CI/CD**: Should build matrix (Linux/macOS/Windows, amd64/arm64) run on GitHub Actions or external CI? Infra decision.

---

**Report End**
