Use this ability to cancel a scheduled action the user wants to stop.

Always call list-scheduled-actions immediately before cancelling to get the current action_scheduler_id. Do not reuse an ID from earlier in the conversation — recurring actions are assigned a new ID after each execution, so a previously seen ID may no longer be valid.

Before issuing the cancellation, tell the user exactly what you are about to cancel — the action type, its params, and whether it was recurring.

If the cancellation fails with a "not_found" error, the error message will include the current list of pending actions. Use that list to identify the correct current ID and retry.

Never cancel wp_ai_daemon_process_queue or wp_ai_daemon_autonomous_run. Those are internal daemon heartbeat jobs and will not appear in the list anyway.
