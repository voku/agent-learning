---
name: agent-learning-maintenance
description: Capture, validate, and transition package-specific findings and proposals for voku/agent-learning.
---

# Agent Learning Maintenance Skill

Use this skill to govern the learning lifecycle within the `voku/agent-learning` repository itself.

## Workflow

### 1. Capture Bounded Findings
*   When a bug, regression, or validation gap is resolved in this repository:
    *   Create a candidate finding under `infra/doc/agent-learning/findings/candidate/` using the template format.
    *   Anchor the finding with a concrete `evidence` item (e.g. referencing the specific failing PHPUnit test or PHPStan output).
    *   Set `validation_status` to `unverified` and `status` to `candidate`.

### 2. Transition Findings to Validated
*   Verify the fix or validation by running tests or phpstan.
*   Once confirmed, transition the finding to `validated` using the transition command:
    ```bash
    make agent_learning_finding_transition FINDING=finding.YYYY-MM-DD.NNN STATUS=validated BY=<name>
    ```
    This updates both `status` to `validated` and `validation_status` to `validated` automatically, moving the file to `findings/validated/`.

### 3. Run Repository Validation
*   Always run validation after any transition or manual file edit:
    ```bash
    make agent_learning_validate
    ```
    This verifies ID formatting, lifecycle combinations, and referential integrity across findings and proposals.
