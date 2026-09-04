# Security policy

## Reporting a vulnerability

Please open a private security advisory on GitHub or contact the maintainer
directly at lars@moelleken.org rather than filing a public issue if the
report includes vulnerability or exploit details.

## What this package does to stay safe by default

- **Redaction and Sensitivity**: Findings and proposals enforce sensitivity
  levels and sanitization to prevent sensitive repository or personal data
  from leaking into learning artifacts or operational prompts.
- **Path Traversal Protection**: Finding IDs and proposal filenames are validated
  strictly against collision-resistant alphanumeric patterns before resolving
  filesystem paths.
- **Auditable Decision History**: Transitions between candidate, approved,
  applied, and retired states are strictly recorded in immutable history
  projections (`history/*.jsonl`) preventing unverified or retroactive guidance.
- **Contextual Exceptions**: No silent error swallowing or `@` suppression;
  failures produce contextual exceptions.

## Supported versions

This project is pre-1.0; only the latest commit on the default branch
receives security fixes until a 1.0.0 stability policy is published.
