Snippets execute immediately with full WordPress privileges and cannot be undone. The snippet runs as the web server user with access to $wpdb credentials, all loaded classes, all constants including secret keys, and all superglobals. Treat every snippet as if it will be reviewed by a security auditor.

## BEFORE WRITING

- Prefer read-only operations: get_option(), $wpdb->get_results(), get_post(), checking constants.
- If the snippet modifies data, describe exactly what it will change in your message field before running it.

## FORBIDDEN — code execution escalation

- eval(), assert( $string ), create_function(), preg_replace with /e modifier
- Passing user-derived strings to call_user_func() or call_user_func_array()

## FORBIDDEN — OS access (no exceptions)

- exec(), system(), shell_exec(), passthru(), popen(), proc_open(), pcntl_exec()
- Backtick operator

## FORBIDDEN — filesystem writes

- file_put_contents(), fwrite(), fopen() for writing, unlink(), rename(), mkdir(), rmdir(), chmod(), symlink()
- Writing outside wp-content/uploads/ without explicit stated purpose

## FORBIDDEN — network and exfiltration

- file_get_contents() for remote URLs — use wp_remote_get() if a fetch is truly needed
- fsockopen(), stream_socket_client()
- Sending data to any external URL without the user's explicit instruction

## FORBIDDEN — information disclosure

Never output:

- DB_PASSWORD, DB_USER, DB_HOST, DB_NAME
- AUTH_KEY, SECURE_AUTH_KEY, LOGGED_IN_KEY, NONCE_KEY and all *_SALT constants
- Any value from $wpdb that exposes credentials
- phpinfo() output
- Full filesystem paths from $_SERVER or PHP constants
- User password hashes from the database

If a secret value must be inspected, show only the first/last 4 characters or confirm presence with a boolean.

## FORBIDDEN — deserialization

- unserialize() or maybe_unserialize() on any string from user input or external sources

## FORBIDDEN — variable manipulation

- extract( $_POST ), extract( $_GET ), extract( $_REQUEST )
- parse_str() without a second argument

## FORBIDDEN — sessions

- session_start() and all session_*() functions

## SAFE PATTERNS

- Database reads: global $wpdb; return $wpdb->get_results( $wpdb->prepare( 'SELECT ...', $args ) );
- Option reads: return get_option( 'option_name' );
- Constant checks: return defined( 'WP_DEBUG' ) && WP_DEBUG ? 'on' : 'off';
- URL fetches: $r = wp_remote_get( 'https://...' ); return wp_remote_retrieve_body( $r );
