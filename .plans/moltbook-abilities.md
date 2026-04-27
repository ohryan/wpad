# Moltbook Abilities — Implementation Plan

> Moltbook is a social network built exclusively for AI agents (launched Jan 2026, acquired by Meta Mar 2026). Agents post, comment, upvote/downvote, and follow topic-based "submolts." They authenticate via their owner's "claim" tweet and are expected to check in ~every 30 minutes.

---

## Open Questions (resolve before implementing)

These are blockers. Don't write any code until you have answers.

1. **Does Moltbook have a public REST API?** The claim-tweet auth mechanism suggests some kind of bearer token exchange, but the API surface is unknown. Is it REST+JSON? GraphQL? Is there API documentation or an SDK?

2. **What does the claim tweet contain exactly?** A signed token? A verification URL? Does the daemon need to post a tweet itself (implying a Twitter/X integration dependency), or is it a one-time owner action that produces a stored credential?

3. **What does "check in every ~30 minutes" mean at the API level?** Is there an explicit heartbeat endpoint, or is posting/reading activity sufficient to signal presence?

4. **Rate limits.** What are Moltbook's API rate limits? Per-minute? Per-hour? What happens on violation — 429s, temporary bans, permanent bans?

5. **Submolt discovery.** How does an agent find submolts? Is there a search/browse API, or does the agent need to know submolt names/IDs in advance?

6. **Idempotency.** Can we detect duplicate posts/comments? Does the API return stable IDs for posts/comments that we can store to avoid reposting?

---

## 1. Abilities Needed

Minimum set for a coherent autonomous presence. Each maps to one class under `includes/abilities/moltbook-*/`.

| Ability Name | Full Key | Readonly | Destructive | Notes |
|---|---|---|---|---|
| Authenticate | `moltbook/authenticate` | false | false | One-time or refresh; stores token |
| Get Feed | `moltbook/get-feed` | true | false | Reads agent's home feed |
| Get Submolt | `moltbook/get-submolt` | true | false | Reads posts in a named submolt |
| Create Post | `moltbook/create-post` | false | true | Posts to a submolt |
| Create Comment | `moltbook/create-comment` | false | true | Replies to a post |
| Vote | `moltbook/vote` | false | false | Upvote or downvote a post/comment |
| Search Submolts | `moltbook/search-submolts` | true | false | Finds submolts by topic/keyword |
| Get Notifications | `moltbook/get-notifications` | true | false | Replies, mentions, vote activity |

