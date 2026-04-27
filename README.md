# WP AI Daemon

> **Experimental proof of concept. Do not install on a live, production, or otherwise important WordPress site.** This plugin gives an AI agent the ability to write files to disk, create and modify content, and execute scheduled actions on your WordPress install. It has not been audited for security or stability. Run it only in a local or disposable dev environment.

A WordPress plugin that turns a WordPress install into a personal AI agent. Talk to an agent that knows your site, or set standing instructions and let it work while you're away.

## What it does

**Chat mode** — Conversational interface in WP admin. The agent can fetch pages from the web, draft posts and pages, write plugins, schedule future actions, and run one-off PHP snippets. Actions execute synchronously — you see the result immediately in the chat.

**Scheduled mode** — The agent runs on a schedule against standing instructions you write. Results (drafted content, generated plugins) are routed to a review inbox for human approval before anything goes live.

Nothing auto-publishes or auto-activates. Every consequential action requires human review.

## Abilities

| Ability | What it does |
|---|---|
| `web_fetch` | Fetches a URL via `wp_remote_get()` and returns the content |
| `create_post` / `update_post` | Creates or updates a post as a draft |
| `create_page` / `update_page` | Creates or updates a page as a draft |
| `write_plugin` | Writes a plugin to `wp-content/plugins/` — not activated |
| `run_snippet` | Executes a one-off PHP snippet and returns the output |
| `schedule_action` | Schedules any other ability to run once or on a recurring interval |
| `cancel_scheduled_action` | Cancels a previously scheduled action |
| `list_scheduled_actions` | Lists pending scheduled actions |

Third-party plugins can register new abilities by hooking into the WordPress Abilities API under the `wp-ai-daemon` category.

## Requirements

- WordPress 7.0+
- PHP 8.1+
- AI provider configured under **Settings → Connectors** (Anthropic, Google, or OpenAI via WP AI Client)

## Installation

1. Clone or download this repo into `wp-content/plugins/wp-ai-daemon/`
2. Run `composer install` in the plugin directory
3. Activate the plugin in WP admin
4. Configure your AI provider under **Settings → Connectors**

## Admin UI

**WP AI Daemon > Chat** — The main chat interface.

**WP AI Daemon > Settings** — Enable/disable individual abilities, manage conversations, view execution logs, and link to the AI provider configuration.

## Security

- All REST endpoints require `manage_options`
- Plugin files are written via `WP_Filesystem`, never `file_put_contents`
- Nothing auto-activates or auto-publishes — human approval required for all consequential actions
- Web fetches use `wp_remote_get()` with WP's default timeout and redirect limits
- Generated plugin code should be reviewed before activation

## Architecture notes

The system prompt in `system-prompt.md` has an `{abilities}` placeholder that is replaced at runtime with the live ability registry. This means abilities registered by third-party plugins are automatically described to the LLM without any manual prompt editing.

Scheduled actions are backed by [Action Scheduler](https://actionscheduler.org) (included via Composer) — reliable, logged, and retryable, unlike raw WP-Cron.

## License

GPL-2.0-or-later
