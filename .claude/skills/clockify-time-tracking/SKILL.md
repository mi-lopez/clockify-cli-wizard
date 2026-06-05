---
name: clockify-time-tracking
description: Track billable time in Clockify from the command line, non-interactively, with clockify-wizard. Use when an agent needs to discover Clockify projects/tasks/tags, mirror a Jira ticket into Clockify as a task, or register time spent on a task (either by logging a measured duration or by running a live start/stop/pause/resume timer). All commands support --json for machine-readable output.
---

# clockify-time-tracking

Drive the `clockify-wizard` CLI to register **billable time** in Clockify without
any interactive prompt. Every command below accepts `--json` and prints a single
JSON object (errors as `{"error": "..."}` with a non-zero exit code).

## Golden rules for agents

- Always pass `--json`. Parse stdout as JSON; check the exit code.
- The Clockify **project must be resolvable** in non-interactive mode: pass
  `--project "<id|name>"` or rely on a saved Jira→Clockify mapping. If neither
  exists the command fails (it never prompts) — fix it with `map` (below).
- Use `--dry-run` first when unsure; it shows the payload **without** writing
  time (avoids double-billing).
- One unit of billable work = one Clockify **task** (named `"KEY summary"`,
  derived from the Jira ticket). Time is logged against that task.

## 1. Discover what exists (`list`)

```bash
clockify-wizard list projects                 # [{id,name,clientName,archived}]
clockify-wizard list tasks --project "API"    # [{id,name,status,projectId}]
clockify-wizard list tags                     # [{id,name}]
clockify-wizard list workspaces               # [{id,name}]
clockify-wizard list current                  # running timer, or {"running":false}
```

## 2. Make the project resolvable once (`map`)

```bash
clockify-wizard map CAM "My Clockify Project"   # persist CAM → that project
clockify-wizard map                              # list current mappings
```

After this, any `CAM-###` ticket resolves its project automatically.

## 3. Mirror a Jira ticket into Clockify (`create-task`) — PM flow

Idempotent: returns the existing task if it already exists (`"existed": true`).

```bash
clockify-wizard create-task CAM-451 --json
clockify-wizard create-task CAM-451 --project "My Clockify Project" --json
# → {"id":"...","name":"CAM-451 ...","projectId":"...","existed":false, ...}
```

## 4. Register time — two flows (both billable)

### A. Measured duration (recommended for agents)

The agent measures how long the task took, then logs that explicit duration.
No timer can be left running.

```bash
clockify-wizard log 1h30m --task CAM-451 --json
clockify-wizard log 45m --task CAM-451 --tags "ai-agent,backend" --json
clockify-wizard log 2h --task CAM-451 --dry-run        # preview, no write
```

### B. Live timer (real wall-clock)

```bash
clockify-wizard start CAM-451 --tags "ai-agent" --json   # begin
clockify-wizard stop --json                              # end
clockify-wizard start CAM-451 --force --json             # replace a running timer
```

Clockify has **no native pause** — `pause` stops the timer and remembers its
context so `resume` can restart it:

```bash
clockify-wizard pause --json
clockify-wizard resume --json            # restart the paused one
clockify-wizard resume --task CAM-451 --json   # or start fresh for a ticket
```

`--tags` is a comma-separated list of labels; missing tags are created.

## Combined recipe: Jira ticket → Clockify task → time

```bash
KEY=$(jira-wizard create --project CAM --type Task --summary "New endpoint")
clockify-wizard create-task "$KEY" --json     # mirror into Clockify
clockify-wizard log 2h --task "$KEY" --json   # bill the time
```

## Common errors

- `Could not resolve a Clockify project for ...` → run `clockify-wizard map <KEY> "<project>"` once, or pass `--project`.
- `A timer is already running ...` → `stop`/`pause` it, or pass `--force` to `start`/`resume`.
- `Clockify CLI is not configured` → run `clockify-wizard configure`.
