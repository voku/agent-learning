# Read-only applied-guidance maintenance evidence

`AppliedGuidanceMaintenanceInspector::inspect($beforeRoot, $afterRoot, $targetSourceRef)` accepts two **independent, owner-validated Learning snapshots** and produces an immutable `AppliedGuidanceMaintenanceEvidence`. Both target files must exist inside their own project roots and have different SHA-256 hashes. All proposal identities and reviewed approval/application data are compared, and every applied proposal on the named target must have updated physical proof with reanchor attribution. No state is written.

This evidence is **not** a semantic-equivalence verdict. A matching target hash and preserved approved proposal wording say nothing about *unrelated* sentences in the same `MEMORY.md`. The `reanchored_by` string is an unauthenticated attribution, not human approval. Consumer workflows must independently require an explicit human-reviewed verdict bound to the exact old/new source diff and must not conclude `no_durable_learning` from this owner projection alone. An existing but unrelated referenced path is not necessarily the canonical owner.

The reanchor history JSONL is often ignored in consumer checkouts. This API does not invent such history or claim to authenticate it. It validates available Learning evidence via the existing owner validator, checks cross-snapshot changes, and fails closed when its own invariants cannot be established.

Related: voku/agent-learning#159 and voku/agent-loop#761 (real consumer case #758).
