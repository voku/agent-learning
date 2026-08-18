# PHP embedding

`agent-learning` owns durable Learning records. PHP lifecycle hosts should call its typed owner APIs instead of invoking CLI commands in-process or reproducing storage schemas.

## Validated Finding creation

Use `FindingCreator` when a host has evidence-backed Finding content and needs one schema-valid durable Finding:

```php
<?php

declare(strict_types=1);

use voku\AgentLearning\FindingCreator;

$result = (new FindingCreator())->createValidated(
    root: $learningRoot,
    taskId: $taskId,
    session: $sessionId,
    createdBy: $actor,
    scope: ['src/'],
    observation: $observation,
    evidence: $evidence,
    hypothesis: $hypothesis,
    validatedConclusion: $validatedConclusion,
    confidence: 'high',
    sensitivity: 'public',
);
```

The owner operation allocates an ID when none is supplied, validates the complete Finding before publication, creates the target directory when needed, rejects duplicate IDs, and publishes atomically. Callers do not need to know the `findings/validated/*.json` layout.

The API intentionally does not decide that a Finding should exist, manufacture evidence, infer a validated conclusion, approve durable guidance, or turn every task into Learning. Those remain caller or human/model judgment boundaries.

## Host boundary

For ordinary orchestration:

- use typed owner APIs for durable mutations;
- use CLI commands as human/script adapters, not as the PHP package boundary;
- let validation exceptions remain explicit failures;
- do not partially persist records after validation failure;
- keep `NO_DURABLE_LEARNING` a valid explicit lifecycle outcome when no reusable Finding is justified.

This lets a lifecycle host own *when* close-out happens while `agent-learning` continues to own *what valid Learning records mean*.
