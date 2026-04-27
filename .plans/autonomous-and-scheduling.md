# Autonomous Mode & Scheduling — Implementation Plan

---

## Overview

Autonomous mode lets the agent run on a schedule without a user present. It reads standing instructions from a file, calls the LLM with context, and queues the resulting actions. A separate queue runner (Action Scheduler) processes queued items every 2 minutes.

The queue supports **action chaining**: after executing an action, the queue runner can call the LLM again with the result and ask "what next?" This lets the LLM make conditional decisions at runtime — e.g. fetch a URL, examine the response, then decide whether to draft a post.

---

## Files to create

```
includes/class-autonomous-service.php       # Core autonomous run loop
includes/class-autonomous-settings-page.php # Admin UI: schedule + instructions editor
includes/class-scheduled-actions.php        # Query/cancel AS actions; format context for LLM
includes/class-queue-runner.php             # Action Scheduler callback; pops queue, executes, continues chains
includes/class-action-queue.php             # DB-backed queue (wpad_queue table)
includes/class-dashboard-page.php           # Dashboard: queue depth, last run, recent log
includes/abilities/schedule-action/
  class-schedule-action.php
  prompt.md
includes/abilities/cancel-scheduled-action/
  class-cancel-scheduled-action.php
  prompt.md
assets/autonomous.js                        # Save-instructions UI
assets/dashboard.js
assets/dashboard.css
autonomous-instructions.md                  # Standing instructions (user-editable)
```

---

## DB schema — `wpad_queue`

```sql
CREATE TABLE {prefix}wpad_queue (
    id              bigint(20)   NOT NULL AUTO_INCREMENT,
    action          varchar(50)  NOT NULL,
    params          longtext     NOT NULL,
    status          varchar(20)  NOT NULL DEFAULT 'pending',
    triggered_by    varchar(20)  NOT NULL DEFAULT 'conversation',
    conversation_id bigint(20)   NULL,
    created_at      datetime     NOT NULL,
    updated_at      datetime     NOT NULL,
    result          longtext     NULL,
    error           text         NULL,
    chain_id        varchar(36)  NULL,
    chain_history   longtext     NULL,
    PRIMARY KEY  (id),
    KEY status (status),
    KEY triggered_by (triggered_by),
    KEY conversation_id (conversation_id),
    KEY chain_id (chain_id)
);
```

Statuses: `pending` → `executing` → `completed` | `failed`

`chain_id` — UUID shared across all steps in one autonomous run. NULL for items that don't participate in a chain.

`chain_history` — JSON array of `{ action, params, result }` objects accumulated so far. Passed to the LLM on each continuation call so it can make conditional decisions based on real results.

---

## Action chaining

After executing an action that has a `chain_id`, the queue runner calls the LLM with the accumulated `chain_history` and asks "what next?" The LLM either issues another action object or returns a `done` signal.

**Flow:**
1. Autonomous run starts → LLM issues first action(s), all assigned the same `chain_id`
2. Queue runner executes the action, captures result
3. Result appended to `chain_history`
4. Queue runner calls LLM: "here is the chain so far, what is your next action?"
5. LLM issues next action → pushed to queue with same `chain_id` + updated `chain_history`
   — or returns `done` → chain ends
6. Each step is logged individually; the chain appears as one logical run in the Dashboard

**Max steps:** Option `wpad_max_chain_steps` (default 10) — the queue runner checks step count (length of `chain_history`) before continuing a chain.

**Compared to the plugin approach:** The LLM can also write a PHP plugin with conditional logic baked in (fetch → check → act). That's still valid for high-frequency deterministic checks with no LLM cost per run. Chaining is better when the condition requires semantic judgment.

---

## class-action-queue.php

