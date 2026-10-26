# HTTPS Setup

EasySched keeps plain HTTP available for local development. Production HTTPS enforcement is opt-in so a localhost defense installation is not broken by a missing certificate.

## Free XAMPP localhost HTTPS

1. In `C:\xampp\apache\conf\httpd.conf`, confirm these lines are enabled (no leading `#`):

```apache
LoadModule ssl_module modules/mod_ssl.so
Include conf/extra/httpd-ssl.conf
```

2. Restart Apache and test:

```text
https://localhost/EasySched-Defense/
```

XAMPP's bundled development certificate may show a browser warning because it is not publicly trusted. This is acceptable only for local development and defense testing.

3. After the HTTPS URL works, add this to `httpd.conf`:

```apache
SetEnv EASYSCHED_FORCE_HTTPS "1"
```

4. Restart Apache. Opening the HTTP URL should now redirect to HTTPS.

## Reverse-proxy hosting

When Render, Railway, Cloudflare, Nginx, or another trusted reverse proxy terminates HTTPS, configure these variables on the PHP server:

```text
EASYSCHED_FORCE_HTTPS=1
EASYSCHED_TRUST_PROXY=1
EASYSCHED_PUBLIC_URL=https://www.easysched.tech
```

Set `EASYSCHED_PUBLIC_URL` to the canonical public HTTPS origin (scheme and hostname only; no path or nonstandard port). It is used for HTTPS redirects so an internal application port such as Railway's `8080` is never exposed in browser redirects. `EASYSCHED_TRUST_PROXY` recognizes the first value of `X-Forwarded-Proto`; the proxy must replace that header. Only enable proxy trust when requests can reach PHP exclusively through a trusted proxy. Do not enable it on a directly exposed server where clients can supply that header themselves.

On Railway, add the variables under the EasySched web service's **Variables**, not the PostgreSQL service, then redeploy the service.

The Docker image also disables Apache's automatic trailing-slash redirect specifically for `/data`. That path is both an application route and a private session-storage directory in older deployments; without this setting, Apache can redirect to its internal Railway port before the application rewrite runs. Deploy the updated Dockerfile to apply the fix.

## Result

On verified HTTPS connections EasySched automatically uses:

- Secure, HttpOnly, SameSite session cookies
- Strict session mode and cookie-only session identifiers
- HTTP Strict Transport Security
- Content Security Policy with insecure-request upgrading
- Cross-origin opener and resource protection
- No-cache protection for authenticated responses
