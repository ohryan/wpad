# WP-CLI AI Abilities — Implementation Plan

## Overview

This plan covers implementing WP-CLI-equivalent capabilities as native AI abilities in WP AI Daemon. Rather than shelling out to `wp-cli`, each ability uses WordPress PHP functions directly — the same underlying APIs WP-CLI uses — wrapped in the existing `WP_AI_Daemon_Action` interface and registered via the WordPress Abilities API.

**Guiding principle:** Follow the exact patterns established by existing actions (`class-action-web-fetch.php`, `class-action-create-post.php`, etc.). Each ability is a self-contained PHP class, registered in `wp_ai_daemon_action_classes()`, and described to the LLM via `get_ability_args()`.

---

## Codebase conventions to follow

- **Interface:** `WP_AI_Daemon_Action` — `execute( array $params ): array|WP_Error` + `get_ability_args(): array`
- **File naming:** `includes/actions/class-action-{slug}.php`
- **Ability name:** `wp-ai-daemon/{hyphenated-slug}` (e.g. `wp-ai-daemon/find-posts`)
- **Registration:** Add to `wp_ai_daemon_action_classes()` in `wp-ai-daemon.php`
- **Annotation:** Each action includes `annotations.instructions` describing behavioral constraints for the LLM
- **Approval:** `requires_approval: true` for anything that writes, deletes, or modifies data; `false` for reads

---

## New abilities

### Group 1: Content operations

#### `wp-ai-daemon/analyze-post`
**File:** `includes/actions/class-action-analyze-post.php`
**WP-CLI equivalent:** `wp post get`, `wp term list`

Read-only. Returns full content, current tags/categories, excerpt, and meta for a single post. Provides the LLM context before it calls write abilities. Used as a prerequisite step before `update_post`, `set-post-taxonomy`, or `write-excerpt`.

```
input_schema:
  post_id: integer (required)
  include_meta: boolean (default false) — include all postmeta

output_schema:
  post_id, title, content, excerpt, status, tags[], categories[], meta{}
  
requires_approval: false
annotations.readonly: true
```

#### `wp-ai-daemon/set-post-taxonomy`
**File:** `includes/actions/class-action-set-post-taxonomy.php`
**WP-CLI equivalent:** `wp post term set`, `wp post term add`

Sets or appends tags and categories on an existing post. Distinct from `update_post` so the LLM can auto-tag/categorize without touching content. Always targets draft or existing posts — never changes `post_status`.

```
input_schema:
  post_id: integer (required)
  tags: string[] — tag names (will be created if they don't exist)
  categories: string[] — category names (will be created if they don't exist)
  append: boolean (default false) — if true, adds to existing terms; if false, replaces

output_schema:
  post_id, tags_set[], categories_set[]

requires_approval: true
annotations.instructions: Always call analyze-post first to see existing taxonomy. Never
  remove all categories from a post. If append is false, confirm with the user before
  replacing existing terms.
```

#### `wp-ai-daemon/write-excerpt`
**File:** `includes/actions/class-action-write-excerpt.php`
**WP-CLI equivalent:** `wp post update --post_excerpt`

Sets the manual excerpt on a post. Purely a field update — does not touch content, title, or taxonomy.

```
input_schema:
  post_id: integer (required)
  excerpt: string (required) — plain text, max ~55 words

output_schema:
  post_id, excerpt, edit_link

requires_approval: true
```

#### `wp-ai-daemon/bulk-update-meta`
**File:** `includes/actions/class-action-bulk-update-meta.php`
**WP-CLI equivalent:** `wp post meta update` (batched)

Updates one or more meta keys across a set of posts matching a query. Returns a dry-run summary before executing; execution requires the `confirmed: true` param. Scoped to meta keys explicitly listed by the LLM — never a wildcard delete.

```
input_schema:
  post_ids: integer[] (required, max 50) — explicit list of post IDs to update
  meta_updates: array of { key: string, value: string|number|boolean }
  confirmed: boolean (default false) — if false, returns dry-run summary only

output_schema (dry run):
  dry_run: true
  affected_posts: integer
  changes: [{ post_id, key, old_value, new_value }]

output_schema (confirmed):
  dry_run: false
  updated: integer
  failed: [{ post_id, key, error }]

requires_approval: true
annotations.instructions: Always perform a dry run first (confirmed: false) and show the
  user the summary before calling again with confirmed: true. Never update more than 50
  posts in a single call. Do not use this to modify protected keys like _edit_lock,
  _wp_trash_meta_*, or _thumbnail_id without explicit user instruction.
```

