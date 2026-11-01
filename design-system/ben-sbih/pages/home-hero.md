# Homepage header and hero — coastal direction

Implementation preview: `apps/site/app/design-lab/accueil/`. Rebuilt at the user's explicit request after rejection of the ring/car composition.

- White header aligned with the content; supplied logo, two navigation links, confirmed phone/WhatsApp, a four-language select and a reservation shortcut. Mobile keeps contact links visible and moves navigation into the menu.
- Desktop: headline and search form grouped on the left, coastal car photograph framed on the right. No geometric ring, oversized detached rectangle or car cutout. White space belongs to clear content groups rather than artificial viewport height.
- Reuses the existing generated `tanger-coastal-hero.png`; it is illustrative, not agency fleet evidence. Visible caption and translated image alternatives preserve this distinction.
- Search: location across the card, paired departure/return fields, a full-width CTA, delivery detail and unconfirmed insurance notice. Tablet places search below the title/image; phones stack title, framed image and form.
- FR/AR/EN/ES cover all hero content, input labels, calendar locale/navigation, validation, menu and image alternatives. URL `?lang=` preserves language on reload. Switching language retains current form state. Dates always day/month/year and 24-hour times.
- No new sections or live booking/API changes. Valid preview searches explicitly say no availability or booking has been confirmed.

## Skill assessment and verification
Used UI/UX Pro Max. An editorial/travel landing query returned no match; a narrower Hero-Centric Trust query returned relevant clear-CTA/low-friction-form guidance. We adopted that hierarchy and explicit-label UX guidance, not suggested unverified statistics, certifications or new sections. Art direction is project-specific.

Verified at 320px Arabic, 375px, 667px landscape, 768px, 1024px, 1440px and 1920px with no horizontal overflow. Tested mobile menu-to-form focus, four-language switch, language persistence on reload, retained date values on language change, English and Spanish invalid-return messages, Spanish calendar labels, Escape closing calendar, valid-search preview feedback. Site TypeScript check passed. No animation or dark theme introduced.
