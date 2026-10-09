Design a public marketing page for "Certificate Generator," a WordPress plugin for issuing, verifying, and bulk-sending certificates, badges, and QR-verified credentials.

Content source: marketing-site/content.json in this repo — pull hero copy, feature list, why-choose-us bullets, comparison table, and plan summary directly from it. Do not invent features not listed there.
Screenshots: marketing-site/screenshots/ — each feature in content.json has a screenshot_ref filename pointing to an image in that folder (some are still placeholders per the screenshots_needed list; use a neutral placeholder frame for any marked "missing").

Page sections, in order:
1. Hero — headline, subheadline, primary + secondary CTA, hero screenshot (hero.screenshot_ref).
2. Feature grid — one card per entry in features[]: title, pitch (short/bold), detail (supporting line), screenshot. Alternate image-left/image-right or use a clean icon+card grid — your call, but every feature needs visual weight, not just a bullet list.
3. Why choose us — the why_choose_us[] entries as a 4-5 item highlight row or icon-strip, punchy not walls-of-text.
4. Comparison table — comparison.rows rendered as "Certificate Generator" vs "Typical certificate plugins," with clear visual win-marking (checkmarks/x's or color) on our column. Keep the note that this is a category comparison, not a named competitor.
5. Pricing — plans_summary[] as 3 cards (Free / Pro / Business), Pro visually emphasized as the recommended tier.
6. Final CTA — repeat primary CTA.

Style direction:
- Clean SaaS-product aesthetic: generous whitespace, one accent color, readable type scale, no stock-photo clutter.
- Trustworthy/institutional undertone (certificates = credentials, schools, professional recognition) — avoid anything playful/toy-like.
- Mobile-first responsive; comparison table must degrade to a stacked/accordion view on small screens, not a horizontal-scroll table.
- Every screenshot in a consistent device/browser frame.

Deliverable: a single responsive HTML page (or component set if targeting a specific framework — ask if unspecified) wired to read copy from content.json rather than hardcoding strings, so future edits only touch the JSON.
