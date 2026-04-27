# WP AI Daemon — Things to Try

A collection of example prompts and scenarios for testing and exploring WP AI Daemon. Organized roughly by which parts of the system they exercise.

---

## Conversational mode — simple

These should work in the chat UI with minimal setup.

**Fetch and summarize**
> Fetch https://hnrss.org/frontpage and draft a post summarizing the top 5 stories today.

**Research and write**
> Fetch the Wikipedia article on the Red River Flood of 1997 and draft a post about it for a Winnipeg local history blog.

**Iterative drafting**
> Draft a short post about the benefits of cycling infrastructure in mid-sized cities. Then fetch https://nacto.org/publication/urban-street-design-guide/ and revise the draft to reference anything relevant you find there.

---

## Conversational mode — plugin generation

These test the write_plugin action and the approval queue.

**Simple utility plugin**
> Write a plugin that adds a [current-weather] shortcode that fetches current conditions for Winnipeg from wttr.in and displays them inline.

**Admin tool**
> Write a plugin that adds a Tools > Post Freshness page showing all posts older than 6 months that haven't been updated, sorted by view count if Jetpack stats are available.

**WP AI Daemon extension**
> Write a WP AI Daemon action plugin that adds a `send_webhook` action type. It should POST a JSON payload to a configurable URL. Register it via the `wp-ai-daemon_actions` filter.

This last one is the self-expansion loop in action — once activated, WP AI Daemon can use `send_webhook` in future conversations and scheduled tasks.

---

## Scheduled / autonomous mode

These go in `autonomous-instructions.md` and run on a recurring schedule.

**Daily digest**
> Every day, fetch https://hnrss.org/frontpage and https://lobste.rs/rss and draft a post titled "Morning Links — [date]" containing the 5 most interesting stories across both feeds, with a one-sentence note on each.

**Winnipeg news monitor**
> Check https://www.cbc.ca/cmlink/rss-canada-manitoba once a day. If any story mentions cycling, transit, or urban planning, draft a post summarizing it and tag it "local advocacy."

**Content freshness**
> Once a week, find the 3 oldest posts on this site that haven't been updated in over a year. For each one, fetch the URLs linked in the post and check if any return 404s. Draft an editor's note listing any broken links found.

---

## Scheduling actions from chat

These test the `schedule_action` capability — telling WP AI Daemon to set up its own recurring work.

**Set up a recurring fetch**
> Every Monday morning, fetch https://winnipeg.ca/news/ and draft a post summarizing any new city announcements from the past week. Set this up as a recurring scheduled action.

**One-time future task**
> On April 22 (Earth Day), draft a post about Winnipeg's tree canopy and urban green space. Schedule it now.

---

## Multi-step chains

These test the queue handling multiple dependent actions in sequence.

**Research → draft → schedule**
> Fetch https://transitapp.com/blog and find the most recent article about Canadian cities. Summarize it into a draft post. Then schedule a recurring action to check that blog every two weeks and do the same thing automatically.

**Fetch → plugin → extend**
> Fetch the Action Scheduler documentation at https://actionscheduler.org and write a WP AI Daemon action plugin that adds a `cancel_scheduled_action` action type, so WP AI Daemon can cancel its own scheduled tasks by hook name.

---

## Stress / edge cases

Good for finding bugs.

- Ask it to fetch a URL that returns a 404
- Ask it to update a post that doesn't exist
- Ask it to schedule a recurring action with an interval of 10 seconds (should either clamp to a minimum or explain why that's inadvisable)
- Ask it to write a plugin with a slug that already exists in wp-content/plugins/
- Send a very long autonomous instruction and see if context length causes issues at runtime