```php
<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class WP_AI_Daemon_Action_Queue {

    const TABLE = 'wpad_queue';

    public static function create_tables(): void {
        global $wpdb;
        $table   = $wpdb->prefix . self::TABLE;
        $charset = $wpdb->get_charset_collate();
        $sql = "CREATE TABLE {$table} (
            id              bigint(20)   NOT NULL AUTO_INCREMENT,
            action          varchar(50)  NOT NULL,
            params          longtext     NOT NULL,
            status          varchar(20)  NOT NULL DEFAULT 'pending',
            triggered_by    varchar(20)  NOT NULL DEFAULT 'conversation',
            conversation_id bigint(20)   NULL,
            created_at      datetime     NOT NULL,
            updated_at      datetime     NOT NULL,
            result          longtext     NULL,
            error           text         NULL,
            chain_id        varchar(36)  NULL,
            chain_history   longtext     NULL,
            PRIMARY KEY  (id),
            KEY status       (status),
            KEY triggered_by (triggered_by),
            KEY conversation_id (conversation_id),
            KEY chain_id     (chain_id)
        ) {$charset};";
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $sql );
    }

    public function add( array $item ): int|WP_Error {
        global $wpdb;
        $now = current_time( 'mysql', true );
        $inserted = $wpdb->insert(
            $wpdb->prefix . self::TABLE,
            [
                'action'          => sanitize_key( $item['action'] ),
                'params'          => wp_json_encode( $item['params'] ?? [] ),
                'status'          => 'pending',
                'triggered_by'    => in_array( $item['triggered_by'] ?? '', [ 'conversation', 'autonomous' ], true )
                                     ? $item['triggered_by'] : 'conversation',
                'conversation_id' => ! empty( $item['conversation_id'] ) ? (int) $item['conversation_id'] : null,
                'chain_id'        => ! empty( $item['chain_id'] ) ? sanitize_text_field( $item['chain_id'] ) : null,
                'chain_history'   => ! empty( $item['chain_history'] ) ? wp_json_encode( $item['chain_history'] ) : null,
                'created_at'      => $now,
                'updated_at'      => $now,
            ],
            [ '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s' ]
        );
        if ( ! $inserted ) {
            return new WP_Error( 'db_error', __( 'Could not insert queue item.', 'wp-ai-daemon' ) );
        }
        return (int) $wpdb->insert_id;
    }

    public function get( int $id ): array|WP_Error {
        global $wpdb;
        $row = $wpdb->get_row(
            $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}" . self::TABLE . " WHERE id = %d", $id ),
            ARRAY_A
        );
        if ( ! $row ) {
            return new WP_Error( 'not_found', __( 'Queue item not found.', 'wp-ai-daemon' ), [ 'status' => 404 ] );
        }
        return $this->decode_row( $row );
    }

    public function list( ?string $status = null, int $limit = 50 ): array {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE;
        if ( $status ) {
            $rows = $wpdb->get_results(
                $wpdb->prepare( "SELECT * FROM {$table} WHERE status = %s ORDER BY id DESC LIMIT %d", $status, $limit ),
                ARRAY_A
            );
        } else {
            $rows = $wpdb->get_results(
                $wpdb->prepare( "SELECT * FROM {$table} ORDER BY id DESC LIMIT %d", $limit ),
                ARRAY_A
            );
        }
        return array_map( [ $this, 'decode_row' ], $rows ?: [] );
    }

    public function pop_pending(): array|null {
        global $wpdb;
        $row = $wpdb->get_row(
            "SELECT * FROM {$wpdb->prefix}" . self::TABLE . " WHERE status = 'pending' ORDER BY id ASC LIMIT 1",
            ARRAY_A
        );
        return $row ? $this->decode_row( $row ) : null;
    }

    public function update_status( int $id, string $status, ?array $result = null, ?string $error = null ): bool {
        global $wpdb;
        $data   = [ 'status' => $status, 'updated_at' => current_time( 'mysql', true ) ];
        $format = [ '%s', '%s' ];
        if ( $result !== null ) { $data['result'] = wp_json_encode( $result ); $format[] = '%s'; }
        if ( $error !== null )  { $data['error']  = $error;                    $format[] = '%s'; }
        return (bool) $wpdb->update( $wpdb->prefix . self::TABLE, $data, [ 'id' => $id ], $format, [ '%d' ] );
    }

    public function delete( int $id ): bool {
        global $wpdb;
        return (bool) $wpdb->delete( $wpdb->prefix . self::TABLE, [ 'id' => $id ], [ '%d' ] );
    }

    private function decode_row( array $row ): array {
        $row['params']        = json_decode( $row['params'] ?? '{}', true ) ?? [];
        $row['result']        = $row['result'] ? ( json_decode( $row['result'], true ) ?? [] ) : null;
        $row['chain_history'] = $row['chain_history'] ? ( json_decode( $row['chain_history'], true ) ?? [] ) : null;
        $row['id']            = (int) $row['id'];
        return $row;
    }
}
```

---

## class-queue-runner.php

