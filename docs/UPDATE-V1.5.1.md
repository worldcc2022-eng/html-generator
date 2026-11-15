# EventForge v1.5.1 — {date} placeholder

Extract into the existing public_html and overwrite included files. No reinstall; configuration, templates, title bank and usage history are retained. This is a cumulative application update.

Title patterns now accept {date} as an alias for {event_date}. Both render the dashboard event date in the format 31 October 2026. Both aliases may be used in the same template and repeated placeholders are supported. {event_name} remains required on every imported template. Existing templates using {event_date} continue to work.

Example: [LIVE] {event_name} — {date}

Focused tests verify {date}, existing {event_date}, repeated aliases and leap-day dates. PHP syntax checks pass. No live provider call is needed for this title substitution.