**Not in v1:**
- Follow/unfollow submolts (can be configured manually in Moltbook settings)
- Delete post/comment (out of scope per plugin's general policy)
- Direct messaging (unnecessary for a public presence)

---

## 2. Authentication Approach

### Credential storage

Credentials are stored in WordPress options, not in any ability class. Use a dedicated options group:

| Option key | Contents |
|---|---|
| `wpad_moltbook_api_token` | Bearer token obtained after claim verification |
| `wpad_moltbook_agent_id` | Agent's Moltbook user ID (returned on first auth) |
| `wpad_moltbook_token_expires` | Unix timestamp; 0 = no expiry |
| `wpad_moltbook_claim_verified` | bool; false until claim flow completes |

All options stored via `update_option()`. The token is sensitive — do not log it, do not include it in `WP_AI_Daemon_Logger` output, do not return it in any REST response.

### Claim flow

The claim mechanism ties a Moltbook agent identity to a WordPress site owner. The expected flow (pending API doc confirmation):

1. Site admin initiates claim in **WP AI Daemon > Settings > Moltbook** (a new settings subsection — not a separate admin page).
2. Plugin requests a challenge from the Moltbook API using the site URL and admin email.
3. Moltbook returns a claim token the owner must post publicly (as a tweet or Moltbook post — TBD based on their mechanism).
4. Admin posts the claim tweet/post, then clicks "Verify" in WP admin.
5. Plugin calls Moltbook's verification endpoint. On success, Moltbook returns a bearer token.
6. Token stored in `wpad_moltbook_api_token`. `wpad_moltbook_claim_verified` set to `true`.

This is a one-time flow, not per-request. The `moltbook/authenticate` ability handles token refresh only — it is not used to initiate the claim flow. The claim UI lives in the settings page.

### How abilities use auth

Each Moltbook ability class calls a shared static helper `WP_AI_Daemon_Moltbook_Client::get_token()`, which:

- Reads `wpad_moltbook_api_token`
- Checks `wpad_moltbook_token_expires` and refreshes if needed (via `moltbook/authenticate`)
- Returns the token string or `WP_Error( 'not_authenticated', ... )`

All HTTP requests to the Moltbook API go through `WP_AI_Daemon_Moltbook_Client`, not directly from ability classes. This centralises the `Authorization: Bearer {token}` header, base URL, and error handling in one place. The client uses `wp_remote_post()` / `wp_remote_get()` — no raw cURL.

### Where the client class lives

`includes/class-moltbook-client.php` — a simple HTTP wrapper, not an ability. Abilities `require_once` or receive it via constructor. Static methods are fine since it's stateless except for the option reads.

### `check_permissions()` for Moltbook abilities

All Moltbook abilities override `check_permissions()` to return `false` (and thus be excluded from the live ability list) when `wpad_moltbook_claim_verified` is `false`. This prevents the LLM from trying to use Moltbook abilities before auth is set up, and cleanly removes them from the dynamic system prompt via `build_available_abilities()` in `class-chat-service.php`.

---

## 3. Autonomous Loop Design

### Triggering

Moltbook participation runs on its own Action Scheduler recurring action: `wp_ai_daemon_moltbook_run`. This is separate from `wp_ai_daemon_autonomous_run` (the general autonomous mode) because:

- It has a tighter cadence (30-minute target vs. hourly/daily general mode)
- It needs its own enable/disable toggle — a site owner might want general autonomous mode on but Moltbook off, or vice versa
- Its context (feed, notifications) is Moltbook-specific and doesn't belong in general autonomous context

Frequency: configurable (30 min, 1 hour, 2 hours, daily). Default: **disabled**. The 30-minute cadence is Moltbook's _expectation_ for active agents; it should be opt-in.

### What happens each run

1. Check `wpad_moltbook_enabled` — skip if false.
2. Check `wpad_moltbook_claim_verified` — skip and log error if false.
3. Check rate limit guard (see §5) — skip if too many actions in recent window.
4. Fetch notifications (`moltbook/get-notifications`) — direct HTTP, not queued, result used as context.
5. Fetch feed (`moltbook/get-feed`) — same, direct.
6. Call LLM with: current date/time, notifications, feed summary, standing Moltbook instructions (from `moltbook-instructions.md` — a separate file from `autonomous-instructions.md`).
7. LLM returns action objects (posts, comments, votes) — these are queued, not executed inline.
8. Queue runner processes them subject to approval settings (see §4).

### Moltbook-specific standing instructions file

`moltbook-instructions.md` — editable by the admin in **WP AI Daemon > Moltbook**, analogous to `autonomous-instructions.md`. Default content is a commented template explaining: which submolts to participate in, tone/persona guidance, what topics to post about, frequency hints.

### LLM context block

The context passed to the LLM each Moltbook run should include:

```
Current UTC time: ...
Agent Moltbook ID: ...
Unread notifications: N
Recent feed (last 10 posts):
  [post_id] submolt/name — "Post title" by agent_id — N upvotes, M comments
  ...
Recent actions taken by this agent (last 5):
  [timestamp] create_post in submolt/foo — "Title"
  [timestamp] create_comment on post_id 123
  ...
```

The "recent actions taken" block is critical for loop prevention — see §5.

---

## 4. Approval Queue Integration

| Action | Requires approval | Rationale |
|---|---|---|
| `moltbook/get-feed` | No | Read-only |
| `moltbook/get-submolt` | No | Read-only |
| `moltbook/get-notifications` | No | Read-only |
| `moltbook/search-submolts` | No | Read-only |
| `moltbook/authenticate` | No | Credential refresh only |
| `moltbook/vote` | No | Low-stakes, reversible in spirit, Moltbook's cadence expects it |
| `moltbook/create-comment` | **Configurable** | Default: requires approval. Can be set to auto in Moltbook settings. |
| `moltbook/create-post` | **Yes, always** | Publishing in the agent's name — high-stakes, should always have a human look |

The `requires_approval` flag on queue items is set by the ability class (via a static `::requires_approval()` method or a constant). The queue runner checks this flag before executing — if true, the item sits in `pending` status and appears in **WP AI Daemon > Review** rather than auto-executing.

For Moltbook posts, the Review screen should show:
- Target submolt
- Post title + body
- Approve button → executes `moltbook/create-post` synchronously
- Discard button → deletes queue item

Comments have an "auto-approve" toggle in Moltbook settings (`wpad_moltbook_auto_approve_comments`, default `false`). When true, comments skip the approval queue.

---

## 5. Rate Limiting and Safety

### Hard limits in the queue runner

Before executing any Moltbook create/comment/vote action, `class-queue-runner.php` (or a `WP_AI_Daemon_Moltbook_Rate_Limiter` helper) checks:

| Window | Max actions |
|---|---|
| 30 minutes | 3 posts + comments combined |
| 24 hours | 20 posts + comments combined |
| 24 hours | 50 votes |

These are enforced by querying `wpad_queue` for recent completed Moltbook actions. If a limit is exceeded, the queue item is failed with a `rate_limit` error (not retried). Log the event via `WP_AI_Daemon_Logger::error()`.

These limits are conservative and configurable via options (`wpad_moltbook_rate_*`). The intent is: if the daemon goes rogue (bad LLM output, looping instructions), the damage is bounded before a human notices.

### Deduplication

Before creating a post or comment, compute a hash of the content (title + body for posts, body + parent_id for comments) and check a simple transient: `wpad_moltbook_hash_{hash}` with a 24-hour TTL. If the transient exists, skip the action and log a deduplication warning. This prevents identical content from being submitted twice even if the queue runner runs twice on the same item.

### Loop prevention in the LLM prompt

The `moltbook-instructions.md` default template and the `prompt.md` for `moltbook/create-post` and `moltbook/create-comment` must both include explicit constraints:

- Do not reply to the same post more than once.
- Do not create posts on the same topic you posted about in the last 24 hours.
- If you have no genuinely new contribution to make, return no actions (empty `actions_json`).
- Check the "recent actions" context block before deciding to act.

The "recent actions" block injected into each Moltbook run context (see §3) is the primary loop-prevention signal for the LLM. The rate limiter is the fallback for when the LLM ignores it.

### Autonomous mode gate

`wpad_moltbook_enabled` defaults to `false`. The Moltbook run is never scheduled unless the admin explicitly enables it and completes the claim flow. This mirrors the general `wpad_autonomous_enabled` pattern from `class-autonomous-service.php`.

---

## 6. New Files

```
includes/
  class-moltbook-client.php               # HTTP wrapper; token management
  class-moltbook-service.php              # Autonomous Moltbook run loop (mirrors class-autonomous-service.php)
  class-moltbook-rate-limiter.php         # Checks/records action counts within time windows
  abilities/
    moltbook-authenticate/
      class-moltbook-authenticate.php
      prompt.md
    moltbook-get-feed/
      class-moltbook-get-feed.php
      prompt.md
    moltbook-get-submolt/
      class-moltbook-get-submolt.php
      prompt.md
    moltbook-create-post/
      class-moltbook-create-post.php
      prompt.md
    moltbook-create-comment/
      class-moltbook-create-comment.php
      prompt.md
    moltbook-vote/
      class-moltbook-vote.php
      prompt.md
    moltbook-search-submolts/
      class-moltbook-search-submolts.php
      prompt.md
    moltbook-get-notifications/
      class-moltbook-get-notifications.php
      prompt.md
moltbook-instructions.md                  # Standing Moltbook instructions (user-editable)
```

New admin section: **WP AI Daemon > Moltbook** (a new submenu page, `class-moltbook-settings-page.php`). Contains:
- Claim flow UI (challenge request → verify button)
- Enable/disable toggle
- Cadence selector
- Auto-approve comments toggle
- Rate limit settings
- Editable textarea bound to `moltbook-instructions.md`
- Last run info (mirrors Autonomous page)

---

## 7. Integration with Existing Architecture

**Ability registration:** Moltbook abilities register via the same `wp_abilities_api_init` hook and `wp_ai_daemon_action_classes()` map as existing abilities. The `check_permissions()` gate (claim_verified check) means they appear in the dynamic system prompt only when auth is complete — no changes needed to `build_available_abilities()` in `class-chat-service.php`.

**Queue runner:** No changes needed. `class-queue-runner.php` resolves `moltbook/create-post` etc. via `wp_get_ability()` exactly like any other ability. The `requires_approval` logic needs to be added to the queue runner as a general pattern (it currently doesn't exist per the plan — this is a new addition required by Moltbook but useful for the general case).

**Action chaining:** The fetch-then-act pattern (get notifications → decide whether to comment) is a natural fit for the existing chain mechanism in `class-queue-runner.php`. The `moltbook/get-notifications` and `moltbook/get-feed` reads can be the first chain step; `moltbook/create-comment` the conditional second step. This reuses `chain_id` / `chain_history` exactly as designed in `autonomous-and-scheduling.md`.

**Review page:** `class-approval-queue-page.php` needs a new item renderer for Moltbook posts/comments (alongside the existing plugin and draft-post renderers). The data structure is a queue item where `action` is `moltbook/create-post` or `moltbook/create-comment`.

**Logging:** All Moltbook actions log via `WP_AI_Daemon_Logger` as normal. The logger context array should include `moltbook_post_id`, `submolt`, etc. for traceability. Never log the bearer token.

---

## 8. Build Order

1. Resolve open questions (API docs, claim mechanism) — no code until this is done.
2. `class-moltbook-client.php` + settings page with claim flow UI.
3. Read-only abilities (`get-feed`, `get-submolt`, `get-notifications`, `search-submolts`) — these are safe to test without approval queue changes.
4. Add `requires_approval` support to `class-queue-runner.php` (needed before write abilities).
5. Write abilities (`create-post`, `create-comment`, `vote`) with rate limiter.
6. `class-moltbook-service.php` autonomous loop + Action Scheduler wiring.
7. Review page additions for Moltbook queue items.
8. `moltbook-instructions.md` default template.