Registered as an Action Scheduler recurring action (`wp_ai_daemon_process_queue`, every 2 minutes). Resolves actions via the WordPress Abilities API. After completing a chained item, calls the LLM to get the next step.

```php
<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class WP_AI_Daemon_Queue_Runner {

    public function process(): void {
        $max = max( 1, (int) get_option( 'wpad_max_queue_per_run', 10 ) );
        for ( $i = 0; $i < $max; $i++ ) {
            if ( ! $this->process_one() ) break;
        }
        if ( mt_rand( 1, 100 ) === 1 ) {
            $retention = max( 1, (int) get_option( 'wpad_log_retention_days', 30 ) );
            WP_AI_Daemon_Logger::prune( $retention );
        }
    }

    private function process_one(): bool {
        $queue = new WP_AI_Daemon_Action_Queue();
        $item  = $queue->pop_pending();
        if ( ! $item ) return false;

        $id         = $item['id'];
        $action_key = $item['action'];
        $queue->update_status( $id, 'executing' );
        WP_AI_Daemon_Logger::info( "Executing action: {$action_key}", [ 'queue_id' => $id ] );

        $ability_name = 'wp-ai-daemon/' . str_replace( '_', '-', $action_key );
        $ability      = wp_get_ability( $ability_name );

        if ( ! $ability ) {
            $msg = "Unknown action type: {$action_key}";
            $queue->update_status( $id, 'failed', null, $msg );
            WP_AI_Daemon_Logger::error( $msg, [ 'queue_id' => $id ] );
            return true;
        }

        if ( ! $ability->check_permissions() ) {
            $msg = "Permission denied for action: {$action_key}";
            $queue->update_status( $id, 'failed', null, $msg );
            WP_AI_Daemon_Logger::error( $msg, [ 'queue_id' => $id ] );
            return true;
        }

        $result = $ability->execute( $item['params'] );

        if ( is_wp_error( $result ) ) {
            $queue->update_status( $id, 'failed', null, $result->get_error_message() );
            WP_AI_Daemon_Logger::error( "Action failed: {$action_key}", [ 'queue_id' => $id, 'error' => $result->get_error_message() ] );
            return true;
        }

        $queue->update_status( $id, 'completed', $result );
        WP_AI_Daemon_Logger::info( "Action complete: {$action_key}", [ 'queue_id' => $id, 'result' => $result ] );

        // Continue chain if applicable.
        if ( ! empty( $item['chain_id'] ) ) {
            $this->continue_chain( $item, $result );
        }

        return true;
    }

    private function continue_chain( array $item, array $result ): void {
        $max_steps    = max( 1, (int) get_option( 'wpad_max_chain_steps', 10 ) );
        $history      = $item['chain_history'] ?? [];
        $history[]    = [ 'action' => $item['action'], 'params' => $item['params'], 'result' => $result ];

        if ( count( $history ) >= $max_steps ) {
            WP_AI_Daemon_Logger::info( 'Chain reached max steps, stopping.', [ 'chain_id' => $item['chain_id'] ] );
            return;
        }

        $next = $this->ask_llm_for_next_step( $history );
        if ( ! $next || ( $next['signal'] ?? '' ) === 'done' ) {
            WP_AI_Daemon_Logger::info( 'Chain complete.', [ 'chain_id' => $item['chain_id'], 'steps' => count( $history ) ] );
            return;
        }

        $queue = new WP_AI_Daemon_Action_Queue();
        $queue->add( [
            'action'        => $next['action'],
            'params'        => $next['params'] ?? [],
            'triggered_by'  => 'autonomous',
            'chain_id'      => $item['chain_id'],
            'chain_history' => $history,
        ] );
    }

    private function ask_llm_for_next_step( array $history ): ?array {
        // TODO: implement — call wp_ai_client_prompt() with chain_history as context,
        // ask LLM for next action object or { "signal": "done" }.
        // Re-use the same JSON response schema as chat/autonomous modes.
        return null;
    }
}
```

---

## class-scheduled-actions.php

Queries Action Scheduler for user-created scheduled actions (hook: `wp_ai_daemon_scheduled_action`, group: `wp-ai-daemon`). Provides a context block for the autonomous LLM prompt.

