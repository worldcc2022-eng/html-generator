# EventForge v1.8.3 — omit duplicated year in title dates

Extract into existing public_html and overwrite included files. No reinstall. Generate a new batch.

When the event name already contains the selected date's year, {date} and {event_date} render day and month only. Example: Chelsea vs Manchester United 2026 with 31 October 2026 renders the event name unchanged and the date placeholder as 31 October. If the event name has no matching year, the full date remains. A different year is not suppressed, and a matching year inside a longer number is not treated as the year.

Only title-template date substitutions change. Article dates remain YYYY-MM-DD, AI description date inputs remain unchanged, and IDs, Unicode text, static template wording and domain rotation are preserved. Existing generated batches are unchanged.
