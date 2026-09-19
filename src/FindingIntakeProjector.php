<?php

declare(strict_types=1);

namespace voku\AgentLearning;

/**
 * Read-only owner projection for the human Finding intake path.
 *
 * It describes supported interactions without choosing one for the caller.
 */
final readonly class FindingIntakeProjector
{
    public function __construct(
        private FindingLifecycle $lifecycle = new FindingLifecycle(),
    ) {
    }

    public function project(Finding $finding): FindingIntakeProjection
    {
        $actions = [];

        if ($finding->status === FindingStatus::CANDIDATE) {
            foreach ($this->lifecycle->allowedTransitions($finding->status) as $target) {
                $actions[] = match ($target) {
                    FindingStatus::VALIDATED => [
                        'id' => FindingIntakeProjection::ACTION_REVIEW_VALIDATE,
                        'requires' => ['actor', 'conclusion'],
                    ],
                    FindingStatus::INVALIDATED => [
                        'id' => FindingIntakeProjection::ACTION_REVIEW_INVALIDATE,
                        'requires' => ['actor'],
                    ],
                    FindingStatus::REJECTED => [
                        'id' => FindingIntakeProjection::ACTION_REVIEW_REJECT,
                        'requires' => ['actor'],
                    ],
                    default => throw new \LogicException(
                        'Candidate intake exposes an unsupported transition: ' . $target->value,
                    ),
                };
            }
        } elseif (
            $finding->status === FindingStatus::VALIDATED
            && $finding->classification === null
        ) {
            $actions[] = [
                'id' => FindingIntakeProjection::ACTION_CLASSIFY,
                'requires' => ['classification'],
            ];
        }

        return new FindingIntakeProjection(
            status: $finding->status,
            validationStatus: $finding->validationStatus,
            classification: $finding->classification,
            availableActions: $actions,
        );
    }
}
