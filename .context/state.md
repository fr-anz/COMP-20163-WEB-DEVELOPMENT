# State

Updated: 2026-11-21.
Objective: M9 (#22) data model v2 on branch `feat/22-schema-v2` (from `dev`, uncommitted).
Progress: v2 schema + in-place v1 upgrade (`HeritageUpgrade`), native-fiber seed, placeholder weavers/articles, fictional transfer accounts, v1 code on v2 statuses/`listing_status`, per-fiber 3D textures. Docs: README, SDD §5/§8/§11/§15, BUILD M9 evidence. `dev` merged `main` (a80ba59).
Checks: phpunit OK (122); `composer cs`/`lint` exit 0; HTTP smoke on fresh DB; upgrade of a local DB copy. No browser visual check.
Blocker/next: owner visual check, commit, PR → `dev`. Open: M9 content review (placeholder stories/articles, empty sources); releasing to `main` migrates production (needs approval + backup). Open PRD §10: evaluation instrument, Filipino reviewer.
