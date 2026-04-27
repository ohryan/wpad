# WP AI Daemon v1 — Claude Code Spec

> **Implementation plans** are in `.plans/`. When a section of this spec references detailed implementation decisions, look there first before designing a new approach. Plans are the authoritative source for how specific subsystems should be built.

## What is this

WP AI Daemon is a WordPress plugin that turns a WordPress install into a personal AI agent. It lives inside WP admin alongside a normal site — it doesn't replace or fight the CMS.

It has two distinct value propositions:

**1. AI that knows your WordPress site**
A chat interface where you talk to an agent that can read, write, and act on your content. Smarter than a generic AI because it has context about your specific site. Actions execute synchronously — you're there, you see the result immediately.

**2. AI that works while you're away**
Standing instructions run on a schedule. The agent decides what to do, executes actions, and routes results to a review inbox for human approval. This is the "always on server" value — it only makes sense as a web appliance.

**Build order: #1 first, #2 second.** The chat interface and its action execution layer must work well before autonomous mode is layered on top. Autonomous mode reuses the same action classes — it just runs them outside a request context and routes results differently.

**Capabilities (both modes):**
- Fetch content from the web (via WP HTTP API) — an enabler for other features, not a feature itself
- Draft and modify posts and pages (never delete, never publish directly)
- Write plugins to disk (never auto-activate — human reviews first)
- Schedule future or recurring actions via Action Scheduler (autonomous mode only)

---

## What we're building on

WP AI Daemon absorbs and extends **Plugin! Plugin! Plugin!** (`ohryan/plugin-plugin-plugin`). Do not rewrite what already works there. Specifically, carry forward:

- The chat UI pattern (builder.js / builder.css)
- The conversation persistence model (custom post type via `class-conversation-store.php`)
- The REST API structure (`class-rest-controller.php`)
- The plugin generation logic (`class-code-generator.php`)
- The plugin installer (`class-plugin-installer.php`) — **but change**: do not auto-activate. Write to disk and push to approval queue instead.
- The `system-prompt.md` pattern for editable system prompts

The key additions WP AI Daemon makes are: synchronous action execution in chat, the expanded capability set (web fetch, content creation), the autonomous/scheduled mode, and the review inbox UI. Build in that order.

---

## Requirements

- WordPress 7.0+
- PHP 8.1+
- AI provider configured under **Settings → Connectors** (Anthropic, Google, or OpenAI — provider-agnostic via WP AI Client)
- Action Scheduler (via Composer) for reliable queue execution
- Node.js + Docker for local dev via `wp-env`

---

## Architecture

### Mode 1: Chat (synchronous)

The LLM responds to a user message and returns structured action objects alongside its text response. The chat service executes these actions immediately within the request, injects results back into the conversation, and displays outcomes inline in the UI. No queue involved.

Actions in chat mode:
- `web_fetch` — executes immediately, result injected into next LLM turn
- `create_post`, `update_post`, `create_page`, `update_page` — creates as draft, surfaces in chat with a link to review in WP editor
- `write_plugin` — writes to disk, surfaces in chat with a link to the Review screen

### Mode 2: Autonomous (async via Action Scheduler)

Standing instructions run on a schedule. The autonomous service sends context + instructions to the LLM, which returns action objects. These are written to a queue. A recurring Action Scheduler job processes the queue and routes results to the review inbox.

The queue is only needed here — because there is no active user session to return results to.

**Queue item structure:**

```json
{
  "action": "web_fetch" | "create_post" | "update_post" | "create_page" | "update_page" | "write_plugin" | "schedule_action",
  "params": {},
  "requires_approval": true | false,
  "triggered_by": "autonomous",
  "created_at": "...",
  "chain_id": "uuid",
  "chain_history": []
}
```

`requires_approval` is determined by action type:
- `web_fetch` → false (executes immediately)
- `create_post`, `update_post`, `create_page`, `update_page` → true (saved as draft, flagged in review inbox)
- `write_plugin` → true (written to disk, flagged in review inbox, not activated)

**Action chaining**

The queue supports conditional multi-step chains via `chain_id` and `chain_history`. After executing an action that has a `chain_id`, the queue runner calls the LLM with the accumulated results and asks "what next?" The LLM either issues another action or signals `done`. This lets the LLM make conditional decisions based on real data — e.g. fetch a URL, examine the response, and only draft a post if something changed. See `.plans/autonomous-and-scheduling.md` for full implementation details.

### The LLM's capabilities

The LLM is told it can issue the following actions via structured JSON in its response. The system prompt defines these explicitly.

Each action is implemented as its own class under `includes/actions/`, implementing a shared `WP_AI_Daemon_Action` interface with an `execute()` method.

