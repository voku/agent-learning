<?php

declare(strict_types=1);

namespace voku\AgentLearning\Tests;

use PHPUnit\Framework\TestCase;
use voku\AgentLearning\EvidenceValidator;
use voku\AgentLearning\ValidationException;

final class EvidenceValidatorTest extends TestCase
{
    public function testEmptyEvidenceThrows(): void
    {
        $validator = new EvidenceValidator();
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('finding requires evidence');
        $validator->validate([], 'finding.json', 1, 'finding.1');
    }

    public function testUnsupportedEvidenceTypeThrows(): void
    {
        $validator = new EvidenceValidator();
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('unsupported evidence type at index 0');
        $validator->validate([['type' => 'magic_proof']], 'finding.json', 1, 'finding.1');
    }

    public function testFileReferenceValidatesSuccessfully(): void
    {
        $validator = new EvidenceValidator();
        $evidence = [[
            'type' => 'file_reference',
            'path' => 'src/Cli.php',
            'line' => 42
        ]];
        $this->expectNotToPerformAssertions();
        $validator->validate($evidence, 'finding.json', 1, 'finding.1');
    }

    public function testFileReferenceMissingPathThrows(): void
    {
        $validator = new EvidenceValidator();
        $evidence = [[
            'type' => 'file_reference',
            'line' => 42
        ]];
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('evidence index 0 requires non-empty string field: path');
        $validator->validate($evidence, 'finding.json', 1, 'finding.1');
    }

    public function testFileReferenceInvalidLineThrows(): void
    {
        $validator = new EvidenceValidator();
        $evidence = [[
            'type' => 'file_reference',
            'path' => 'src/Cli.php',
            'line' => 0
        ]];
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('file_reference evidence requires positive integer line at index 0');
        $validator->validate($evidence, 'finding.json', 1, 'finding.1');
    }

    public function testTestResultValidatesSuccessfully(): void
    {
        $validator = new EvidenceValidator();
        $evidence = [[
            'type' => 'test_result',
            'command' => 'vendor/bin/phpunit',
            'summary' => 'All tests passed'
        ]];
        $this->expectNotToPerformAssertions();
        $validator->validate($evidence, 'finding.json', 1, 'finding.1');
    }

    public function testTestResultMissingCommandThrows(): void
    {
        $validator = new EvidenceValidator();
        $evidence = [[
            'type' => 'test_result',
            'summary' => 'All tests passed'
        ]];
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('evidence index 0 requires non-empty string field: command');
        $validator->validate($evidence, 'finding.json', 1, 'finding.1');
    }

    public function testCommitValidatesSuccessfully(): void
    {
        $validator = new EvidenceValidator();
        $evidence = [[
            'type' => 'commit',
            'commit' => 'a1b2c3d4'
        ]];
        $this->expectNotToPerformAssertions();
        $validator->validate($evidence, 'finding.json', 1, 'finding.1');
    }

    public function testReviewCommentValidatesSuccessfully(): void
    {
        $validator = new EvidenceValidator();
        $evidence = [[
            'type' => 'review_comment',
            'reference' => 'comment-123'
        ]];
        $this->expectNotToPerformAssertions();
        $validator->validate($evidence, 'finding.json', 1, 'finding.1');
    }

    public function testIssueReferenceValidatesSuccessfully(): void
    {
        $validator = new EvidenceValidator();
        $evidence = [[
            'type' => 'issue_reference',
            'issue' => 'GH-426'
        ]];
        $this->expectNotToPerformAssertions();
        $validator->validate($evidence, 'finding.json', 1, 'finding.1');
    }

    public function testDefaultSummaryTypesValidateSuccessfully(): void
    {
        $validator = new EvidenceValidator();
        $evidence = [
            [
                'type' => 'manual_verification',
                'summary' => 'Verified by hand'
            ],
            [
                'type' => 'runtime_observation',
                'summary' => 'Observed CPU spike'
            ],
            [
                'type' => 'schema_reference',
                'summary' => 'Matches database layout'
            ]
        ];
        $this->expectNotToPerformAssertions();
        $validator->validate($evidence, 'finding.json', 1, 'finding.1');
    }