```php
<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class WP_AI_Daemon_Scheduled_Actions {

    public static function list(): array {
        if ( ! function_exists( 'as_get_scheduled_actions' ) ) return [];

        $raw = as_get_scheduled_actions( [
            'hook'     => 'wp_ai_daemon_scheduled_action',
            'group'    => 'wp-ai-daemon',
            'status'   => \ActionScheduler_Store::STATUS_PENDING,
            'per_page' => 100,
            'orderby'  => 'date',
            'order'    => 'ASC',
        ] );

        $result = [];
        foreach ( $raw as $id => $action ) {
            $args             = $action->get_args();
            $queue_item       = $args[0] ?? [];
            $schedule         = $action->get_schedule();
            $is_recurring     = false;
            $interval_seconds = null;
            $next_run         = null;

            if ( $schedule instanceof \ActionScheduler_IntervalSchedule ) {
                $is_recurring     = true;
                $interval_seconds = (int) $schedule->get_recurrence();
            }

            $next_dt = method_exists( $schedule, 'next' ) ? $schedule->next() : null;
            if ( $next_dt ) {
                $next_run = gmdate( 'Y-m-d H:i:s', $next_dt->getTimestamp() ) . ' UTC';
            }

            $result[] = [
                'id'               => $id,
                'action'           => $queue_item['action'] ?? 'unknown',
                'params'           => $queue_item['params'] ?? [],
                'is_recurring'     => $is_recurring,
                'interval_seconds' => $interval_seconds,
                'next_run'         => $next_run,
            ];
        }
        return $result;
    }

    public static function format_context(): string {
        $actions = self::list();
        if ( empty( $actions ) ) return 'No scheduled actions currently set up.';

        $lines = [ 'Currently scheduled actions (' . count( $actions ) . '):' ];
        foreach ( $actions as $item ) {
            $schedule_desc = $item['is_recurring']
                ? 'recurring every ' . $item['interval_seconds'] . 's'
                : 'one-time';
            $next    = $item['next_run'] ? ', next run: ' . $item['next_run'] : '';
            $lines[] = "  [ID: {$item['id']}] {$item['action']} — {$schedule_desc}{$next}";
        }
        return implode( "\n", $lines );
    }

    public static function cancel( int $id ): bool {
        if ( ! $id || ! function_exists( 'as_get_scheduled_actions' ) ) return false;
        try {
            \ActionScheduler::store()->cancel_action( $id );
            return true;
        } catch ( \Exception $e ) {
            WP_AI_Daemon_Logger::error( 'Failed to cancel scheduled action.', [
                'action_scheduler_id' => $id,
                'error'               => $e->getMessage(),
            ] );
            return false;
        }
    }
}
```

---

## class-autonomous-service.php

Registered as a recurring Action Scheduler action (`wp_ai_daemon_autonomous_run`). Frequency is user-configurable; `wpad_autonomous_enabled` gates whether it runs at all.

