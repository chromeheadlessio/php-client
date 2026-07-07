# Handoff — current state of all agents

Updated: 2026-07-07 (architect)

## Architect (Claude Code, pdf-export-service terminal)

- Reviewed php-client v1.8.0 + the export service; queued the v1.9.0
  resilience/speed batch (task-001..task-004) in tasks.json.
- Plan and constraints in `.agents/plan.md`. Wire contract is FROZEN — see
  AGENTS.md "Project facts".
- Will review each `done` task: run `bash tests/run-tests.sh` from clean
  state, read the diff, then archive via `bash scripts/archive-task.sh <id>`.

## builder-1 (OpenCode + DeepSeek, php-client terminal)

- Status: idle. Queue is EMPTY — batch complete.
- task-001..task-004 all REVIEWED and archived (2026-07-07). Architect
  applied 4 small fixes during review (see decisions.md) — do not re-do them.
- Next: architect handles the 1.9.0 release (CHANGELOG, manual, tag). No
  builder action until new tasks appear.