    public function testAgentHistoryReferenceValidatesSuccessfully(): void
    {
        $validator = new EvidenceValidator();
        $evidence = [[
            'type' => 'agent_history_reference',
            'source' => 'ctx',
            'ctx_session_id' => 'ses_01hxyz',
            'ctx_event_id' => 'evt_01hxyz',
            'provider' => 'codex',
            'query' => 'session access auth context PHPStan failure',
            'retrieved_at' => '2026-07-04T10:15:00+02:00',
            'summary' => 'Previous session found direct session reads caused permission visibility drift.',
            'verification_status' => 'inspected',
        ]];

        $this->expectNotToPerformAssertions();
        $validator->validate($evidence, 'finding.json', 1, 'finding.1');
    }

    public function testAgentHistoryReferenceAllowsEventIdWithoutSessionId(): void
    {
        $validator = new EvidenceValidator();
        $evidence = [[
            'type' => 'agent_history_reference',
            'source' => 'ctx',
            'ctx_event_id' => 'evt_01hxyz',
            'query' => 'auth context',
            'retrieved_at' => '2026-07-04T10:15:00+02:00',
            'summary' => 'Reviewed event linked an older failed approach to this task.',
            'verification_status' => 'found',
        ]];

        $this->expectNotToPerformAssertions();
        $validator->validate($evidence, 'finding.json', 1, 'finding.1');
    }

    public function testAgentHistoryReferenceRequiresCtxSource(): void
    {
        $validator = new EvidenceValidator();
        $evidence = [$this->agentHistoryReference(['source' => 'other'])];

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('agent_history_reference evidence requires source=ctx at index 0');
        $validator->validate($evidence, 'finding.json', 1, 'finding.1');
    }

    public function testAgentHistoryReferenceRequiresTraceableCtxId(): void
    {
        $validator = new EvidenceValidator();
        $evidence = [$this->agentHistoryReference([
            'ctx_session_id' => '',
            'ctx_event_id' => '',
        ])];

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('agent_history_reference evidence requires ctx_session_id or ctx_event_id at index 0');
        $validator->validate($evidence, 'finding.json', 1, 'finding.1');
    }

    public function testAgentHistoryReferenceRequiresNonEmptyQuery(): void
    {
        $validator = new EvidenceValidator();
        $evidence = [$this->agentHistoryReference(['query' => ''])];

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('evidence index 0 requires non-empty string field: query');
        $validator->validate($evidence, 'finding.json', 1, 'finding.1');
    }

    public function testAgentHistoryReferenceRequiresValidRetrievedAt(): void
    {
        $validator = new EvidenceValidator();
        $evidence = [$this->agentHistoryReference(['retrieved_at' => 'yesterday'])];

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('agent_history_reference evidence requires valid ISO timestamp retrieved_at at index 0');
        $validator->validate($evidence, 'finding.json', 1, 'finding.1');
    }

    public function testAgentHistoryReferenceRejectsCalendarInvalidRetrievedAt(): void
    {
        $validator = new EvidenceValidator();
        $evidence = [$this->agentHistoryReference(['retrieved_at' => '2026-99-99T10:15:00+02:00'])];

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('agent_history_reference evidence requires valid ISO timestamp retrieved_at at index 0');
        $validator->validate($evidence, 'finding.json', 1, 'finding.1');
    }

    public function testAgentHistoryReferenceRejectsRetrievedAtWithTrailingData(): void
    {
        $validator = new EvidenceValidator();
        $evidence = [$this->agentHistoryReference(['retrieved_at' => '2026-07-04T10:15:00+02:00 trailing'])];

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('agent_history_reference evidence requires valid ISO timestamp retrieved_at at index 0');
        $validator->validate($evidence, 'finding.json', 1, 'finding.1');
    }

    public function testAgentHistoryReferenceRequiresSupportedVerificationStatus(): void
    {
        $validator = new EvidenceValidator();
        $evidence = [$this->agentHistoryReference(['verification_status' => 'validated'])];

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('agent_history_reference evidence has unsupported verification_status at index 0');
        $validator->validate($evidence, 'finding.json', 1, 'finding.1');
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function agentHistoryReference(array $overrides): array
    {
        return array_replace([
            'type' => 'agent_history_reference',
            'source' => 'ctx',
            'ctx_session_id' => 'ses_01hxyz',
            'ctx_event_id' => 'evt_01hxyz',
            'query' => 'session access auth context PHPStan failure',
            'retrieved_at' => '2026-07-04T10:15:00+02:00',
            'summary' => 'Previous session found direct session reads caused permission visibility drift.',
            'verification_status' => 'inspected',
        ], $overrides);
    }
}
