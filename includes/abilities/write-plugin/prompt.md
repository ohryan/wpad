## BEFORE WRITING

WordPress has many hooks with subtle timing and data-availability differences. If you are uncertain about which hook to use, or whether a specific API is available at a given point in the request lifecycle, **use the web-fetch ability to look it up first** before writing the plugin.

Canonical references:
- Hook reference: `https://developer.wordpress.org/reference/hooks/{hook-name}/`
- Function reference: `https://developer.wordpress.org/reference/functions/{function-name}/`
- Hook execution order: `https://developer.wordpress.org/apis/hooks/action-reference/`

Fetch the hook reference page when: choosing between multiple hooks that seem similar, working with post/term/meta lifecycle events, or integrating with REST API, block editor, or WooCommerce.

### Third-party plugin integrations

If the plugin you are writing extends another plugin (WooCommerce, ACF, Gravity Forms, Elementor, Yoast SEO, etc.), use your training data as a starting point. If you are uncertain about a specific hook, filter, or function — or if the integration is non-trivial — fetch the relevant documentation before writing.

Common documentation roots:
- WooCommerce: `https://woo.com/documentation/woocommerce/` and `https://woocommerce.github.io/code-reference/`
- Advanced Custom Fields: `https://www.advancedcustomfields.com/resources/`
- Gravity Forms: `https://docs.gravityforms.com/`
- Elementor: `https://developers.elementor.com/docs/`
- Yoast SEO: `https://developer.yoast.com/`
- Easy Digital Downloads: `https://easydigitaldownloads.com/docs/`

You can also use run-snippet to confirm the plugin is active and check its version before writing, which helps when the right API depends on the installed version:
```php
return [
    'active'  => is_plugin_active( 'woocommerce/woocommerce.php' ),
    'version' => defined( 'WC_VERSION' ) ? WC_VERSION : null,
];
```

---

## WORDPRESS PATTERNS & GOTCHAS

### Post lifecycle hook timing

Hooks fire in this order on a post save. Data availability varies:

1. `pre_post_update` — before DB write; no new data yet
2. `transition_post_status` — fires as status changes but **terms and meta are not yet committed**
3. `save_post_{post-type}` / `save_post` — post row is written; meta may be partially saved depending on caller; **terms are not reliable here**
4. `set_object_terms` — fires when terms are actually written (mid-save, not end-of-save)
5. `wp_after_insert_post` *(WP 5.6+)* — fires after the full save including meta and terms; **this is the reliable hook for "post is fully saved"**

**Rule:** If your logic depends on the post's terms, tags, or categories being present, use `wp_after_insert_post`. Do not use `transition_post_status` or `save_post` for term-dependent logic.

`wp_after_insert_post` signature:
```php
add_action( 'wp_after_insert_post', function( $post_id, $post, $update, $post_before ) {
    // $update = true means this is an edit, false means new post
    // $post_before = WP_Post state before save (null for new posts)
}, 10, 4 );
```

For "when a post is published with a specific tag":
```php
add_action( 'wp_after_insert_post', function( $post_id, $post ) {
    if ( $post->post_status !== 'publish' ) return;
    if ( ! has_tag( 'my-tag', $post_id ) ) return;
    // act here
}, 10, 2 );
```

### Request lifecycle hook order

```
muplugins_loaded → plugins_loaded → setup_theme → after_setup_theme →
init → wp_loaded → parse_request → wp → template_redirect → wp_head
```

- `plugins_loaded` — safe for loading plugin dependencies; too early for user/role data
- `init` — register post types, taxonomies, shortcodes, REST routes; current user is available
- `wp_loaded` — all plugins and theme are fully loaded; safe for anything that needs the full environment
- `admin_init` — fires on every admin page load; do not run expensive queries here unconditionally
- Never call `get_current_user_id()` before `init`

### Term and taxonomy functions

- `has_tag( $tag, $post_id )` — checks if post has the tag; works correctly inside `wp_after_insert_post`
- `wp_get_post_terms( $post_id, 'post_tag' )` — bypasses object cache; use when you need fresh data
- `get_the_terms( $post_id, 'post_tag' )` — uses object cache; fine in most contexts
- After `wp_set_post_terms()` is called, the cache is automatically invalidated

### Options and settings

- Always `register_setting()` with a `sanitize_callback` — never write to options from a POST handler without it
- Prefix option names with your plugin slug to avoid collisions: `my_plugin_option_name`
- For complex data, store as JSON via `wp_json_encode()` / `json_decode()`, never serialized PHP

### Enqueueing assets

- Always enqueue on `wp_enqueue_scripts` (frontend) or `admin_enqueue_scripts` (admin)
- Never `echo <script>` or `echo <link>` directly — use `wp_enqueue_script()` / `wp_enqueue_style()`
- Use `wp_localize_script()` to pass PHP data to JavaScript; never inline dynamic values into script tags

### Custom DB tables

- Create tables in an activation hook via `dbDelta()` — never on every request
- Always check `dbDelta()` output when debugging; it silently skips columns it can't modify
- Prefix table names with `$wpdb->prefix`
- Store the schema version in an option and run migrations conditionally on that version

---

Every PHP file must begin with: if ( ! defined( 'ABSPATH' ) ) exit;

## ACCESS CONTROL

