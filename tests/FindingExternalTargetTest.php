<?php

declare(strict_types=1);

namespace voku\AgentLearning\Tests;

use PHPUnit\Framework\TestCase;
use voku\AgentLearning\FindingParser;
use voku\AgentLearning\FindingValidator;
use voku\AgentLearning\ValidationException;

final class FindingExternalTargetTest extends TestCase
{
    public function testExistingFindingWithoutExternalTargetRemainsValid(): void
    {
        $finding = (new FindingParser())->parseRecord($this->record(), 'finding.json');

        self::assertNull($finding->targetPackage);
        self::assertNull($finding->testedVersion);
        self::assertNull($finding->testedCommit);
        (new FindingValidator())->validate($finding, 'finding.json');
    }

    public function testExternalTargetIdentityIsParsedAndValidated(): void
    {
        $record = $this->record();
        $record['target_package'] = 'voku/agent-loop';
        $record['tested_version'] = '0.16.3';
        $record['tested_commit'] = '629fd126a0cb6e1933c2bd6cf2825423a568caa0';
        $finding = (new FindingParser())->parseRecord($record, 'finding.json');

        self::assertSame('voku/agent-loop', $finding->targetPackage);
        self::assertSame('0.16.3', $finding->testedVersion);
        self::assertSame('629fd126a0cb6e1933c2bd6cf2825423a568caa0', $finding->testedCommit);
        (new FindingValidator())->validate($finding, 'finding.json');
    }

    public function testTestedIdentityCannotExistWithoutTargetPackage(): void
    {
        $record = $this->record();
        $record['tested_version'] = '0.16.3';
        $finding = (new FindingParser())->parseRecord($record, 'finding.json');

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('require target_package');
        (new FindingValidator())->validate($finding, 'finding.json');
    }

    public function testMalformedTargetPackageFailsValidation(): void
    {
        $record = $this->record();
        $record['target_package'] = 'Voku Agent Loop';
        $finding = (new FindingParser())->parseRecord($record, 'finding.json');

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('lowercase vendor/package');
        (new FindingValidator())->validate($finding, 'finding.json');
    }

    public function testMalformedTestedCommitFailsValidation(): void
    {
        $record = $this->record();
        $record['target_package'] = 'voku/agent-loop';
        $record['tested_commit'] = 'not-a-commit';
        $finding = (new FindingParser())->parseRecord($record, 'finding.json');

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Git commit hash');
        (new FindingValidator())->validate($finding, 'finding.json');
    }

    /** @return array<string, mixed> */
    private function record(): array
    {
        return [
            'id' => 'finding.2026-08-16.001',
            'task_id' => 'HTTPFUL-1',
            'session' => 'session-httpful-1',
            'created_at' => '2026-08-16T00:30:00+02:00',
            'created_by' => 'dogfood-agent',
            'scope' => ['tools/agent-loop'],
            'observation' => 'The projected CLI path does not work for the isolated tool install.',
            'evidence' => [[
                'type' => 'runtime_observation',
                'summary' => 'The documented command failed while the isolated tool binary succeeded.',
            ]],
            'hypothesis' => 'Projected assets assume the default root vendor layout.',
            'validated_conclusion' => 'The projected asset hardcodes a CLI path that is invalid for the documented isolated tool layout.',
            'confidence' => 'high',
            'validation_status' => 'validated',
            'status' => 'validated',
            'sensitivity' => 'public',
        ];
    }
}
