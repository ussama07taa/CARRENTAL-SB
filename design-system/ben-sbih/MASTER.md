# Ben Sbih — design decisions

## Authority and scope
User-confirmed brand constraints take precedence over UI/UX Pro Max search suggestions. This document preserves the public-site direction; it does not authorize redesigning the dashboard or adding sections. Work remains step by step: approved header/hero, fleet section, then vehicle-detail preview. Consult page overrides; contact/request submission remains a later step.

## Tooling
- Skill: `.agents/skills/ui-ux-pro-max/SKILL.md`; upstream pinned in its `UPSTREAM.json`, MIT license retained.
- Search uses local Python 3 and bundled CSVs, no npm/browser runtime dependency.
- Invoke `python3 /home/taaouati/projects/bensebih-car-rental/.agents/skills/ui-ux-pro-max/scripts/search.py "query" --domain ux` (or the detected stack `nextjs`).
- Consult recommendations, inspect their relevance, then make deliberate decisions. Never treat a generated recommendation as a verified commercial policy.

## Brand and hierarchy
- Light theme only. Primary #2463EB, text navy #102344, white surfaces, restrained pale-blue background. Muted text must retain 4.5:1 contrast.
- Keep the supplied Ben Sbih logo and its proportions. Manrope for Latin text; Noto Sans Arabic for Arabic.
- Use an 8px spacing rhythm, shared content edges, visible form labels, clear selected states and keyboard focus. Controls aim for 44px minimum touch height; no hover-only actions.
- Headings communicate the offer, photography supports it, booking fields remain easy to scan. Avoid excessive small text, decorative floating badges and giant empty gutters.
- Reuse existing Lucide icons. No extra component library or motion dependency is needed for this hero.

## Product facts
- One booking intent: “Voir les disponibilités”. Header CTA focuses the same reservation form.
- Phone confirmed by user: 0770098571. Tel +212770098571; WhatsApp manual wa.me link only.
- Pickup: Tanger Aéroport, Tanger Ville, Hôtel. Display “Livraison Aéroport / Hôtel”.
- No price placeholders or invented prices, fleet size, reviews, availability or commercial promises.
- Insurance/cancellation remain unconfirmed: “Assurance et conditions à confirmer”.
- Generated car imagery is illustrative, not the real fleet. Keep that distinction visible.
- Four public-preview languages: French, Arabic, English and Spanish, including calendars, validation messages and image alternatives. Dates stay day/month/year (localized placeholder), times 24h. A compact named language select replaces the former two-language toggle; Arabic layout is RTL. User explicitly requested these additions with the header/hero rebuild.

## Validation
Inspect desktop 1440/1920, tablet 768/1024, mobile 375/320 and landscape. Check overflow, readable sizing, contact links, language/menu keyboard behavior, date picker, input validation and booking destination. Respect reduced motion; don't add dark mode to a light-only brief. The design-lab form remains a preview with no live booking.

## Search assessment
The query “car rental booking premium” returned a relevant Hero-Centric pattern but an unsuitable motion-heavy style, red palette and different fonts. The narrower “minimal service booking” also returned an unsuitable multi-step funnel. These were not persisted as design rules. Adopt the relevant single-intent hierarchy; retain the user's brand and single search form. The UX query “responsive navigation touch targets” returned applicable touch sizing/spacing guidance; the Next.js image query returned responsive image optimization guidance. Visual direction is a project-specific synthesis, not a prebuilt template from the skill.
