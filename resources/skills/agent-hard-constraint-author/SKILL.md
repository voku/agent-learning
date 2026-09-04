---
name: agent-hard-constraint-author
description: Turn validated agent-learning findings into human-approved executable constraints such as PHPStan rules, PHP-CS-Fixer rules, tests, or CI checks, with generation packages, manifests, fixtures, and validation evidence.
---

# Agent Hard Constraint Author

Use this skill when repeated learning should become an executable check. The point is to move stable behavior from prose into a rule that can fail validation.

## Fast Path

1. Start from validated findings. Require several independent findings or one clearly documented critical incident.
2. Define the objective violation, scope, allowed boundaries, detectability, false-positive risk, validation commands, target rule path, registration files, and example rule paths.
3. Export the constraint package before changing enforcement:

```bash
vendor/bin/agent-learning constraint-export \
  --root infra/doc/agent-learning \
  --proposal proposal.2026-06-15.001
```

4. Inspect `specification.json#mode`:
   - `generate`: implement the rule in the consuming project, not in this package, unless the rule is package-generic. Include valid, invalid, boundary, and false-positive fixtures where the engine supports fixtures, then register the rule in the consuming project's analyzer configuration.
   - `adopt_existing`: the configured target rule and registration files already exist. Do **not** generate duplicate enforcement or synthetic fixtures merely to satisfy the promotion path. Validate the existing enforcement against the approved constraint semantics and historical bad/good states using the exported validation commands.
5. Validate both directions appropriate to the selected mode. For generated rules, the invalid fixture must fail with the intended rule identifier and the valid fixture plus real target files must pass. For adopted enforcement, prove the historical bad state is rejected and a valid good state remains accepted by the existing target.
6. Record validation evidence and content hashes before marking the proposal applied.
7. Activate the manifest only after implementation/adoption and validation:

```bash
vendor/bin/agent-learning constraint-loop \
  --root infra/doc/agent-learning \
  proposal.2026-06-15.001 \
  --by agent \
  --commit working-tree \
  --validation infra/doc/agent-learning/validation-results/proposal.2026-06-15.001.json
```

## Quality Bar

- Prefer PHPStan or PHP-CS-Fixer when the violation is statically detectable.
- Keep rule identifiers stable and engine-valid.
- Make selection scope explicit; recall uses scope overlap, not semantic similarity.
- Do not activate generated or adopted constraints silently.
- Do not accept `adopt_existing`, file existence, or exit code alone as proof; inspect the existing enforcement and its historical bad/good behavior.
