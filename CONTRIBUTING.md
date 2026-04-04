# Contributing to 2FA-Vault

Thank you for your interest in contributing to 2FA-Vault! This guide will help you get started.

---

## 🚀 Quick Start

1. **Fork the repository**
   ```bash
   # Fork on GitHub, then clone your fork
   git clone https://github.com/YOUR_USERNAME/2FA-Vault.git
   cd 2FA-Vault
   ```

2. **Install dependencies**
   ```bash
   # Backend
   composer install

   # Frontend
   npm install
   ```

3. **Setup environment**
   ```bash
   cp .env.example .env
   php artisan key:generate
   php artisan migrate
   ```

4. **Run development server**
   ```bash
   # Backend
   php artisan serve

   # Frontend (in another terminal)
   npm run dev
   ```

5. **Make your changes**
   - Create a branch for your feature
   - Make your changes
   - Test thoroughly
   - Submit a pull request

---

## 📋 Development Workflow

### Branch Naming

Use descriptive branch names:

```
feature/your-feature-name
fix/your-bug-fix
docs/update-documentation
refactor/refactor-something
test/add-tests
```

### Commit Messages

Follow conventional commits:

```
feat: add E2EE encryption module
fix: resolve memory leak in OTP generation
docs: update API documentation
refactor: simplify database queries
test: add unit tests for encryption
```

### Pull Request Process

1. Update your branch with latest changes
   ```bash
   git fetch upstream
   git rebase upstream/master
   ```

2. Push to your fork
   ```bash
   git push origin feature/your-feature
   ```

3. Create a Pull Request
   - Link to related issues
   - Describe your changes
   - Add screenshots if applicable
   - Ensure CI passes

---

## 🧪 Testing

### Run All Tests
```bash
# Backend
./vendor/bin/phpunit

# Frontend
npm run test
```

### Run Specific Tests
```bash
# Backend - specific test file
./vendor/bin/phpunit tests/Feature/EncryptionTest.php

# Frontend - specific test file
npm run test -- Encryption.spec.js
```

### Test Coverage
```bash
# Backend
./vendor/bin/phpunit --coverage-html coverage

# Frontend
npm run test:coverage
```

**Target:** 90%+ code coverage

---

## 📝 Code Style

### PHP (Backend)
```bash
# Run Pint (Laravel Prettier)
./vendor/bin/pint

# Run Larastan (PHPStan)
./vendor/bin/phpstan analyse
```

### JavaScript/TypeScript (Frontend)
```bash
# Run ESLint
npm run lint

# Fix linting issues
npm run lint:fix
```

---

## 🏗️ Architecture Guidelines

### E2EE Implementation

- **Never** decrypt secrets on the server
- Use Web Crypto API for client-side encryption
- Follow the architecture in [ARCHITECTURE.md](ARCHITECTURE.md)
- Test encryption/decryption thoroughly

### Multi-User Implementation

- Always check permissions before data access
- Use Laravel's authorization gates
- Log all access to shared vaults
- Encrypt per-user vault keys

### Database Changes

- Create migrations for all schema changes
- Run migrations locally: `php artisan migrate`
- Provide rollback migrations
- Document schema changes in ARCHITECTURE.md

---

## 🐛 Reporting Bugs

Before reporting a bug:

1. Check existing [issues](https://github.com/vibecoder11200/2FA-Vault/issues)
2. Reproduce the bug with latest code
3. Provide detailed information:

**Bug Report Template:**

```markdown
**Describe the bug**
A clear and concise description of what the bug is.

**To Reproduce**
Steps to reproduce the behavior:
1. Go to '...'
2. Click on '....'
3. Scroll down to '....'
4. See error

**Expected behavior**
A clear and concise description of what you expected to happen.

**Screenshots**
If applicable, add screenshots to help explain your problem.

**Environment (please complete the following information):**
 - OS: [e.g. Windows 11, macOS 14, Ubuntu 22.04]
 - Browser: [e.g. Chrome 120, Firefox 120]
 - PHP Version: [e.g. 8.4]
 - Database: [e.g. MySQL 8.0, PostgreSQL 15]

**Additional context**
Add any other context about the problem here.
```

---

## 💡 Feature Requests

We welcome feature requests! Before submitting:

1. Check [ROADMAP.md](ROADMAP.md) for planned features
2. Search existing issues and discussions
3. Provide a clear use case

**Feature Request Template:**

```markdown
**Is your feature request related to a problem? Please describe.**
A clear and concise description of what the problem is.

**Describe the solution you'd like**
A clear and concise description of what you want to happen.

**Describe alternatives you've considered**
A clear and concise description of any alternative solutions or features you've considered.

**Additional context**
Add any other context or screenshots about the feature request here.
```

---

## 🔒 Security

If you discover a security vulnerability, **please do not open a public issue**.

Instead, send an email to: **[SECURITY_EMAIL]**

Include:
- Description of the vulnerability
- Steps to reproduce
- Proposed fix (if any)

We will respond promptly and coordinate a fix.

---

## 📖 Documentation

### Improving Documentation

- Documentation is in the `docs/` folder
- Use clear, concise language
- Add code examples
- Update relevant sections

### API Documentation

API endpoints are documented using Laravel API Resources and OpenAPI/Swagger.

```bash
# Generate API documentation
php artisan api:generate
```

---

## 🎨 UI/UX Contributions

When contributing to the UI:

1. Follow the existing design system
2. Ensure responsive design
3. Test on multiple browsers
4. Consider accessibility (ARIA labels, keyboard navigation)
5. Use i18n for all user-facing text

---

## 🌍 Internationalization

We support multiple languages. When adding new text:

1. Add translation keys to `resources/lang/en/*.php`
2. Provide translations for other languages
3. Use Laravel's translation helpers: `__('key.subkey')`

---

## 🤝 Code of Conduct

Be respectful, inclusive, and collaborative. We value all contributions and want everyone to feel welcome.

---

## 📜 License

By contributing, you agree that your contributions will be licensed under the [AGPL-3.0](LICENSE) license.

---

## 🎯 Areas Looking for Help

- **Frontend:** Vue 3 components, PWA, Browser Extension
- **Backend:** Laravel, PHP, E2EE implementation
- **Testing:** Unit tests, integration tests, E2E tests
- **Documentation:** User guides, API docs, tutorials
- **Internationalization:** Translations to new languages
- **Security:** Security audits, penetration testing
- **Design:** UI/UX improvements

---

## 💬 Getting Help

- **Discussions:** [GitHub Discussions](https://github.com/vibecoder11200/2FA-Vault/discussions)
- **Issues:** [GitHub Issues](https://github.com/vibecoder11200/2FA-Vault/issues)
- **Discord:** [Join our Discord](https://discord.gg/XXXXX) (coming soon)

---

Thank you for contributing to 2FA-Vault! 🎉

---

*Last Updated: 2026-04-04*
