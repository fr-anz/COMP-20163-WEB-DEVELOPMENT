---
name: review-screen
description: Review Habi's implemented Twig shell against its canonical craft direction, semantic CSS roles and accessibility targets.
---

# Habi screen review

1. Read [DSD](../../../docs/DSD.md), [canonical CSS](../../../public/assets/css/app.css), and the changed Twig templates under `views/`. Separate implemented M0 shell from planned catalog, intro, viewer and commerce APIs; do not treat later milestones as shell defects.
2. Run the [README quickstart](../../../README.md), open the actual route, and confirm educational/fictional labels and real link destinations. Current M0 route is `/`; shell links must target existing content, not future commerce routes.
3. Inspect 360 px and wide layouts, long text, and keyboard navigation. Activate the skip link, verify visible focus, accessible names and 44 px targets. Light theme only; check reduced motion if motion is introduced. Measure actual text and necessary focus/control boundary contrast pairs; Lighthouse ≥90 alone is insufficient.
4. Trace aesthetic values to `app.css` primitives and semantic roles. Permit structural `auto`, zero and percentages; flag unsupported component variants and ad hoc colors. Check local fonts load and retain their shipped licenses. Do not publish supplied reference attachments.
5. Report observed defects with route/state, expected versus actual, severity, source/visual anchor and smallest correction. Distinguish design proposals and unrun checks from runtime findings. Preserve screenshot/action evidence outside fixture cleanup; do not change implementation during independent verification.