```php
<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class WP_AI_Daemon_Autonomous_Service {

    public function run(): void {
        if ( ! get_option( 'wpad_autonomous_enabled', true ) ) return;
        if ( ! function_exists( 'wp_ai_client_prompt' ) ) {
            WP_AI_Daemon_Logger::error( 'Autonomous run skipped: no AI provider configured.' );
            return;
        }

        @set_time_limit( 300 );
        WP_AI_Daemon_Logger::info( 'Autonomous run started.' );

        $instructions = $this->get_instructions();
        if ( empty( trim( $instructions ) ) || str_starts_with( trim( $instructions ), '<!--' ) ) {
            WP_AI_Daemon_Logger::info( 'Autonomous run skipped: no instructions configured.' );
            return;
        }

        $context  = $this->build_context();
        $result   = $this->call_llm( $instructions, $context );

        if ( is_wp_error( $result ) ) {
            WP_AI_Daemon_Logger::error( 'Autonomous LLM call failed.', [ 'error' => $result->get_error_message() ] );
            update_option( 'wpad_autonomous_last_run', [
                'time' => current_time( 'mysql', true ), 'status' => 'error', 'summary' => $result->get_error_message(),
            ] );
            return;
        }

        $chain_id = wp_generate_uuid4();
        $queued   = $this->queue_actions( $result['actions'] ?? [], $chain_id );

        update_option( 'wpad_autonomous_last_run', [
            'time'    => current_time( 'mysql', true ),
            'status'  => 'ok',
            'summary' => $result['message'],
            'queued'  => count( $queued ),
        ] );
        WP_AI_Daemon_Logger::info( 'Autonomous run complete.', [
            'summary'  => $result['message'],
            'queued'   => count( $queued ),
            'chain_id' => $chain_id,
        ] );
    }

    private function build_context(): string {
        $lines   = [];
        $lines[] = 'Current date/time (UTC): ' . gmdate( 'Y-m-d H:i:s' );
        $lines[] = '';
        $lines[] = WP_AI_Daemon_Scheduled_Actions::format_context();
        $lines[] = '';
        $log_entries = WP_AI_Daemon_Logger::get_recent( 10 );
        if ( $log_entries ) {
            $lines[] = 'Recent activity log:';
            foreach ( $log_entries as $entry ) {
                $lines[] = "  [{$entry['level']}] {$entry['created_at']}: {$entry['message']}";
            }
            $lines[] = '';
        }
        return implode( "\n", $lines );
    }

    private function call_llm( string $instructions, string $context ): array|WP_Error {
        $schema   = ( new WP_AI_Daemon_Chat_Service() )->response_schema();
        $prompt   = $context . "\n---\n\nYour standing instructions:\n\n" . $instructions;
        $response = wp_ai_client_prompt( $prompt )
            ->using_system_instruction( $this->get_system_instruction() )
            ->using_temperature( 0.3 )
            ->as_json_response( $schema )
            ->generate_text();

        if ( is_wp_error( $response ) ) return $response;

        $data = json_decode( $response, true );
        if ( json_last_error() !== JSON_ERROR_NONE || empty( $data['message'] ) ) {
            return new WP_Error( 'parse_error', __( 'Failed to parse autonomous LLM response.', 'wp-ai-daemon' ) );
        }

        $actions_raw     = $data['actions_json'] ?? '';
        $data['actions'] = ! empty( $actions_raw ) ? ( json_decode( $actions_raw, true ) ?? [] ) : [];
        unset( $data['actions_json'] );
        return $data;
    }

    private function queue_actions( array $actions, string $chain_id ): array {
        if ( empty( $actions ) ) return [];
        $queue  = new WP_AI_Daemon_Action_Queue();
        $queued = [];
        foreach ( $actions as $action_obj ) {
            $action_key   = $action_obj['action'] ?? '';
            $params       = $action_obj['params'] ?? [];
            $ability_name = 'wp-ai-daemon/' . str_replace( '_', '-', $action_key );
            $ability      = function_exists( 'wp_get_ability' ) ? wp_get_ability( $ability_name ) : null;
            if ( ! $ability ) {
                WP_AI_Daemon_Logger::error( "Autonomous: unknown action type: {$action_key}" );
                continue;
            }
            $id = $queue->add( [
                'action'        => $action_key,
                'params'        => $params,
                'triggered_by'  => 'autonomous',
                'chain_id'      => $chain_id,
                'chain_history' => [],
            ] );
            if ( ! is_wp_error( $id ) ) $queued[] = $id;
        }
        return $queued;
    }

    private function get_instructions(): string {
        $file = WP_AI_DAEMON_DIR . 'autonomous-instructions.md';
        return file_exists( $file ) ? file_get_contents( $file ) : ''; // phpcs:ignore
    }

    private function get_system_instruction(): string {
        $file = WP_AI_DAEMON_DIR . 'system-prompt.md';
        return file_exists( $file ) ? file_get_contents( $file ) : 'You are a personal AI agent for WordPress running in autonomous mode.'; // phpcs:ignore
    }
}
```

---

## Ability: schedule-action

**Input schema:**
| param | type | notes |
|---|---|---|
| `action` | string | WP AI Daemon action key (e.g. `web_fetch`) |
| `action_params` | string | JSON-encoded params for the nested action |
| `schedule` | `"once"` \| `"recurring"` | |
| `interval_seconds` | integer | min 60, required for recurring |
| `first_run` | string | ISO 8601 datetime |

**What it does:** Calls `as_schedule_single_action()` or `as_schedule_recurring_action()` with hook `wp_ai_daemon_scheduled_action`, group `wp-ai-daemon`. The hook fires with `$queue_item = [ 'action' => ..., 'params' => ..., 'triggered_by' => 'autonomous' ]` which is then added to the action queue.

**Prompt:** _"Only schedule actions the user has explicitly asked to automate. Use the longest reasonable interval — never schedule recurring actions at less than 60-second intervals. Confirm the first_run time and interval with the user if there is any ambiguity. Do not create duplicate recurring schedules for the same purpose."_

---

## Ability: cancel-scheduled-action

