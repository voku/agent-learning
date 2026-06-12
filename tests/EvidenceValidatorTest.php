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
}