---

### Group 2: Search & audit

#### `wp-ai-daemon/find-posts`
**File:** `includes/actions/class-action-find-posts.php`
**WP-CLI equivalent:** `wp post list --search`, `wp post list --meta_query`

Translates a natural-language description into a `WP_Query` and returns matching posts. The LLM constructs query params from the description; this ability executes them.

```
input_schema:
  post_type: string|string[] (default 'any')
  post_status: string|string[] (default ['publish','draft'])
  s: string — full-text search string
  meta_key: string — filter by meta presence
  meta_value: string — filter by meta value
  tag: string — tag slug
  category_name: string — category slug
  author: integer — author user ID
  date_query: { after: string, before: string } — ISO 8601
  orderby: string (default 'date')
  order: 'ASC'|'DESC' (default 'DESC')
  per_page: integer (default 20, max 100)

output_schema:
  total: integer
  posts: [{ post_id, title, status, date, edit_link, excerpt }]

requires_approval: false
annotations.instructions: Return post IDs and titles only — do not return full content.
  If per_page is not specified, default to 20. If total exceeds per_page, note the
  remaining count and ask the user if they want to refine or paginate.
```

#### `wp-ai-daemon/audit-content`
**File:** `includes/actions/class-action-audit-content.php`
**WP-CLI equivalent:** No direct equivalent — typically custom scripts or plugins

Scans a set of posts (or all posts, up to a batch limit) for common content issues: broken shortcodes (unregistered tags), images missing alt text, and references to orphaned media IDs (attachments that no longer exist).

```
input_schema:
  post_ids: integer[] — explicit list; if omitted, scans most recent N posts
  scan_limit: integer (default 50, max 200)
  checks: string[] — one or more of: 'broken_shortcodes', 'missing_alt_text',
    'orphaned_media' (default: all three)

output_schema:
  scanned: integer
  issues: [{
    post_id: integer
    post_title: string
    edit_link: string
    issue_type: 'broken_shortcode'|'missing_alt_text'|'orphaned_media'
    detail: string — human-readable description
  }]
  summary: string — plain text overview

requires_approval: false
annotations.readonly: true
annotations.instructions: This is a read-only audit. Do not attempt to fix issues
  automatically unless the user explicitly asks. Present issues grouped by type.
  Limit output to the most actionable issues if the list is large.
```

---

### Group 3: User management

#### `wp-ai-daemon/create-user`
**File:** `includes/actions/class-action-create-user.php`
**WP-CLI equivalent:** `wp user create`

Creates a WordPress user. The LLM determines the appropriate role from a description (e.g. "a contributing writer who shouldn't be able to publish" → `contributor`). Always generates a random secure password; optionally sends the new-user email.

```
input_schema:
  user_login: string (required) — must be unique
  user_email: string (required)
  display_name: string
  role: string (default 'subscriber') — WP role slug
  send_user_notification: boolean (default false)
  first_name: string
  last_name: string

output_schema:
  user_id: integer
  user_login: string
  user_email: string
  role: string
  edit_link: string

requires_approval: true
annotations.instructions: Valid roles are subscriber, contributor, author, editor,
  administrator. Never assign administrator role unless the user has explicitly asked
  for it and confirmed. Always use a cryptographically random password — never accept
  a password param from the user. Confirm role assignment with the user before calling
  if the role is editor or administrator.
```

#### `wp-ai-daemon/find-users`
**File:** `includes/actions/class-action-find-users.php`
**WP-CLI equivalent:** `wp user list`

Queries users by role, registration date, post count, or login activity. Useful for finding inactive accounts, identifying prolific contributors, or auditing role assignments.

```
input_schema:
  role: string|string[]
  registered_after: string — ISO 8601
  registered_before: string — ISO 8601
  has_published_posts: boolean — filter to users with at least one published post
  search: string — searches login, email, display name
  orderby: 'login'|'registered'|'post_count' (default 'registered')
  order: 'ASC'|'DESC'
  per_page: integer (default 20, max 100)

output_schema:
  total: integer
  users: [{ user_id, login, display_name, email, role, registered, post_count, edit_link }]

requires_approval: false
annotations.readonly: true
annotations.instructions: Do not return password hashes or sensitive meta. If querying
  for users to delete or demote, return the list and ask for explicit confirmation
  before taking any action.
```

---

