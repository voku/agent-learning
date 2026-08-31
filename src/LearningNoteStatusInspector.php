<?php

declare(strict_types=1);

namespace voku\AgentLearning;

final readonly class LearningNoteStatusInspector
{
    public function __construct(private LearningProjectPaths $projectPaths = new LearningProjectPaths())
    {
    }

    public function inspect(string $root, LearningNote $note, ?string $projectRoot = null): LearningNoteStatusReport
    {
        if ($note->repositoryEvidence === []) {
            return new LearningNoteStatusReport($note->id, LearningNoteEvidenceState::NO_HASHABLE_EVIDENCE, []);
        }

        $resolvedProjectRoot = $this->projectPaths->projectRootForLearningRoot($root, $projectRoot);
        $checks = [];
        $overall = LearningNoteEvidenceState::CURRENT;
        foreach ($note->repositoryEvidence as $evidence) {
            $sourceRef = str_replace('\\', '/', trim($evidence->sourceRef));
            $path = rtrim($resolvedProjectRoot, '/\\') . '/' . $sourceRef;
            if (!is_file($path)) {
                $checks[] = new LearningNoteEvidenceCheck(
                    $sourceRef,
                    $evidence->contentSha256,
                    null,
                    LearningNoteEvidenceState::SOURCE_MISSING,
                );
                $overall = LearningNoteEvidenceState::SOURCE_MISSING;
                continue;
            }

            $actual = hash_file('sha256', $path);
            if (!is_string($actual)) {
                throw new ValidationException($path, null, $note->id, 'cannot hash LearningNote repository evidence');
            }
            $state = hash_equals($evidence->contentSha256, $actual)
                ? LearningNoteEvidenceState::CURRENT
                : LearningNoteEvidenceState::REVIEW_NEEDED;
            if ($state === LearningNoteEvidenceState::REVIEW_NEEDED && $overall === LearningNoteEvidenceState::CURRENT) {
                $overall = LearningNoteEvidenceState::REVIEW_NEEDED;
            }
            $checks[] = new LearningNoteEvidenceCheck($sourceRef, $evidence->contentSha256, $actual, $state);
        }

        return new LearningNoteStatusReport($note->id, $overall, $checks);
    }
}
