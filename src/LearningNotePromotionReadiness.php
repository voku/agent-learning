<?php

declare(strict_types=1);

namespace voku\AgentLearning;

/**
 * Whether one Finding can become a LearningNote, and what is missing if not.
 *
 * `prepare()` already answered this, but only by throwing on the first thing it
 * disliked, which is useful to a caller that has decided to promote and useless
 * to one asking whether promotion is even possible. A store can accumulate
 * validated Findings for months while every one of them is unpromotable, and
 * without a read-only answer nothing surfaces that: the findings look like
 * durable knowledge and behave like a write-only log.
 *
 * The blockers are reported together rather than one at a time, because a
 * Finding that needs three fields is a different amount of work from one that
 * needs a single classification.
 */
final readonly class LearningNotePromotionReadiness
{
    /** The Finding was not found in the Learning root. */
    public const string BLOCKER_MISSING = 'finding_missing';

    /** Only validated or consolidated Findings may source a note. */
    public const string BLOCKER_STATUS = 'status_not_promotable';

    public const string BLOCKER_CLASSIFICATION = 'classification_not_add_learning_note';

    public const string BLOCKER_PATTERN_KEY = 'pattern_key_missing';

    public const string BLOCKER_VALIDATION_CASE = 'validation_case_missing';

    public const string BLOCKER_VALIDATED_CONCLUSION = 'validated_conclusion_missing';

    /** @param list<string> $blockers in the order `prepare()` would reject them */
    public function __construct(
        public string $findingId,
        public bool $promotable,
        public array $blockers,
        public ?string $patternKey = null,
    ) {
    }

    /**
     * @return array{finding_id: string, promotable: bool, blockers: list<string>, pattern_key: string|null}
     */
    public function toArray(): array
    {
        return [
            'finding_id' => $this->findingId,
            'promotable' => $this->promotable,
            'blockers' => $this->blockers,
            'pattern_key' => $this->patternKey,
        ];
    }
}
