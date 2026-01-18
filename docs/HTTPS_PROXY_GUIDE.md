# HTTPS & Proxy Configuration Guide

When Laravel apps run behind reverse proxies (Cloudflare, Caddy, nginx), they often don't detect HTTPS correctly. This causes several critical issues that can break your application in production.

## Common Issues

### 1. Mixed Content Errors

**Symptoms:**
- Browser console shows "Mixed Content" errors
- Assets load via `http://` on `https://` pages
- Filament admin forms are broken (select, file-upload, markdown editor don't work)
- JavaScript components fail to load

**Cause:**
Laravel generates URLs based on the incoming request. Behind a proxy, the request appears to be HTTP even when the user is accessing via HTTPS.

### 2. Storage Files Return 404

**Symptoms:**
- Uploaded files return 404 errors
- Files disappear after deployment
- Storage symlink exists but points to wrong location

**Cause:**
`php artisan storage:link` creates a symlink to the release's storage directory. With Deployer's release-based deployments, each release has its own storage directory, so files uploaded to one release are lost when you deploy a new release.

### 3. Insecure Redirect Loops

**Symptoms:**
- Infinite redirect loops
- "Too many redirects" errors
- Login redirects to HTTP instead of HTTPS

**Cause:**
Laravel's redirects use the detected scheme. Behind a proxy, it may generate `http://` redirects on an `https://` site.

## Solutions

### Solution 1: URL::forceScheme('https')

Add this to your `app/Providers/AppServiceProvider.php`:

```php
use Illuminate\Support\Facades\URL;

public function boot(): void
{
    // Force HTTPS in production (behind Cloudflare/Caddy proxy)
    if ($this->app->environment('production')) {
        URL::forceScheme('https');
    }
}
```

This forces all generated URLs to use HTTPS regardless of the detected scheme.

### Solution 2: TrustProxies Middleware

Add this to your `bootstrap/app.php`:

```php
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->trustProxies(
            at: '*',
            headers: Request::HEADER_X_FORWARDED_FOR |
                Request::HEADER_X_FORWARDED_HOST |
                Request::HEADER_X_FORWARDED_PORT |
                Request::HEADER_X_FORWARDED_PROTO |
                Request::HEADER_X_FORWARDED_AWS_ELB
        );
    })
    // ...
```

This tells Laravel to trust the `X-Forwarded-*` headers from your proxy.

### Solution 3: Correct APP_URL

Ensure your production `.env` has:

```env
APP_URL=https://yourdomain.com
```

**Not:**
```env
APP_URL=http://yourdomain.com  # Wrong!
```

### Solution 4: Storage Symlink to Shared Storage

The deployer-laravel-stack recipe handles this automatically with the `storage:link-custom` task. Configure your storage links:

```php
// In your deploy.php
set('storage_links', [
    'storage' => 'storage/app/public',  // public/storage -> shared/storage/app/public
]);
```

This creates a symlink to the **shared** storage directory, not the release directory.

## Verification

The recipe includes automatic verification:

### Pre-flight Checks (before deploy)

- Checks for `URL::forceScheme('https')` in AppServiceProvider
- Checks Filament assets exist locally
- Warns if local git is ahead of remote

### Post-deploy Verification

- `verify:https-redirects` - Confirms redirects use HTTPS
- `verify:filament-assets` - Confirms Filament JS/CSS accessible
- `verify:storage-symlink` - Confirms symlink points to shared storage
- `verify:storage-files` - Tests actual uploaded files are accessible

### Manual Checks

```bash
# Run all HTTPS/asset verifications
./deploy/dep verify:https-all prod

# Run individual checks
./deploy/dep check:https-config prod
./deploy/dep check:filament-assets prod
./deploy/dep verify:https-redirects prod
./deploy/dep verify:storage-symlink prod
```

## Checklist for New Projects

1. [ ] Add `URL::forceScheme('https')` to AppServiceProvider
2. [ ] Configure TrustProxies in bootstrap/app.php
3. [ ] Set `APP_URL=https://...` in production .env
4. [ ] Configure `storage_links` in deploy.php
5. [ ] Run `php artisan filament:assets` if using Filament
6. [ ] Push all changes to git before deploying
7. [ ] Run `./deploy/dep verify:https-all prod` after deploy

## Troubleshooting

### Still seeing mixed content errors?

1. Check browser console for the exact URLs failing
2. Clear config cache: `php artisan config:clear`
3. Restart Octane/PHP-FPM
4. Hard refresh browser (Cmd+Shift+R)

### Filament forms still broken?

1. Run `php artisan filament:assets` locally
2. Commit and push the generated files
3. Redeploy
4. Check assets exist at `/js/filament/support/support.js`

### Storage files 404?

1. Check symlink target: `readlink public/storage`
2. Should point to `shared/storage/app/public`
3. If wrong, run `./deploy/dep storage:link-custom prod`
