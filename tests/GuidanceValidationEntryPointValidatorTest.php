<?php

declare(strict_types=1);

namespace voku\AgentLearning\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use voku\AgentLearning\Cli;
use voku\AgentLearning\GuidanceValidationEntryPointValidator;
use voku\AgentLearning\LearningRepositoryValidator;
use voku\AgentLearning\ValidationException;

final class GuidanceValidationEntryPointValidatorTest extends TestCase
{
    private string $project;

    protected function setUp(): void
    {
        $this->project = sys_get_temp_dir() . '/guidance-entry-point-test-' . bin2hex(random_bytes(8));
        $learning = $this->project . '/.agent-loop/learning';
        mkdir($learning . '/findings/validated', 0777, true);
        mkdir($learning . '/proposals/approved', 0777, true);
        mkdir($learning . '/proposals/candidate', 0777, true);
        mkdir($this->project . '/tools', 0777, true);
        file_put_contents($learning . '/config.json', '{"schema_version":"1.0","project_root":"../.."}');
        copy(__DIR__ . '/fixtures/findings/finding.2026-06-08.001.json', $learning . '/findings/validated/finding.2026-06-08.001.json');
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->project));
    }

    public function testValidateCommandFailsWhenApprovedGuidanceNamesMissingScript(): void
    {
        $this->writeProposal('approved', ['bash tools/gone-script.sh']);

        self::assertSame(1, $this->validateExitCode());
    }

    public function testValidateCommandPassesWhenScriptExists(): void
    {
        file_put_contents($this->project . '/tools/live.php', '<?php');
        $this->writeProposal('approved', ['php tools/live.php --check']);

        self::assertSame(0, $this->validateExitCode());
    }

    public function testCandidateProposalIsNotChecked(): void
    {
        $this->writeProposal('candidate', ['bash tools/gone-script.sh']);

        self::assertSame(0, $this->validateExitCode());
    }

    public function testMissingScriptNamesProposalAndEntryPoint(): void
    {
        $this->writeProposal('approved', ['bash tools/gone-script.sh']);
        $result = (new LearningRepositoryValidator())->validate($this->learningRoot());

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('proposal.2026-06-08.001: validation command references missing project-local entry point: tools/gone-script.sh');

        (new GuidanceValidationEntryPointValidator())->validate($this->learningRoot(), $result->proposalsById);
    }

    public function testLibraryValidationStaysReadableWhenAScriptIsGone(): void
    {
        $this->writeProposal('approved', ['bash tools/gone-script.sh']);

        $result = (new LearningRepositoryValidator())->validate($this->learningRoot());

        self::assertCount(1, $result->proposalsById);
    }

    #[DataProvider('commands')]
    public function testProjectLocalEntryPoint(string $command, ?string $expected): void
    {
        self::assertSame($expected, GuidanceValidationEntryPointValidator::projectLocalEntryPoint($command));
    }

    /**
     * @return iterable<string, array{string, string|null}>
     */
    public static function commands(): iterable
    {
        yield 'bash script' => ['bash tools/self-shape-dogfood.sh', 'tools/self-shape-dogfood.sh'];
        yield 'php script with args' => ['php tools/run.php --flag', 'tools/run.php'];
        yield 'leading ./' => ['sh ./tools/run.sh', 'tools/run.sh'];
        yield 'bare php file' => ['php check.php', 'check.php'];
        yield 'bare word is not a script' => ['php artisan', null];
        yield 'vendor binary' => ['php vendor/bin/phpunit', null];
        yield 'option' => ['php -d memory_limit=1G', null];
        yield 'traversal' => ['php ../outside.php', null];
        yield 'prose' => ['Run the package PHPUnit suite.', null];
        yield 'composer script' => ['composer ci', null];
    }

    private function learningRoot(): string
    {
        return $this->project . '/.agent-loop/learning';
    }

    private function validateExitCode(): int
    {
        return (new Cli())->run(['agent-learning', 'validate', '--root', $this->learningRoot()]);
    }

    /**
     * @param list<string> $validation
     */
    private function writeProposal(string $status, array $validation): void
    {
        $proposal = json_decode((string) file_get_contents(__DIR__ . '/fixtures/proposals/proposal.2026-06-08.001.json'), true);
        self::assertIsArray($proposal);
        $proposal['status'] = $status;
        $proposal['validation'] = $validation;
        if ($status === 'candidate') {
            $proposal['approved_by'] = null;
            $proposal['approved_at'] = null;
        }
        file_put_contents(
            $this->project . '/.agent-loop/learning/proposals/' . $status . '/proposal.2026-06-08.001.json',
            json_encode($proposal),
        );
    }
}