**Input schema:**
| param | type | notes |
|---|---|---|
| `action_scheduler_id` | integer | ID from the scheduled actions list |

**What it does:** Calls `WP_AI_Daemon_Scheduled_Actions::cancel( $id )`.

**Prompt:** _"Always confirm the action_scheduler_id matches what the user wants to cancel — show the ID in your message field before issuing the cancellation. Never cancel wp_ai_daemon_process_queue or wp_ai_daemon_autonomous_run (those are the daemon's own internal heartbeat jobs)."_

---

## REST endpoints

| Method | Path | Description |
|---|---|---|
| `GET` | `/wp-ai-daemon/v1/queue` | List queue items; optional `?status=` filter |
| `GET` | `/wp-ai-daemon/v1/scheduled-actions` | List pending scheduled actions |
| `DELETE` | `/wp-ai-daemon/v1/scheduled-actions/{id}` | Cancel a scheduled action |

`get_status()` should include `queue_depth` and `autonomous` (enabled, last_run).

---

## wp-ai-daemon.php wiring

```php
require_once WP_AI_DAEMON_DIR . 'includes/class-autonomous-settings-page.php';
require_once WP_AI_DAEMON_DIR . 'includes/class-scheduled-actions.php';
require_once WP_AI_DAEMON_DIR . 'includes/class-queue-runner.php';
require_once WP_AI_DAEMON_DIR . 'includes/class-action-queue.php';
require_once WP_AI_DAEMON_DIR . 'includes/class-autonomous-service.php';
require_once WP_AI_DAEMON_DIR . 'includes/class-dashboard-page.php';

// Ability registrations:
'schedule_action'         => WP_AI_Daemon_Ability_Schedule_Action::class,
'cancel_scheduled_action' => WP_AI_Daemon_Ability_Cancel_Scheduled_Action::class,

// Action callbacks:
add_action( 'wp_ai_daemon_process_queue',    [ new WP_AI_Daemon_Queue_Runner(), 'process' ] );
add_action( 'wp_ai_daemon_autonomous_run',   [ new WP_AI_Daemon_Autonomous_Service(), 'run' ] );
add_action( 'wp_ai_daemon_scheduled_action', function ( array $item ) {
    ( new WP_AI_Daemon_Action_Queue() )->add( $item );
}, 10, 1 );

// Activation:
WP_AI_Daemon_Action_Queue::create_tables();
wp_ai_daemon_schedule_queue_runner();

// Deactivation:
as_unschedule_all_actions( 'wp_ai_daemon_process_queue' );
as_unschedule_all_actions( 'wp_ai_daemon_autonomous_run' );

// Keep heartbeat alive after AS initialises:
function wp_ai_daemon_schedule_queue_runner(): void {
    if ( ! as_next_scheduled_action( 'wp_ai_daemon_process_queue', [], 'wp-ai-daemon' ) ) {
        as_schedule_recurring_action( time(), 2 * MINUTE_IN_SECONDS, 'wp_ai_daemon_process_queue', [], 'wp-ai-daemon' );
    }
}
add_action( 'action_scheduler_init', 'wp_ai_daemon_schedule_queue_runner' );
```

---

## Settings options

| Option | Default | Purpose |
|---|---|---|
| `wpad_autonomous_enabled` | `true` | Gates whether `run()` executes |
| `wpad_max_queue_per_run` | `10` | Max items queue runner processes per 2-min cycle |
| `wpad_max_chain_steps` | `10` | Max LLM-driven steps per chain before hard stop |
| `wpad_autonomous_last_run` | `null` | Array: `{ time, status, summary, queued }` |

Autonomous mode should be **disabled by default** — enable explicitly to avoid unintended background LLM calls.

---

## autonomous-instructions.md default content

```markdown
# Autonomous Instructions

<!--
  This file is your standing instructions for the autonomous agent.
  Edit to tell the agent what to work on automatically.

  Examples:
  - Check https://wordpress.org/news/ daily and draft a summary post of any new releases.
  - Monitor recent posts and suggest improvements in a draft post once a week.
  - Every Monday, draft a "Week ahead" post with three suggested topics.

  Tips:
  - Be specific about frequency ("once a week", "every day at 9am").
  - The agent can fetch URLs, draft posts/pages, and write plugins.
  - It cannot publish or activate anything without your approval.
-->

<!-- Replace this comment with your standing instructions. -->
```