Action classes register themselves via the `wp_ai_daemon_actions` filter hook rather than a central registry class. This means any plugin can register new action types using the same WordPress primitive developers already know:

```php
add_filter( 'wp_ai_daemon_actions', function( $actions ) {
    $actions['web_fetch'] = WP_AI_Daemon_Action_Web_Fetch::class;
    return $actions;
});
```

`class-queue-runner.php` resolves available actions at runtime via `apply_filters( 'wp_ai_daemon_actions', [] )`. This is the primary extension point — a generated plugin that adds a new action type hooks into `wp_ai_daemon_actions` exactly like a built-in action does. The LLM writes the plugin, a human activates it, and WP AI Daemon can now do things it couldn't before.


**web_fetch**
```json
{
  "action": "web_fetch",
  "params": {
    "url": "https://...",
    "store_as": "variable_name"
  }
}
```
Uses `wp_remote_get()`. Response stored temporarily and injected into the next LLM turn in the same job sequence.

**create_post / update_post**
```json
{
  "action": "create_post",
  "params": {
    "title": "...",
    "content": "...",
    "status": "draft",
    "tags": [],
    "categories": []
  }
}
```
Always creates as draft. Never publishes directly. Pushes to approval queue.

**create_page / update_page**
Same pattern as posts.

**write_plugin**
```json
{
  "action": "write_plugin",
  "params": {
    "slug": "my-plugin-name",
    "filename": "my-plugin-name.php",
    "code": "<?php\n..."
  }
}
```
Written to `wp-content/plugins/` via WP_Filesystem. Not activated. Pushes to approval queue. Reuses `class-plugin-installer.php` from Plugin! Plugin! Plugin! with activation step removed.

**schedule_action**
```json
{
  "action": "schedule_action",
  "params": {
    "action": "web_fetch",
    "params": {},
    "schedule": "once" | "recurring",
    "interval_seconds": 3600,
    "first_run": "2026-04-10T09:00:00Z"
  }
}
```
Schedules another WP AI Daemon action via Action Scheduler — either a one-time action (`as_schedule_single_action()`) or a recurring one (`as_schedule_recurring_action()`). The nested `action` must be a valid WP AI Daemon action type. This is what allows the LLM to set up its own ongoing work beyond the current conversation.

### The queue runner

Action Scheduler is the execution backbone — included via Composer, not WP-Cron directly. Action Scheduler provides reliable, logged, retryable async job execution on top of WP-Cron's scheduling primitives, without the reliability problems of raw WP-Cron or transients.

Registered as a recurring Action Scheduler action: `wp_ai_daemon_process_queue`

Scheduled on plugin activation via `as_schedule_recurring_action()` with a 2-minute interval. Pops one item at a time (to avoid timeouts). For items that don't require approval, executes immediately. For items that require approval, writes result and pushes to approval queue table. Logs all executions via Action Scheduler's built-in logging plus WP AI Daemon's own `class-logger.php`.

After executing a chained item (`chain_id` present), calls the LLM with accumulated results to get the next step or a `done` signal. Enforces a configurable max-steps limit per chain.

### Autonomous mode

A separate recurring Action Scheduler action: `wp_ai_daemon_autonomous_run`

Frequency: user-configurable (hourly, twice daily, daily). On each run:

1. Loads the standing instructions from `autonomous-instructions.md`
2. Loads recent context (last N log entries, current date/time, any pending approval items)
3. Sends to LLM with system prompt
4. Parses response for action objects
5. Assigns a shared `chain_id` to all actions returned in this run
6. Writes action objects to queue — the queue runner handles chaining from there

---

## File structure

```
wp-ai-daemon.php                      # Plugin entry point & bootstrap
system-prompt.md                      # Core system prompt — editable
composer.json                         # Action Scheduler dependency (Phase 2)
includes/
  # --- Phase 1: Chat mode ---
  class-admin-page.php                # WP AI Daemon > Chat UI
  class-approval-queue-page.php       # WP AI Daemon > Review (plugins + drafted content)
  class-rest-controller.php           # REST API endpoints
  class-chat-service.php              # Chat mode: sends to LLM, executes actions synchronously
  class-conversation-store.php        # Conversation persistence (from Plugin! Plugin! Plugin!)
  class-plugin-installer.php          # Plugin write-to-disk, no auto-activate
  class-logger.php                    # Execution log

  # --- Phase 2: Autonomous mode ---
  class-dashboard-page.php            # WP AI Daemon > Dashboard (log + status)
  class-autonomous-settings-page.php  # WP AI Daemon > Autonomous (schedule + instructions)
  class-autonomous-service.php        # Autonomous mode: loads instructions, calls LLM, writes to queue
  class-action-queue.php              # Queue read/write (autonomous mode only)
  class-queue-runner.php              # Recurring Action Scheduler handler — resolves action classes via wp_ai_daemon_actions filter

  actions/
    class-action-interface.php        # WP_AI_Daemon_Action interface (execute(), get_schema())
    # Phase 1 actions
    class-action-web-fetch.php        # web_fetch
    class-action-create-post.php      # create_post
    class-action-update-post.php      # update_post
    class-action-create-page.php      # create_page
    class-action-update-page.php      # update_page
    class-action-write-plugin.php     # write_plugin (via class-plugin-installer.php)
    # Phase 2 actions
    class-action-schedule-action.php  # schedule_action (autonomous mode only)
assets/
  chat.js                             # Chat UI
  chat.css
  approval.js                         # Review queue UI
  approval.css
  # Phase 2
  dashboard.js                        # Dashboard / log UI
  dashboard.css
  autonomous-instructions.md          # Standing instructions — editable by user
```

