Use this ability to schedule a WPAD action to run at a future time or on a recurring interval. Issue the call immediately — do not describe what you are about to do and wait for confirmation.

Before scheduling, call list-scheduled-actions to check whether a similar action is already set up. Do not create duplicate schedules for the same purpose.

Guidelines:
- Infer a sensible first_run (default: now) and interval from the user's request. Do not ask for clarification if the intent is clear.
- Always tell the user the action_scheduler_id returned so they can reference or cancel it later.
- For recurring actions, omit interval_seconds only for one-time schedules.

Available action keys: web_fetch, create_post, update_post, create_page, update_page, write_plugin, run_snippet.
