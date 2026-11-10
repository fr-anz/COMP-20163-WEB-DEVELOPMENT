# State

Updated: 2026-11-10.
Objective: D7 + D8 pushed on branch `d7-d8-story-catalog-3d` (commit db5e07a); no PR opened yet.
Progress: D7 done. D8: catalog = Sinag Tote, Lupa Satchel, Tala Sling, Maya Clutch (6 SVGs removed); tests use fixture rows for pagination. bagFactory.js rebuilt from SVGs via optional `three_config.pattern` (checker/stripes/pixel) + `colors.handle*`; tala handle_style → top_handle (sling now allows it); admin update keeps unmanaged config keys (new test). viewer3d.js: PMREM studio env, retuned lights. Docs: PRD N7, SDD (catalog + pattern key), USER-JOURNEY, README.
Checks: PHPUnit 119 pass, cs clean; browser screenshots of all 4 bags exterior + Peek after Sol 6.1 rebuild (Peek keeps shell; tote handles fold outward; clutch flap hinged), no console errors.
Blocker/next: Tala listed dims now 30×22×8, Maya 26×16×3 (match models + illustrations). Product descriptions still describe old looks. code subagent override → openai-codex/gpt-6.1-sol:high is session-only (unsaved). User review; open PR into main (merging deploys via CI). M8 runbook pending.