### Group 4: Plugin & theme diagnostics

#### `wp-ai-daemon/list-plugins`
**File:** `includes/actions/class-action-list-plugins.php`
**WP-CLI equivalent:** `wp plugin list`

Returns the current plugin inventory with activation status, version, and — where available — update status. The LLM summarizes what each plugin does and flags plugins that are inactive, outdated, or have known vulnerability information via the WordPress.org Plugins API.

```
input_schema:
  status: 'active'|'inactive'|'all' (default 'all')
  with_update_info: boolean (default true) — calls wp_update_plugins() cache

output_schema:
  plugins: [{
    slug: string
    name: string
    version: string
    status: 'active'|'inactive'|'must-use'|'drop-in'
    update_available: boolean
    latest_version: string|null
    description: string
    author: string
  }]

requires_approval: false
annotations.readonly: true
annotations.instructions: When reporting diagnostics, call list-plugins first to
  understand what is installed. Summarize what each active plugin does in plain
  language. Flag any plugins with available updates. Flag any inactive plugins
  that have been inactive for a long time as potential candidates for removal
  (but never remove them — surface for human review only).
```

#### `wp-ai-daemon/check-plugin-conflicts`
**File:** `includes/actions/class-action-check-plugin-conflicts.php`
**WP-CLI equivalent:** (no direct equivalent — typically requires manual review)

Scans for common conflict indicators: duplicate function/hook definitions in active plugins (PHP function_exists checks), plugins known to conflict based on shared function name prefixes, and PHP error log entries mentioning plugin slugs. Returns a structured report.

```
input_schema:
  check_php_log: boolean (default true) — scan wp-content/debug.log if present
  deep_scan: boolean (default false) — enumerate plugin hooks (slower)

output_schema:
  conflicts: [{
    severity: 'error'|'warning'|'info'
    plugins: string[] — involved plugin slugs
    description: string
    source: 'php_log'|'hook_analysis'|'heuristic'
  }]
  log_errors_found: integer
  scanned_at: string — ISO 8601

requires_approval: false
annotations.readonly: true
annotations.instructions: This is a heuristic scan, not definitive proof of conflicts.
  Communicate uncertainty. Never deactivate plugins based on this scan alone —
  present findings and ask the user how to proceed.
```

---

### Group 5: Database search & replace

#### `wp-ai-daemon/db-search-replace`
**File:** `includes/actions/class-action-db-search-replace.php`
**WP-CLI equivalent:** `wp search-replace`

Performs a search-replace across the WordPress database (all tables or a specified subset). Always performs a dry run first unless `confirmed: true`. Handles serialized data correctly via `maybe_unserialize` / `maybe_serialize`. Scoped to `wp_posts`, `wp_postmeta`, `wp_options`, `wp_usermeta`, `wp_comments`, `wp_commentmeta` by default — never touches custom tables or the users table's auth fields.

```
input_schema:
  search: string (required)
  replace: string (required)
  tables: string[] — defaults to safe subset listed above
  confirmed: boolean (default false)

output_schema (dry run):
  dry_run: true
  search: string
  replace: string
  matches: [{ table: string, column: string, count: integer }]
  total_replacements: integer

output_schema (confirmed):
  dry_run: false
  replaced: [{ table: string, column: string, count: integer }]
  total_replacements: integer

requires_approval: true
annotations.instructions: ALWAYS perform a dry run first (confirmed: false) and
  present the full match summary to the user before calling with confirmed: true.
  Never search-replace URLs without confirming the user understands serialized data
  implications. Never replace empty string (search == '') — reject with an error.
  Never touch wp_users, wp_user_roles option, or authentication keys.
```

**Implementation note:** Use `$wpdb->get_results()` to enumerate matches. Handle serialized PHP strings by wrapping replacements with `maybe_unserialize`/`maybe_serialize`. This replicates the core WP-CLI behavior without shell exec.

---

### Group 6: Cron management

#### `wp-ai-daemon/list-cron-events`
**File:** `includes/actions/class-action-list-cron-events.php`
**WP-CLI equivalent:** `wp cron event list`

Returns all scheduled WP-Cron events with their hook names, next run times, intervals, and a plain-language description of likely purpose (derived from hook name pattern matching and known WP core/popular plugin hooks).

