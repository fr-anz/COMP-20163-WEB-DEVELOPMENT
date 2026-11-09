---
name: review-screen
description: Review Habi's implemented Twig storefront against its canonical craft direction, semantic CSS roles and accessibility targets.
---

# Habi screen review

1. Read [DSD](../../../docs/DSD.md), [canonical CSS](../../../public/assets/css/app.css), and the changed Twig templates under `views/`. Separate M1 discovery/catalog and M2 exterior inspection from M3 interiors and M4 commerce.
2. Run the [README quickstart](../../../README.md). Exercise the changed consumer surface, not unrelated milestones. On discovery/catalog changes, skip the introduction, explore materials without shopping, and exercise filters/pagination/reset/no results. On product changes, inspect gallery/3D switching, all four shapes, stock/fictional labels and disabled M4 purchase controls; verify loading, fetch failure/retry and WebGL static fallback.
3. Inspect 360 px and wide layouts with keyboard navigation. Verify visible focus, accessible names and 44 px targets. Product inspection must work without dragging; check pause/resume, reduced motion, mobile dialog focus containment, close/Esc/focus return and desktop inline viewing. Light theme only. Measure actual text and necessary focus/control boundary contrast pairs; a Lighthouse score alone is insufficient.
4. Trace aesthetic values to `app.css` primitives and semantic roles. Permit structural `auto`, zero and percentages; flag unsupported component variants and ad hoc colors. Check local fonts load and retain their shipped licenses. Do not publish supplied reference attachments.
5. Report observed defects with route/state, expected versus actual, severity, source/visual anchor and smallest correction. Distinguish design proposals and unrun checks from runtime findings. Preserve screenshot/action evidence outside fixture cleanup; do not change implementation during independent verification.
