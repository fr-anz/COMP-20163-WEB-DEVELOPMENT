---
name: review-screen
description: Review Habi's implemented Twig storefront against its canonical craft direction, semantic CSS roles and accessibility targets.
---

# Habi screen review

1. Read [DSD](../../../docs/DSD.md), [canonical CSS](../../../public/assets/css/app.css), and the changed Twig templates under `views/`. Separate M1 discovery/catalog and read-only bag inspection from later viewer, cart and checkout work.
2. Run the [README quickstart](../../../README.md). On `/`, skip the introduction and explore materials without shopping; follow the optional `/products` path. Exercise search, category, both price sorts, newest, pagination, reset and no results; follow a product link and inspect stock/fictional labels. Links must target implemented routes or anchors.
3. Inspect 360 px and wide layouts with keyboard navigation. Activate skip links, material disclosures, listing controls and pagination; verify visible focus, accessible names and 44 px targets. Light theme only; check reduced motion if motion is introduced. Measure actual text and necessary focus/control boundary contrast pairs; Lighthouse ≥90 alone is insufficient.
4. Trace aesthetic values to `app.css` primitives and semantic roles. Permit structural `auto`, zero and percentages; flag unsupported component variants and ad hoc colors. Check local fonts load and retain their shipped licenses. Do not publish supplied reference attachments.
5. Report observed defects with route/state, expected versus actual, severity, source/visual anchor and smallest correction. Distinguish design proposals and unrun checks from runtime findings. Preserve screenshot/action evidence outside fixture cleanup; do not change implementation during independent verification.
