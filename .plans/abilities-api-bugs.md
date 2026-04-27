# WP Abilities API — Known Bugs

## `ai/*` abilities: permission callback called with `null`

**Affected abilities:** `ai/get-post-details` and likely others in the `ai` namespace.

**Error:**
```
WordPress\AI\Abilities\Utilities\Posts::permission_callback(): Argument #1 ($args) must be of type array, null given, called in /var/www/html/wp-includes/abilities-api/class-wp-ability.php on line 513
```

**Root cause:** The WP core `WP_Ability::check_permissions()` calls the registered `permission_callback` with `null` as the `$args` argument. The `ai` plugin's callbacks declare `array $args` (strict type), causing a PHP `TypeError` in PHP 8.x.

**Who owns the bug:** WP core (`class-wp-ability.php`) or the `ai` plugin — not `wp-ai-daemon`.

**Our workaround:** The abilities sidebar loop in `class-admin-page.php` wraps each iteration in a try/catch and silently skips any ability that throws, so a broken third-party ability can't crash the page or cut off script output.

```php
try {
    if ( ! $ability->check_permissions() ) continue;
    $label = $ability->get_label();
    $desc  = $ability->get_description();
} catch ( \Throwable $e ) {
    continue;
}
```

**Status:** Workaround in place. Watch for a fix in the `ai` plugin or WP core.
