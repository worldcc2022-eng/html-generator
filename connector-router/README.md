# First-party XML Connector & Route Manager

Deploy on `https://html.surprisestream.com/connector-router` as a **standalone module**, or integrate it into your existing PHP HTML Generator. Requires PHP 8.2+, working HTTPS, and write access to a private data directory. No database or Node.js is needed.

**Important boundary:** This package does not exploit or inject markup into a third-party FCKeditor file browser. It implements its own valid XML connector, file browser, and authenticated route-creation API. It can redirect to an administrator-approved third-party HTTPS domain. The initial external test destination is **`https://www.ub.edu/`**; this is *only* a destination that your own host redirects to. No content is injected into `www.ub.edu`.

## 1. Install without modifying the existing generator

Upload the `connector-router` folder into:

`/home/surprisephp/public_html/connector-router/`

For example, from an SSH session in `/home/surprisephp/public_html/`:

```bash
unzip connector-router.zip
```

Run the installer as the PHP site owner, **not root**, so config files and private data are owned by the correct account. Replace the example password with a private 12+-character password. If the site account has a different name, adapt the `runuser` command.

```bash
runuser -u surprisephp -- env \
  CR_BASE_URL='https://html.surprisestream.com/connector-router' \
  CR_ADMIN_PASSWORD='REPLACE_WITH_A_LONG_PRIVATE_PASSWORD' \
  CR_DATA_DIR='/home/surprisephp/connector-router-private' \
  php /home/surprisephp/public_html/connector-router/bin/install.php
```

**Security:** Passing a password in an environment variable can expose it to users able to inspect the current process. On a sensitive server, invoke the installer in an interactive session under the site account and enter the password using a non-echoing prompt, or supply it through a protected environment file. Do not keep credentials in shell history or share them in chat.

Installer output shows the API token once. Save it securely. `config.local.php` stores the hash and token, and the data directory should be **outside** `public_html`.

### Initial smoke test

```bash
runuser -u surprisephp -- php /home/surprisephp/public_html/connector-router/bin/smoke.php

# Check that your module responds
curl -sS 'https://html.surprisestream.com/connector-router/health.php'

# Check the generated XML (valid metadata; no scripts)
curl -sS 'https://html.surprisestream.com/connector-router/connector.php?Command=GetFoldersAndFiles&Type=File'

# Inspect the 302 without following it
curl -sSI 'https://html.surprisestream.com/connector-router/go.php?id=video-test-university-0001'
```

Expected redirect header after the smoke test:

`Location: https://www.ub.edu/`

The smoke test creates the sample route if it does not exist. It uses `www.ub.edu` as a redirect **destination**, not as the host serving the connector.

### Browser pages

- Admin: `https://html.surprisestream.com/connector-router/admin.php`
- Self-hosted browser: `https://html.surprisestream.com/connector-router/browser.php`
- XML: `https://html.surprisestream.com/connector-router/connector.php`
- Route: `https://html.surprisestream.com/connector-router/go.php?id=video-test-university-0001`

## 2. Manually enter or change the third-party domain

Open **Admin → Approved external domains**. To test with the observed example, enter `www.ub.edu` as an approved destination host and `https://www.ub.edu/` as the test destination URL. You can change the host later to any HTTPS domain you are authorized to redirect visitors to.

The list uses **exact host matching**: `example.com` does not automatically allow `sub.example.com`. Private IP addresses, ports, non-HTTPS URLs, and URLs containing embedded credentials are rejected. Updating the allowlist immediately invalidates routes whose destinations are no longer approved.

**The `External reference host` field is informational only.** It does not load third-party FCKeditor pages, build `CONNECTOR` payload URLs, or execute scripts on third-party websites.

## 3. Use the import API from the existing HTML Generator

The API accepts up to 1,000 records per JSON POST. It is intended to be called **server-to-server by your PHP Generator**, never from generated HTML or client-side JavaScript.

```bash
TOKEN='YOUR_PRIVATE_API_TOKEN'
curl -sS -X POST \
  -H "Authorization: Bearer $TOKEN" \
  -H 'Content-Type: application/json' \
  --data-binary @/home/surprisephp/public_html/connector-router/samples/import.json \
  'https://html.surprisestream.com/connector-router/import.php'
```

Import payload example:

```json
{
  "overwrite": false,
  "routes": [
    {
      "id": "video-example-a1b2c3d4",
      "destination": "https://www.ub.edu/",
      "mode": 302,
      "title": "Example event"
    }
  ]
}
```

Response: `created_count`, `error_count`, generated `url` for each accepted ID, and per-ID errors. Existing IDs are rejected by default. Use `overwrite: true` only when intentionally changing an existing mapping.

The module does not generate HTML itself. Your existing generator continues to generate HTML, titles, IDs, events, GitHub uploads, server uploads, and domain distribution. It additionally sends selected ID→destination mappings to this module. Do not replace its current logic.

## 4. File inventory

| File | Role |
|---|---|
| `bin/install.php` | One-time CLI installer |
| `bin/smoke.php` | Local verification and initial demo route |
| `config.local.php` | Generated during installation; private password hash and import token |
| `lib/Core.php` | Validation, file storage, sessions, routes |
| `admin.php` | Password-protected destination settings and manual bulk entry |
| `import.php` | Bearer-token protected server-to-server batch import |
| `go.php` | ID lookup with 301/302 redirect to approved HTTPS hosts |
| `connector.php` | Read-only FCKeditor-style XML list of generated IDs |
| `browser.php` | First-party file-browser-style HTML demonstration |
| `health.php` | Public health endpoint |
| `assets/style.css` | Admin/browser styling |
| `samples/import.json` | Example API payload |
| `AUTOMATION_PROMPT.md` | Prompt for existing HTML Generator development chat |

## 5. Deployment safety

- Upload to a **subfolder**, not over `index.php` or `.htaccess` in your existing generator root.
- Run the installer as the actual PHP site account. A `0600` config created by root will not normally be readable by the PHP account.
- Configure a private data directory outside the document root.
- Protect the import API token like a password; never put it in public files.
- The included Apache/LiteSpeed `.htaccess` rules block access to source-only folders, but if your environment uses Nginx, configure explicit access controls as well.
- If your site has a restrictive content-security policy, allow local CSS for this subfolder.
- Run the smoke test and confirm XML parsing and 302 headers before connecting bulk import.
- A 301 may be cached by browsers: default to **302** while testing.

## 6. Known scope limits

This is a standalone drop-in route module; it is not a patch to your existing generator because its source code is not attached here. For complete automation, give `AUTOMATION_PROMPT.md` to the development chat that has the generator's actual code. That chat should integrate the server-to-server `import.php` API and preserve current URL/domain redistribution behavior.

The connector returns recent items (up to 50); the router can address an ID directly via `connector.php?id=...`. It is not a full replacement for all legacy FCKeditor upload/write endpoints.
