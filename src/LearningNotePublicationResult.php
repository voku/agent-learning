<?php

declare(strict_types=1);

namespace voku\AgentLearning;

final readonly class LearningNotePublicationResult
{
    public function __construct(
        public LearningNote $note,
        public string $path,
        public string $digest,
    ) {
    }
}
