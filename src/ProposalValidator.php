<?php

declare(strict_types=1);

namespace voku\AgentLearning;

use DateTimeImmutable;
use DateTimeInterface;

final class ProposalValidator
{
    public function __construct(
        private readonly ProposalParser $parser = new ProposalParser(),
        private readonly RedactionGuard $redactionGuard = new RedactionGuard(),
        private readonly ConstraintPromotionValidator $constraintPromotionValidator = new ConstraintPromotionValidator(),
    ) {
    }

    /**
     * @param array<string, Finding> $findingsById
     */
    public function validateFile(string $path, array $findingsById = []): Proposal
    {
        $proposal = $this->parser->parseFile($path);
        $this->validate($proposal, $path, null, $findingsById);

        return $proposal;
    }

    /**
     * @param array<string, Finding> $findingsById
     */
    public function validate(Proposal $proposal, string $file, ?int $line = null, array $findingsById = []): void
    {
        $this->redactionGuard->assertSafeValue($proposal->raw, $file, $line, $proposal->id);
        if (preg_match('/^proposal\.\d{4}-\d{2}-\d{2}\.\d{3}$/', $proposal->id) !== 1) {
            throw new ValidationException($file, $line, $proposal->id, 'proposal id must match proposal.YYYY-MM-DD.NNN');
        }

        if (DateTimeImmutable::createFromFormat(DateTimeInterface::ATOM, $proposal->createdAt) === false) {
            throw new ValidationException($file, $line, $proposal->id, 'malformed timestamp field: created_at');
        }

        foreach (['mutations', 'changes', 'targets'] as $field) {
            $value = $proposal->raw[$field] ?? null;
            if (is_array($value) && count($value) > 1) {
                throw new ValidationException($file, $line, $proposal->id, 'proposal contains several mutations in field: ' . $field);
            }
        }

        if ($proposal->sourceFindings === []) {
            throw new ValidationException($file, $line, $proposal->id, 'proposal requires source findings');
        }

        $this->assertActionStatusCombination($proposal, $file, $line);
        if ($proposal->constraint !== null && $proposal->targetType !== GuidanceType::CONSTRAINT->value) {
            throw new ValidationException($file, $line, $proposal->id, 'constraint specification requires target_type=constraint');
        }

        if ($proposal->action !== Action::NO_DURABLE_LEARNING) {
            if (
                $proposal->targetType === null
            ) {
                throw new ValidationException($file, $line, $proposal->id, 'durable proposal requires target_type and target');
            }

            if ($proposal->target === null && $proposal->targetType !== GuidanceType::CONSTRAINT->value) {
                throw new ValidationException($file, $line, $proposal->id, 'durable proposal requires target_type and target');
            }

            if ($proposal->scope === []) {
                throw new ValidationException($file, $line, $proposal->id, 'durable proposal requires exact scope');
            }

            if (
                $proposal->constraint === null
                &&
                (
                    $proposal->boundary === null
                    ||
                    trim($proposal->boundary) === ''
                )
            ) {
                throw new ValidationException($file, $line, $proposal->id, 'durable proposal requires known boundary');
            }

            if ($proposal->validation === []) {
                throw new ValidationException($file, $line, $proposal->id, 'durable proposal requires validation list');
            }
        }

        if (
            $proposal->action === Action::ADD
            &&
            $proposal->constraint === null
            &&
            (
                $proposal->new === null
                ||
                trim($proposal->new) === ''
            )
        ) {
            throw new ValidationException($file, $line, $proposal->id, 'ADD proposal requires new wording');
        }

        if (
            $proposal->action === Action::DELETE
            &&
            (
                $proposal->old === null
                ||
                trim($proposal->old) === ''
            )
        ) {
            throw new ValidationException($file, $line, $proposal->id, 'DELETE proposal requires old wording');
        }

        if (
            $proposal->action === Action::REPLACE
            &&
            (
                (
                    $proposal->old === null
                    ||
                    trim($proposal->old) === ''
                )
                ||
                (
                    $proposal->new === null
                    ||
                    trim($proposal->new) === ''
                )
            )
        ) {
            throw new ValidationException($file, $line, $proposal->id, 'REPLACE proposal requires old and new wording');
        }

        if (
            (
                $proposal->status === ProposalStatus::APPROVED
                ||
                $proposal->status === ProposalStatus::APPLIED
            )
            &&
            (
                $proposal->approvedBy === null
                ||
                $proposal->approvedAt === null
            )
        ) {
            throw new ValidationException($file, $line, $proposal->id, 'approved proposal requires approved_by and approved_at');
        }

        if (
            $proposal->approvedAt !== null
            &&
            DateTimeImmutable::createFromFormat(DateTimeInterface::ATOM, $proposal->approvedAt) === false
        ) {
            throw new ValidationException($file, $line, $proposal->id, 'malformed timestamp field: approved_at');
        }

        if (
            (
                $proposal->status === ProposalStatus::REJECTED
                ||
                $proposal->action === Action::REJECT
            )
            && trim($proposal->reason) === ''
        ) {
            throw new ValidationException($file, $line, $proposal->id, 'rejected proposal requires a reason');
        }

        $evidenceScope = [];
        foreach ($proposal->sourceFindings as $findingId) {
            $finding = $findingsById[$findingId] ?? null;
            if (
                $findingsById !== []
                &&
                !$finding instanceof Finding
            ) {
                throw new ValidationException($file, $line, $proposal->id, 'invalid reference to finding: ' . $findingId);
            }

            if ($finding instanceof Finding) {
                // Provenance: finding.2026-06-13.005 (consolidated source findings support)
                $isValidatedStatus = $finding->status === FindingStatus::VALIDATED
                                     ||
                                     $finding->status === FindingStatus::CONSOLIDATED;
                if (
                    !$isValidatedStatus
                    ||
                    $finding->validationStatus !== 'validated') {
                    throw new ValidationException($file, $line, $proposal->id, 'source finding is not validated: ' . $findingId);
                }

                foreach ($finding->scope as $scope) {
                    $evidenceScope[$scope] = true;
                }
            }
        }

        $this->constraintPromotionValidator->validate($proposal, $file, $line, $findingsById);
        foreach ($proposal->scope as $scope) {
            if (
                !isset($evidenceScope[$scope])
                &&
                $findingsById !== []
                &&
                trim((string)($proposal->raw['scope_justification'] ?? '')) === ''
            ) {
                throw new ValidationException($file, $line, $proposal->id, 'proposal scope is broader than source finding evidence without justification: ' . $scope);
            }
        }
    }

    public function assertCanBeApplied(Proposal $proposal, string $file): void
    {
        if ($proposal->status !== ProposalStatus::APPROVED) {
            throw new ValidationException($file, null, $proposal->id, 'candidate or non-approved proposal cannot be treated as active');
        }
    }

    private function assertActionStatusCombination(Proposal $proposal, string $file, ?int $line): void
    {
        $allowedStatuses = match ($proposal->action) {
            Action::ADD, Action::DELETE, Action::REPLACE => [
                ProposalStatus::CANDIDATE,
                ProposalStatus::APPROVED,
                ProposalStatus::REJECTED,
                ProposalStatus::APPLIED,
            ],
            Action::NO_DURABLE_LEARNING, Action::REJECT => [
                ProposalStatus::CANDIDATE,
                ProposalStatus::REJECTED,
            ],
        };

        if (!in_array($proposal->status, $allowedStatuses, true)) {
            throw new ValidationException(
                $file,
                $line,
                $proposal->id,
                'proposal action '
                . $proposal->action->value
                . ' cannot use status='
                . $proposal->status->value
            );
        }
    }
}