```
input_schema:
  include_past_due: boolean (default true) — include events that missed their window
  filter_hook: string — filter by partial hook name
  source: 'all'|'core'|'plugins'|'wp-ai-daemon' (default 'all')

output_schema:
  events: [{
    hook: string
    next_run: string — ISO 8601
    interval_seconds: integer|null
    schedule: string|null — human readable (e.g. 'hourly', 'twicedaily')
    is_past_due: boolean
    likely_source: string — best-guess plugin/core attribution
    description: string — plain English description
  }]

requires_approval: false
annotations.readonly: true
```

#### `wp-ai-daemon/reschedule-cron`
**File:** `includes/actions/class-action-reschedule-cron.php`
**WP-CLI equivalent:** `wp cron event run`, `wp cron event delete` + `wp cron event schedule`

Cancels an existing WP-Cron event and reschedules it with a new time or interval. Targeted at WP core and plugin-registered events only — Action Scheduler events should be managed via the existing `schedule_action`/`cancel_scheduled_action` abilities.

```
input_schema:
  hook: string (required) — exact hook name
  new_timestamp: string — ISO 8601, for one-time reschedule
  new_schedule: string — WP cron schedule slug (e.g. 'hourly', 'twicedaily', 'daily')

output_schema:
  hook: string
  old_timestamp: string
  new_timestamp: string
  new_schedule: string|null
  success: boolean

requires_approval: true
annotations.instructions: Never reschedule or delete cron events for Action Scheduler
  (hooks prefixed with 'action_scheduler_' or 'wp_action_scheduler_'). Never delete
  core WP cron events (wp_scheduled_delete, wp_update_themes, etc.) without explicit
  user confirmation that they understand the consequences.
```

---

### Group 7: Options & config

#### `wp-ai-daemon/get-option`
**File:** `includes/actions/class-action-get-option.php`
**WP-CLI equivalent:** `wp option get`

Retrieves one or more WordPress options by key. Includes a plain-language explanation of what each option does and its current value's implications. A safe allowlist prevents leaking sensitive options (auth keys, salts, passwords, tokens).

```
input_schema:
  option_names: string[] (required, max 20)

output_schema:
  options: [{
    name: string
    value: mixed — serialized values decoded to array/object
    description: string — plain English explanation
    is_sensitive: boolean — true if value was redacted
  }]

requires_approval: false
annotations.readonly: true
annotations.instructions: Never return values for options matching these patterns:
  auth_key, secure_auth_key, logged_in_key, nonce_key, *_salt, admin_email,
  sendgrid_*, mailchimp_*, *_api_key, *_secret*, *_password*, *_token.
  Return '[redacted]' for the value and set is_sensitive: true. When summarizing
  an option value, focus on what it means for the site's behavior, not just
  its raw value.
```

#### `wp-ai-daemon/update-option`
**File:** `includes/actions/class-action-update-option.php`
**WP-CLI equivalent:** `wp option update`

Updates a WordPress option value. Blocked for core security options. For all writes, the LLM explains the change and its consequences in plain language before executing.

```
input_schema:
  option_name: string (required)
  value: string|number|boolean|array (required)
  autoload: 'yes'|'no' — optional, if omitted leaves existing autoload setting

output_schema:
  option_name: string
  old_value: mixed
  new_value: mixed
  updated: boolean

requires_approval: true
annotations.instructions: Refuse to update any of these options: auth_key, secure_auth_key,
  logged_in_key, nonce_key, *_salt, admin_email, active_plugins, *_api_key, *_secret*,
  *_password*, *_token. For options that affect site visibility (blog_public,
  blogdescription, siteurl, home), warn the user of the consequences before updating.
  Always show the old value and new value in your message.
```

---

## Registration steps

All new abilities follow the same two-step registration pattern:

### 1. Add to `wp_ai_daemon_action_classes()` in `wp-ai-daemon.php`

