# ctx Integration

`ctx` is an optional evidence discovery tool for local coding-agent history. It indexes persisted agent sessions into a local SQLite database and exposes search, show, locate, and SQL inspection workflows. `agent-learning` does not install `ctx`, run `ctx setup`, own the SQLite database, or call the `ctx` CLI.

Use this boundary:

```text
ctx = raw historical evidence retrieval
agent-learning = validated findings, proposals, and decisions
```

Do not use this boundary:

```text
ctx = durable memory
```

## When to Use ctx

Use `ctx` before writing or validating an agent-learning finding when prior agent sessions may contain relevant decisions, failed attempts, commands, review context, or earlier validation history.

The ctx repo provides the `ctx-agent-history-search` skill for searching local coding-agent history before acting. Use that skill for the ctx workflow itself; this package only stores bounded references to reviewed ctx results.

## Evidence Workflow

1. Check the local index with `ctx status` and `ctx sources`.
2. Use `ctx` to find previous sessions.
3. Inspect the focused result with `ctx show` or `ctx locate`.
4. Extract only bounded evidence into the finding.
5. Record the ctx IDs in the finding.
6. Keep the observation and hypothesis separate.
7. Validate the finding with normal project evidence before promoting durable guidance.

Example commands:

```bash
ctx status
ctx sources
ctx search "<task / failure / module / command>"
ctx show event <ctx-event-id> --window 5
ctx locate event <ctx-event-id>
```

Do not paste raw transcripts into findings. Do not treat ctx output as validated project memory. Do not automatically create proposals from ctx matches. Do not promote ctx results into `MEMORY.md` or skills without the normal proposal and decision lifecycle.

## Evidence Shape

Use `agent_history_reference` for reviewed ctx-backed evidence:

```json
{
  "type": "agent_history_reference",
  "source": "ctx",
  "ctx_session_id": "ses_01h...",
  "ctx_event_id": "evt_01h...",
  "provider": "codex",
  "query": "session access auth context PHPStan failure",
  "retrieved_at": "2026-07-04T10:15:00+02:00",
  "summary": "Previous session found that service-layer direct session reads caused permission visibility drift.",
  "verification_status": "inspected"
}
```

Required fields:

- `type`: must be `agent_history_reference`
- `source`: must be `ctx`
- `query`: non-empty search query or lookup expression
- `retrieved_at`: ISO 8601 timestamp for when the ctx result was retrieved
- `summary`: bounded human- or agent-reviewed summary
- `ctx_session_id` or `ctx_event_id`: at least one traceable ctx identifier
- `verification_status`: one of `found`, `inspected`, `rejected`, or `stale`

Optional fields:

- `provider`: provider name such as `codex`

## Verification Status

- `found`: ctx returned a result, but it has not been trusted.
- `inspected`: the event or session was opened and reviewed.
- `rejected`: the result was checked and found irrelevant or wrong.
- `stale`: the result references old context that may no longer apply.

Only `inspected` should count as supporting evidence for validation-heavy proposals. Other statuses may still help explain search history, rejected paths, or context that should be checked against the current repository.

## Privacy and Redaction

ctx results may contain private paths, transcript text, or secret-shaped strings. Review copied output before sharing it or committing it. Store bounded summaries and ctx IDs, not full transcripts, large JSON payloads, tokens, secrets, or private-path-heavy excerpts.

## Consolidation Behavior

Consolidation prompts render `agent_history_reference` evidence as untrusted historical source material. They include only the bounded fields needed for traceability:

- source
- query
- ctx session ID
- ctx event ID
- provider
- reviewed summary
- verification status

Full transcript text is not rendered by default.
