# Context protocol

Read before writing a checkpoint or other context.

Checkpoint: reread state.md, then save objective, progress, actual checks, blockers, and next action. Preserve unfinished work; move older outcomes to linked history.

One home per fact; link instead of copying:
- knowledge.md: durable sourced findings with verification dates. Replace superseded entries; move stale ones to archive/. Never paste checkpoints.
- decisions.md: consequential choices with reason, date, source; mark replacements.
- preferences.md: explicit user preferences only.
- project.md: purpose, commands, and the "When to read" list.
- docs/ pipeline (if present): requirements live in PRD.md as R#; other docs cite IDs. When work changes a requirement, design, test, or milestone, update the owning doc. state.md names the current BUILD milestone.

Word budgets: project 200, preferences 80, state 120 (read every session); rules 150 (copied into adapters); protocol 150; knowledge 800 before archiving. Run `npx contextspine check` when available.
