# Public website clickable prototype — 2026-10-27

Local-only, standalone static prototype. No API calls, database mutations, payment processing or real requests. Contact inputs live in memory and reset on reload. Photos copied from the existing demo fleet; pricing and equipment are illustrative. No commercial conditions approved by this prototype.

Start from this directory: `python3 -m http.server 3105 --bind 127.0.0.1`.
Open http://127.0.0.1:3105/ or mobile.html (390px iframe, not physical-device testing).

Includes home, category filter and empty state, vehicle detail, editable dates, hotel/airport pickup and return, optional flight, validated request and simulation receipt. FR/AR direction switching. Uses existing approved SVG logo.

Browser verification: hotel flow with preserved name/phone and 800 DH example total; airport flow completed at 390px; Arabic receipt; invalid date message. No integration test required as this is deliberately disconnected.
## Revision 02
Reorganized homepage into editorial hero, separate booking block, three-step explanation, catalogue, delivery and FAQ. Clarified primary navigation to date selection. Rebalanced vehicle details and request summary; on mobile, request fields precede full summary. Version 01 source retained in archive-v1. Still local-only and disconnected.
## Four-language preview
FR, AR (RTL), EN and ES. All 108 translation keys have EN/ES entries. Shared fields remain untouched when changing language. Language stored in URL (?lang=en / es / ar / fr), contact language visible in simulated receipt. Dates use the selected locale; currency remains Moroccan dirham (MAD in EN/ES), no exchange conversion. No backend or document-language changes. Browser checked EN→ES with hotel/name/phone preserved. Commercial terms still require agency approval.