- Use current_user_can() for every privileged operation — never is_admin() (it checks URL context, not role).
- Check capability before touching data. Late checks are bypassed if earlier code causes damage.
- Common capabilities: manage_options (admin settings), edit_posts, edit_others_posts, upload_files, install_plugins.

## CSRF / NONCES

- Forms: wp_nonce_field( 'action_name', 'nonce_field' ) in output; wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['nonce_field'] ) ), 'action_name' ) on submit.
- AJAX handlers: check_ajax_referer( 'action_name', 'nonce' ) before anything else.
- REST endpoints: nonce passed as X-WP-Nonce header using wp_create_nonce( 'wp_rest' ).
- Settings API forms: settings_fields() handles nonces automatically.
- Make nonce action strings specific — include a record ID or context to prevent cross-context reuse.

## INPUT SANITIZATION

Always wp_unslash() before sanitizing (WordPress magic-quotes all superglobals).

- Single-line text: sanitize_text_field( wp_unslash( $_POST['field'] ) )
- Multi-line plain text: sanitize_textarea_field()
- Integers: absint() for non-negative, intval() for signed
- URLs for storage: sanitize_url() or esc_url_raw()
- Email: sanitize_email()
- Filenames: sanitize_file_name()
- Keys/slugs: sanitize_key()
- Rich HTML: wp_kses_post() or wp_kses( $data, $allowed )
- SQL ORDER BY from input: sanitize_sql_orderby()
- Prefer allowlists (in_array with strict true) over blocklists for enumerated values.

## OUTPUT ESCAPING

Escape immediately before output, not at storage time.

- HTML content: esc_html()
- HTML attributes: esc_attr()
- URLs in href/src/action: esc_url()
- Inline `<script>` values: esc_js()
- Textarea content: esc_textarea()
- JSON for JavaScript: wp_json_encode()
- Rich HTML output: wp_kses_post()
- Translatable strings: esc_html__(), esc_attr__() — never __() alone in HTML context
- add_query_arg() and remove_query_arg() output must be wrapped with esc_url()
- $_SERVER['REQUEST_URI'], $_SERVER['PHP_SELF'], $_SERVER['HTTP_REFERER'] are user-controllable — always escape before output

## DATABASE

- Always use $wpdb->prepare() with typed placeholders: %d (int), %f (float), %s (string), %i (identifier, WP 6.2+)
- Safe helpers that handle escaping internally: $wpdb->insert(), $wpdb->update(), $wpdb->delete()
- LIKE: $like = '%' . $wpdb->esc_like( $term ) . '%'; then pass through prepare()
- IN with arrays: build placeholder string dynamically, spread array into prepare()
- Never esc_sql() as sole protection — it is not parameterization

## AJAX HANDLERS

- Register wp_ajax_{action} (never wp_ajax_nopriv_ for data-modifying actions)
- Pattern: check_ajax_referer → current_user_can → sanitize inputs → do work → wp_send_json_success/error
- Always end with wp_send_json_success(), wp_send_json_error(), or wp_die() — never fall through
- Pass explicit HTTP status codes to wp_send_json_error() (e.g. 403, 500)

## REST API ENDPOINTS

- permission_callback is mandatory — omitting it creates a public endpoint
- Use '__return_true' explicitly for genuinely public read endpoints; never omit
- Define validate_callback and sanitize_callback for every argument
- Return array or WP_REST_Response — never echo, wp_send_json, or die() inside a REST callback

## OPTIONS / SETTINGS

- Always register_setting() with a sanitize_callback
- Store complex data as JSON (wp_json_encode / json_decode), never as PHP-serialized data
- Never call update_option() or update_post_meta() directly from a GET/POST handler without nonce + capability check

## FILESYSTEM

- Use WP_Filesystem exclusively — never file_put_contents(), fwrite(), fopen(), unlink(), rename(), mkdir(), chmod(), symlink()
- Validate file paths from user input: realpath() the result and confirm it starts within the intended base directory
- Use validate_file() to detect path traversal sequences

## HTTP REQUESTS

- Use wp_remote_get() / wp_remote_post() — never curl_*(), file_get_contents() for URLs, fsockopen()
- Redirects: wp_safe_redirect( esc_url_raw( $url ) ); exit; — never wp_redirect() with user-supplied URLs
- Validate user-supplied URLs with wp_http_validate_url() before fetching; reject non-http/https schemes and internal IPs

## SERIALIZATION

- Never unserialize() or maybe_unserialize() on any user-supplied, externally-fetched, or untrusted data — use JSON

## FORBIDDEN FUNCTIONS

Never use in generated plugins:

- Code execution: eval(), assert( $string ), create_function(), preg_replace with /e modifier
- OS commands: exec(), system(), shell_exec(), passthru(), popen(), proc_open(), pcntl_exec(), backtick operator
- Dangerous variable manipulation: extract() from superglobals, parse_str() without second argument
- PHP sessions: session_start() and all session_*() functions (incompatible with WP caching)
- Dynamic includes with variable paths: include($var), require($var) where $var is user-influenced

## DO NOT

- Auto-activate the plugin or auto-publish any content
- Use is_admin() for capability checks
- Register wp_ajax_nopriv_ for any action that reads private data or modifies anything
- Omit permission_callback from REST routes
- Use esc_sql() as the only SQL protection
- Echo add_query_arg() output without esc_url()
- Store PHP-serialized objects in options or meta when JSON suffices
