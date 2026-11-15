<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
require_once __DIR__.'/lib/GithubPages.php';
cr_session();
$message = '';
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    cr_csrf_check();
    $action = (string)($_POST['action'] ?? '');
    if ($action === 'login') {
        if (password_verify((string)($_POST['password'] ?? ''), cr_config()['admin_password_hash'])) {
            session_regenerate_id(true);
            $_SESSION['admin'] = true;
            $_SESSION['admin_until'] = time() + cr_auth_lifetime();
            // Store a revocable 30-day HttpOnly cookie in addition to the PHP session.
            try {
                cr_remember_issue();
                $message = 'Logged in. This browser will remain signed in for 30 days.';
            } catch (Throwable $e) {
                $message = 'Logged in.';
                $error = 'Persistent sign-in could not be enabled. Check private data directory permissions.';
            }
            cr_audit('admin_login');
        } else { http_response_code(403); $error = 'Incorrect password'; }
    } elseif ($action === 'logout') {
        cr_remember_revoke_current();
        $_SESSION = [];
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
            setcookie(session_name(), '', cr_auth_cookie_options(time() - 3600));
        }
        $message = 'Logged out';
    } else {
        cr_require_admin();
        try {
            if ($action === 'github_settings') {
                cr_gp_set_default((string)($_POST['github_base'] ?? ''));
                $message = 'GitHub Pages directory saved';
            } elseif ($action === 'settings') {
                $hosts = array_filter(array_map('trim', preg_split('/\R/', (string)($_POST['allowed_hosts'] ?? ''))));
                $hosts = array_values(array_unique(array_map('cr_normalize_host', $hosts)));
                if (count($hosts) < 1 || count($hosts) > 100) throw new InvalidArgumentException('Allow 1–100 approved hosts');
                $reference = cr_normalize_host((string)($_POST['reference_host'] ?? ''));
                $next = ['allowed_hosts'=>$hosts,'reference_host'=>$reference];
                $next['test_destination'] = cr_validate_destination((string)($_POST['test_destination'] ?? ''), $next);
                cr_json_write(cr_data_dir().'/settings.json', $next);
                cr_audit('settings_updated', ['approved_hosts'=>$hosts]);
                $message = 'Settings saved';
            } elseif ($action === 'create') {
                $ids = preg_split('/\R/', (string)($_POST['ids'] ?? ''));
                $ids = array_values(array_unique(array_filter(array_map('trim', $ids))));
                if (!$ids || count($ids) > 1000) throw new InvalidArgumentException('Enter 1–1000 route IDs, one per line');
                $destination = cr_validate_destination((string)($_POST['destination'] ?? ''));
                $mode = (int)($_POST['mode'] ?? 302);
                $replace = !empty($_POST['overwrite']);
                $created = 0;
                $failures = [];
                foreach ($ids as $id) {
                    try {
                        cr_route_save(['id'=>$id,'destination'=>$destination,'mode'=>$mode,'title'=>(string)($_POST['title'] ?? $id)], $replace);
                        $created++;
                    } catch (Throwable $e) { $failures[] = $id.': '.$e->getMessage(); }
                }
                $message = "Saved {$created} route(s).";
                if ($failures) $error = implode('; ', array_slice($failures, 0, 8));
            } else { throw new InvalidArgumentException('Unknown action'); }
        } catch (Throwable $e) { http_response_code(400); $error = $e->getMessage(); }
    }
}
$loggedIn = cr_is_admin();
$settings = cr_settings();
$githubBase = cr_gp_default_base();
cr_html_headers();
?><!doctype html><html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Connector Router Admin</title><link rel="stylesheet" href="assets/style.css"></head><body><main><div class="top"><h1>Connector &amp; Routing</h1><a href="browser.php">File browser demo</a></div><p>First-party connector hosted on <strong><?=cr_str(cr_config()['base_url'])?></strong>.</p>
<?php if ($message): ?><div class="success"><?=cr_str($message)?></div><?php endif; ?>
<?php if ($error): ?><div class="error"><?=cr_str($error)?></div><?php endif; ?>
<?php if (!$loggedIn): ?>
<section><h2>Administrator sign-in</h2><form method="post"><input type="hidden" name="csrf" value="<?=cr_str(cr_csrf())?>"><input type="hidden" name="action" value="login"><label>Password<input type="password" name="password" required autocomplete="current-password"></label><button>Sign in</button></form></section>
<?php else: ?>
<form method="post" class="logout"><input type="hidden" name="csrf" value="<?=cr_str(cr_csrf())?>"><input type="hidden" name="action" value="logout"><button class="muted">Log out</button></form>
<section><h2>GitHub Pages Directory</h2><form method="post"><input type="hidden" name="csrf" value="<?=cr_str(cr_csrf())?>"><input type="hidden" name="action" value="github_settings"><label>Directory containing uploaded HTML files<input type="url" name="github_base" required placeholder="https://worldcc2022-eng.github.io/november14-events-world/" value="<?=cr_str($githubBase)?>"></label><button>Save GitHub Pages Directory</button></form><p>Each ID opens the matching ID.html file in this directory. No separate route registration is needed.</p></section>
<section><h2>1. Approved external domains</h2><p>These domains can be used as redirect destinations. For the initial test, <code>www.ub.edu</code> is configured as an external destination only. No third-party script injection is performed.</p><form method="post"><input type="hidden" name="csrf" value="<?=cr_str(cr_csrf())?>"><input type="hidden" name="action" value="settings"><label>Approved destination hosts (one per line)<textarea name="allowed_hosts" rows="5" required><?=cr_str(implode("\n", $settings['allowed_hosts']))?></textarea></label><label>External reference host<input name="reference_host" required value="<?=cr_str($settings['reference_host'])?>"></label><label>Test destination URL<input type="url" name="test_destination" required value="<?=cr_str($settings['test_destination'])?>"></label><button>Save settings</button></form></section>
<section><h2>2. Generate routes</h2><form method="post"><input type="hidden" name="csrf" value="<?=cr_str(cr_csrf())?>"><input type="hidden" name="action" value="create"><label>Event ID(s), one per line<textarea name="ids" rows="5" required>video-test-university-0001</textarea></label><label>Destination URL<input type="url" name="destination" required value="<?=cr_str($settings['test_destination'])?>"></label><label>Public description<input name="title" value="First-party routing test"></label><label>Redirect mode<select name="mode"><option value="302">302 — Temporary (recommended)</option><option value="301">301 — Permanent</option></select></label><label class="check"><input type="checkbox" name="overwrite" value="1">Update an existing ID</label><button>Create routes</button></form></section>
<section><h2>3. Test endpoints</h2><p><a href="health.php">Health check</a> · <a href="connector.php?Command=GetFoldersAndFiles&amp;Type=File">XML connector</a> · <a href="browser.php">File browser</a></p><p>Sample route (if created): <a href="go.php?id=video-test-university-0001">go.php?id=video-test-university-0001</a></p></section>
<section><h2>Recent routes</h2><table><thead><tr><th>ID</th><th>Target</th><th>Mode</th><th>Test</th></tr></thead><tbody><?php foreach (cr_latest_routes(30) as $route): ?><tr><td><?=cr_str($route['id'])?></td><td class="wrap"><?=cr_str($route['destination'])?></td><td><?=cr_str((string)$route['mode'])?></td><td><a href="<?=cr_str(cr_route_url($route['id']))?>">Open</a></td></tr><?php endforeach; ?></tbody></table></section>
<section><h2>4. Generator integration</h2><p>POST JSON to <code><?=cr_str(rtrim(cr_config()['base_url'],'/').'/import.php')?></code> using <code>Authorization: Bearer YOUR_API_TOKEN</code>. Use the server-side token printed by the installer. Never put it into generated HTML or frontend JavaScript.</p></section>
<?php endif; ?></main></body></html>