```php
function wp_ai_daemon_action_classes(): array {
    return [
        // ... existing actions ...

        // Content operations
        'analyze_post'       => WP_AI_Daemon_Action_Analyze_Post::class,
        'set_post_taxonomy'  => WP_AI_Daemon_Action_Set_Post_Taxonomy::class,
        'write_excerpt'      => WP_AI_Daemon_Action_Write_Excerpt::class,
        'bulk_update_meta'   => WP_AI_Daemon_Action_Bulk_Update_Meta::class,

        // Search & audit
        'find_posts'         => WP_AI_Daemon_Action_Find_Posts::class,
        'audit_content'      => WP_AI_Daemon_Action_Audit_Content::class,

        // User management
        'create_user'        => WP_AI_Daemon_Action_Create_User::class,
        'find_users'         => WP_AI_Daemon_Action_Find_Users::class,

        // Plugin/theme diagnostics
        'list_plugins'       => WP_AI_Daemon_Action_List_Plugins::class,
        'check_plugin_conflicts' => WP_AI_Daemon_Action_Check_Plugin_Conflicts::class,

        // Database
        'db_search_replace'  => WP_AI_Daemon_Action_Db_Search_Replace::class,

        // Cron
        'list_cron_events'   => WP_AI_Daemon_Action_List_Cron_Events::class,
        'reschedule_cron'    => WP_AI_Daemon_Action_Reschedule_Cron::class,

        // Options
        'get_option'         => WP_AI_Daemon_Action_Get_Option::class,
        'update_option'      => WP_AI_Daemon_Action_Update_Option::class,
    ];
}
```

The existing loop in `wp-ai-daemon.php` that iterates `wp_ai_daemon_action_classes()` and registers each via `wp_register_ability()` handles the rest automatically — no other bootstrap changes needed.

### 2. Add `require_once` in plugin bootstrap

In `wp-ai-daemon.php`, add `require_once` calls alongside the existing action includes, grouped by category.

---

## Approval matrix

| Ability | `requires_approval` | Rationale |
|---|---|---|
| `analyze-post` | false | Read-only |
| `set-post-taxonomy` | true | Modifies post data |
| `write-excerpt` | true | Modifies post data |
| `bulk-update-meta` | true | Writes to multiple posts; dry-run param gates execution |
| `find-posts` | false | Read-only query |
| `audit-content` | false | Read-only scan |
| `create-user` | true | Creates an account |
| `find-users` | false | Read-only query |
| `list-plugins` | false | Read-only inventory |
| `check-plugin-conflicts` | false | Read-only scan |
| `db-search-replace` | true | Bulk DB writes; dry-run param gates execution |
| `list-cron-events` | false | Read-only |
| `reschedule-cron` | true | Modifies scheduled jobs |
| `get-option` | false | Read-only |
| `update-option` | true | Modifies site configuration |

---

## Dry-run pattern

`bulk-update-meta` and `db-search-replace` implement a two-phase pattern to avoid needing the full approval queue UI for dangerous bulk operations:

1. **Phase 1** (`confirmed: false`): Execute returns a preview of changes without writing anything. The LLM presents this to the user in its `message` field.
2. **Phase 2** (`confirmed: true`): The LLM calls again with `confirmed: true` only after the user explicitly confirms. This is still gated by `requires_approval: true` in the queue for autonomous mode, but in chat mode the two-phase pattern provides the safety check inline.

The `annotations.instructions` field for these abilities explicitly instructs the LLM to always do phase 1 first.

---

## Implementation order

1. **Read-only abilities first** — lower risk, easier to test, provide foundation for write abilities:
   - `analyze-post`, `find-posts`, `find-users`, `list-plugins`, `list-cron-events`, `get-option`

2. **Scoped write abilities** — single-record writes with existing WP primitives:
   - `set-post-taxonomy`, `write-excerpt`, `create-user`, `reschedule-cron`, `update-option`

3. **Audit abilities** — more complex scan logic:
   - `audit-content`, `check-plugin-conflicts`

4. **Bulk/dangerous abilities** — implement last after dry-run pattern is battle-tested:
   - `bulk-update-meta`, `db-search-replace`

---

## Security notes

- **`db-search-replace`:** Use `$wpdb->prepare()` for all queries. Never allow replacement of auth keys, salts, or `siteurl`/`home` without explicit user confirmation. Always handle serialized data — replacing raw URLs in serialized strings corrupts data.
- **`get-option` / `update-option`:** Maintain a hard-coded denylist of sensitive option name patterns (keys, salts, tokens, passwords). This list lives in the action class, not the system prompt, so it cannot be overridden by prompt injection.
- **`create-user`:** Use `wp_generate_password( 24, true, true )`. Never accept or log a plaintext password param.
- **`find-users`:** Never return password hashes, session tokens, or `user_pass` field.
- **`audit-content`:** Read-only; uses only `WP_Query` and `get_post_meta()`. No eval, no exec.
- **`check-plugin-conflicts`:** Reads debug.log if present — scope to `WP_CONTENT_DIR . '/debug.log'` only via `WP_Filesystem`, never arbitrary paths.
- All abilities use `manage_options` permission callback (inherited from existing pattern).
