Use this ability to answer any user question about what is currently scheduled — "what's running?", "do I have anything set up?", "when does X next run?", etc.

Always call this before scheduling a new action to avoid creating duplicates. If an identical or similar action is already scheduled, tell the user rather than adding another.

The `id` field in each result is the Action Scheduler ID. Reference it when describing actions to the user so they can ask you to cancel by name or description — you will resolve the correct ID for them.
