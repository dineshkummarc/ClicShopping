# SECURITY.md — ClicShopping AI v4.20+

> Transversal security — applies to the framework, modules, AI agents and APIs.
> Agent operational rules: `AGENTS.md`

---

## 1. General principle

ClicShopping AI security is organized into **10 independent layers**.
Each layer must remain active. Never bypass one, even in development.

---

## 2. Architecture in 10 layers

| # | Layer | Mechanism                                                           | Scope |
|---|---|---------------------------------------------------------------------|---|
| 1 | **Webserver** | Apache `.htaccess`: security headers, bot blocking, path restriction | All HTTP requests |
| 2 | **Sanitization** | `SecurityPro` module: XSS filtering, SQLi prevention                | User Inputs |
| 3 | **Authentication** | `Hash::verify`, secure session, tokens                              | Admin and shop login |
| 4 | **2FA email** | Codes 4-8 digits, expiry 5 min                                      | Admin Login |
| 5 | **Rate limiting** | Window 900s, max 20 requests per identifier                         | APIs and AI endpoints |
| 6 | **Account lock** | 5 failed attempts → lock 30 min                                     | Authentication |
| 7 | **AI Guardrails** | Prompt injection detection, Obfuscation scan, detection, scoring        | Endpoints LLM |
| 8 | **Encryption** | AES-256 for sensitive data                                          | Data storage |
| 9 | **GDPR** | Data export/deletion, audit log                                     | Personal data |
| 10 | **Monitoring** | Table `clic_rag_security_events`, health scoring, MCP               | Observability |

---

## 3. Mandatory rules for any code

### Inputs
- Validate **all** user inputs on the server side
- Use existing sanitation helpers — do not write custom filtering
- Never trust data from `$_GET`, `$_POST`, `$_COOKIE` without processing

### Outputs
- Escape all HTML output (`htmlspecialchars` or existing helpers)
- Never display raw data from the database or the user
- Templates cannot receive unprepared data

### Credentials and secrets
```
✗ Never hardcoded API key in the code
✗ Never credential in a versioned file
✗ Never internal path exposed in error messages
✗ Never unencrypted personal data in logs
```

Always use **configuration constants** defined via the admin interface.

---

## 4. Admin Endpoint Security

- Any admin endpoint must check the active admin session
- Never expose an admin endpoint without authentication
- Destructive actions (deletion, config modification) must check the CSRF token
- Respect the rate limiting layer for API endpoints

---

## 5. AI Security — Guardrails

### Prompt injection detection

Any input sent to an LLM goes through the scoring system before processing:
- Scan of known injection patterns
- Obfuscation scan, detection (encoding, homoglyphs, etc.)
- Calculated threat score — request blocked if threshold exceeded

**Never bypass this validation**, even for development tests.
Use dedicated staging environments for AI testing.

### Rate limiting AI

| Constant | Value | Role |
|---|---|---|
| `CLICSHOPPING_APP_API_AI_RATE_LIMIT_WINDOW` | 900s | Time window |
| `CLICSHOPPING_APP_API_AI_MAX_REQUEST_PER_WINDOW` | 20 | Max queries per identifier |
| `CLICSHOPPING_APP_API_AI_MAX_LOGIN_ATTEMPTS` | 5 | Attempts before lock |
| `CLICSHOPPING_APP_API_AI_ACCOUNT_LOCK_DURATION` | 1800s | Lockdown duration |

Related tables:
- `clic_api_rate_limit` — tracking requests by identifier + timestamp
- `clic_api_failed_attempts` — tracking failed attempts
- `clic_rag_security_events` — auditing AI security events

---
## 6. Authentication and sessions

### Sessions

Four backends with automatic fallback:
1. **Database** — persistent, table storage
2. **File** — native PHP fallback
3. **Memcached** — option to be activated - distributed cache, TTL = `session.gc_maxlifetime`
4. **Redis** — option to be activated - `localhost:6379`, prefix `sess_`, TTL = `session.gc_maxlifetime`


### 2FA (Two-Factor Authentication email)
- Enabled on the admin interface
- Code: 4 to 8 digits, expires 5 minutes
- Resistant to replay attacks (one-time code)

### TOTP (admin)
- Configuration: see wiki `How-to-set-Double-authentication-for-Catalog-and-Administration-Login-by-TOTP`

---

## 7. Encryption and GDPR

### Encryption of sensitive data
- Algorithm: AES-256 for critical personal data
- Do not implement custom encryption — use existing core helpers

### GDPR Compliance
- Export of personal data: native functionality not to be bypassed
- Data deletion: cascade managed by existing scripts
- Audit log: do not deactivate audit tables

---

## 8. Apache configuration (.htaccess)

The `.htaccess` file is a critical security layer.

Rules:
- Do not weaken existing security guidelines
- Do not expose the `Core/`, `Core/ClicShopping/Work/`, `install/` directories via HTTP
- Maintain security headers (`X-Frame-Options`, `X-Content-Type-Options`, etc.)
- SEO rewrite rules must not create path traversal flaws

---

## 9. What you should never expose

```
✗ Configuration files (config.php, .env, composer.json via HTTP)
✗ Core/ClicShopping/Work/ directory (cache and temporary files)
✗ install/ directory (must be removed or blocked after deployment)
✗ Credentials, API keys, passwords
✗ Internal server paths in error messages
✗ Stack traces PHP in production
✗ Non-anonymized personal data in logs
```

---

## 10. Security checklist before commit

```
[ ] Inputs validated on the server side
[ ] Outputs escaped in all templates
[ ] No credential or API key in the code
[ ] Admin endpoints protected by session verification
[ ] AI guardrails not bypassed
[ ] No audit or rate limiting tables deleted
[ ] .htaccess not weakened
[ ] No clear personal data in the logs
[ ] use always the sanitization helpers : HTML::sanitize()
```

---

## 11. References

- Architecture framework: `ARCHITECTURE.md`
- AI guardrails security: `AI_SYSTEM.md`
- DeepWiki security: https://deepwiki.com/ClicShopping/ClicShopping/7-security-architecture