---

## Admin UI

WP AI Daemon registers a top-level menu item in WP admin: **WP AI Daemon**. Sub-pages:

### WP AI Daemon > Chat
The conversational interface. User types a message, agent responds, actions are queued and executed. Shows a log of actions taken inline with the conversation. Reuses chat UI patterns from Plugin! Plugin! Plugin!.

### WP AI Daemon > Dashboard
Status at a glance:
- Queue depth (items pending)
- Last autonomous run (timestamp + summary)
- Recent action log (last 20 items, filterable by type)
- Any errors

### WP AI Daemon > Review
The approval queue. Lists items requiring human action:
- Generated plugins (with code preview, Activate button)
- Drafted posts/pages (with preview link, Publish button)

For plugins: show the generated code in a read-only (but copyable) code block. Activate button calls WP plugin activation. Delete button removes the file.

For posts/pages: link to WP editor for review. Publish button sets status to published.

### WP AI Daemon > Autonomous
- Toggle: enable/disable autonomous mode
- Schedule selector: hourly / twice daily / daily
- Editable textarea (or CodeMirror) bound to `autonomous-instructions.md`
- Last run info

### WP AI Daemon > Settings (submenu)
- Link to WP Settings → Connectors (don't duplicate, just link)
- Log retention (days)
- Max queue items per run
- Max chain steps (default: 10) — hard limit on how many LLM-driven steps a single chain can take

---

## System prompt design

`system-prompt.md` defines the agent's behaviour, capabilities, and output format. It must clearly specify:

1. The agent's role and constraints (create but not delete, draft but not publish, write but not activate)
2. The exact JSON format for action objects
3. That multiple actions can be returned in a single response as an array
4. That web_fetch results will be injected back into the conversation before the next action is decided
5. Security expectations for generated plugin code (nonces, capability checks, sanitization, escaping)

`autonomous-instructions.md` is the user-editable standing instructions file. It is injected into the autonomous mode prompt as the user's goals/context. Default content should be a commented template explaining what to put there.

---

## REST API

| Method | Endpoint | Description |
| --- | --- | --- |
| POST | `/wp-ai-daemon/v1/chat` | Send message, get response, queue actions |
| GET | `/wp-ai-daemon/v1/conversations` | List conversations |
| GET | `/wp-ai-daemon/v1/conversations/{id}` | Get conversation |
| DELETE | `/wp-ai-daemon/v1/conversations/{id}` | Delete conversation |
| GET | `/wp-ai-daemon/v1/queue` | List pending queue items |
| POST | `/wp-ai-daemon/v1/queue/{id}/approve` | Approve a queue item |
| DELETE | `/wp-ai-daemon/v1/queue/{id}` | Discard a queue item |
| GET | `/wp-ai-daemon/v1/log` | Get execution log |
| GET | `/wp-ai-daemon/v1/status` | Agent status (for dashboard) |

All endpoints require `manage_options`.

---

## Local development

```
npm install
npm run env:start
```

WordPress available at `http://localhost:8888`. Admin: `admin` / `password`.

```
npm run env:stop
npm run env:clean
```

---

## Security notes

- All REST endpoints require `manage_options` capability
- Plugin files are written via `WP_Filesystem`, never raw file_put_contents
- Nothing auto-activates or auto-publishes — human approval required for all consequential actions
- Web fetches use `wp_remote_get()` with default WP timeout/redirect limits
- Generated plugin code is instructed to follow WP security best practices but should be reviewed before activation
- Queue items are stored in a custom DB table, not transients (transients are not reliable for this)
- Autonomous mode should be disabled by default

---

## What's explicitly out of scope for v1

- Deleting posts, pages, or plugins
- Auto-publishing content
- Auto-activating plugins
- Arbitrary PHP execution (the LLM writes plugins, not raw exec calls)
- Multi-user / per-user agents (single admin user only)
- Front-end UI (WP admin only)
- Email/notification on queue items (v2)
