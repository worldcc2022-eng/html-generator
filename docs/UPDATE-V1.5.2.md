# EventForge v1.5.2 — event type placeholder

Extract into existing public_html and overwrite the included files. No reinstall; this cumulative update preserves configuration, templates, title bank, usage history, API key and existing batches.

{event} renders the selected Event Type from the dashboard. Options include Boxing, MMA, UFC, Football, Soccer, NFL, NBA, NHL, MLB, Basketball, Baseball, Tennis, Golf, Cricket, Rugby, Motorsport, Wrestling and Marathon. General Event renders Event. Fill Manual Event Type to override the dropdown with your own wording, up to 100 characters. Other / Custom requires a manual value.

Example:

++[{event}@TV] {event_name} LIVE Streams {date}

With Boxing selected, Navarrete vs Foster as the keyword and 31 October 2026 as the date:

++[Boxing@TV] Navarrete vs Foster LIVE Streams 31 October 2026

Every occurrence of {event} is substituted. {event_name}, {date}, and {event_date} retain their existing meanings. Static text such as Boxing in an uploaded pattern stays literal; use {event} for dynamic sport wording. Used-title history remains tied to the keyword, so changing the type does not recycle already-used title patterns. Existing batches retain their snapshots.

Tests verify repeated placeholders, multiword manual labels, real HTTP dropdown substitution, manual override precedence, missing/invalid custom labels, and the existing 100,005-template import and no-repeat regressions. Live OpenAI and browser rendering were not reverified; this placeholder uses PHP only.
