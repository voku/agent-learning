<?php

declare(strict_types=1);

namespace voku\AgentLearning;

enum LearningNoteEvidenceState: string
{
    case CURRENT = 'current';
    case REVIEW_NEEDED = 'review_needed';
    case SOURCE_MISSING = 'source_missing';
    case NO_HASHABLE_EVIDENCE = 'no_hashable_evidence';
}
