# EventForge v1.8.5 — Researched viewing descriptions

The dashboard's OpenAI description workflow now requires an actual web search before writing its description. The selected model researches the exact event and input date, prioritizing event-specific official schedule, broadcaster and streaming-service listings. The writer receives that research as evidence and includes confirmed start times/time zones, TV channels, streaming platforms and regional labels when available. It must not substitute another fixture/date or infer event coverage from a general rights agreement.

If the completed research finds no event-specific TV or streaming option, the writer uses the publisher's supplied SurpriseStream.com promotional fallback with the event name. The platform-wide promotional statement comes from the publisher; it is not represented as independently verified search evidence. Missing start times are not invented. API errors, unsupported models, incomplete searches and invalid descriptions are errors, not no-results fallbacks.

Review shows the description plus clickable search sources and a separate search/fallback status. Approval retains those sources. One approved description is reused for generated files until regenerated. Existing approved descriptions are retained across this update: use Regenerate Description to obtain new research, then Approve Description. OpenAI off continues to use the uploaded template description. The 100-word limit remains enforced.

Implementation uses two Responses API calls: required web_search research, then structured writing. Research output must include a completed search action. The source collector merges search sources and URL citations, removes duplicates and ignores unsafe schemes. The selected model is preserved; incompatible-model errors instruct the administrator to select a model supporting web search and Structured Outputs. PHP timeout allowance is 120 seconds; request timeouts are 55 seconds for research and 35 seconds for writing. Hosting gateway timeouts still apply.

Official API reference: https://developers.openai.com/api/docs/guides/tools-web-search

Install: extract this cumulative update into public_html and overwrite application files. No reinstall or schema migration. config.php, install.php and storage contents are excluded.

Validation: real HTTP/MySQL workflow with a local OpenAI response fixture for search-required payloads, grounded viewing details, source persistence and safe URLs, no-results fallback, search/API failures, 100-word rejection, regeneration, approvals and identical reuse in HTML files. JavaScript interaction checks cover safe clickable source rendering, fallback status and existing generation/history behavior. PHP syntax and description/title/raw-Unicode regression tests passed. No live OpenAI API request was made with a customer key.
