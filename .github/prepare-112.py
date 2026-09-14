from pathlib import Path


def replace(path: str, old: str, new: str) -> None:
    file = Path(path)
    text = file.read_text()
    if old not in text:
        raise SystemExit(f"expected block not found in {path}")
    file.write_text(text.replace(old, new, 1))


replace(
    "src/FindingClassifier.php",
    """        if ($classification === LearningClassification::IGNORE) {
            unset($raw['pattern_key'], $raw['validation_case']);
        } else {
            if ($patternKey === null || trim($patternKey) === '') {
                throw new ValidationException($path, null, $findingId, 'classified learning requires pattern_key');
            }
            if (!$validationCase instanceof ValidationCase) {
                throw new ValidationException($path, null, $findingId, 'classified learning requires validation_case');
            }

            $raw['pattern_key'] = trim($patternKey);
            $raw['validation_case'] = $validationCase->toArray();
        }
""",
    """        $hasLineageMetadata = $patternKey !== null || $validationCase instanceof ValidationCase;
        if ($classification === LearningClassification::IGNORE && !$hasLineageMetadata) {
            unset($raw['pattern_key'], $raw['validation_case']);
        } else {
            if ($patternKey === null || trim($patternKey) === '') {
                throw new ValidationException($path, null, $findingId, 'classified learning requires pattern_key');
            }
            if (!$validationCase instanceof ValidationCase) {
                throw new ValidationException($path, null, $findingId, 'classified learning requires validation_case');
            }

            $raw['pattern_key'] = trim($patternKey);
            $raw['validation_case'] = $validationCase->toArray();
        }
""",
)

replace(
    "src/FindingValidator.php",
    """        if ($finding->classification === LearningClassification::IGNORE) {
            return;
        }

        if ($finding->patternKey === null || trim($finding->patternKey) === '') {
""",
    """        if ($finding->classification === LearningClassification::IGNORE) {
            $hasPatternKey = $finding->patternKey !== null && trim($finding->patternKey) !== '';
            $hasValidationCase = $finding->validationCase instanceof ValidationCase;
            if ($hasPatternKey !== $hasValidationCase) {
                throw new ValidationException(
                    $file,
                    $line,
                    $finding->id,
                    'IGNORE lineage requires pattern_key and validation_case together',
                );
            }

            return;
        }

        if ($finding->patternKey === null || trim($finding->patternKey) === '') {
""",
)

replace(
    "src/Cli.php",
    """        $patternKey = null;
        $validationCase = null;
        if ($classification !== LearningClassification::IGNORE) {
            $patternKey = $this->stringOption($parsed['options'], 'pattern-key');
            $given = $this->stringOption($parsed['options'], 'given');
            $when = $this->stringOption($parsed['options'], 'when');
            $then = $this->stringOption($parsed['options'], 'then');
            $missingOptions = [];
            foreach ([
                '--pattern-key' => $patternKey,
                '--given' => $given,
                '--when' => $when,
                '--then' => $then,
            ] as $label => $value) {
                if ($value === null) {
                    $missingOptions[] = $label;
                }
            }
            if ($missingOptions !== []) {
                throw new ValidationException(
                    $root,
                    null,
                    $findingId,
                    'finding-classify missing required options: ' . implode(', ', $missingOptions),
                );
            }
            if ($patternKey === null || $given === null || $when === null || $then === null) {
                throw new ValidationException($root, null, $findingId, 'finding-classify promotion metadata is incomplete');
            }

            $validationCase = new ValidationCase($given, $when, $then);
        }
""",
    """        $patternKey = $this->stringOption($parsed['options'], 'pattern-key');
        $given = $this->stringOption($parsed['options'], 'given');
        $when = $this->stringOption($parsed['options'], 'when');
        $then = $this->stringOption($parsed['options'], 'then');
        $lineageOptions = [
            '--pattern-key' => $patternKey,
            '--given' => $given,
            '--when' => $when,
            '--then' => $then,
        ];
        $hasLineageMetadata = array_filter($lineageOptions, static fn(?string $value): bool => $value !== null) !== [];
        $validationCase = null;
        if ($classification !== LearningClassification::IGNORE || $hasLineageMetadata) {
            $missingOptions = [];
            foreach ($lineageOptions as $label => $value) {
                if ($value === null) {
                    $missingOptions[] = $label;
                }
            }
            if ($missingOptions !== []) {
                throw new ValidationException(
                    $root,
                    null,
                    $findingId,
                    'finding-classify missing required options: ' . implode(', ', $missingOptions),
                );
            }
            if ($patternKey === null || $given === null || $when === null || $then === null) {
                throw new ValidationException($root, null, $findingId, 'finding-classify lineage metadata is incomplete');
            }

            $validationCase = new ValidationCase($given, $when, $then);
        }
""",
)

replace(
    "src/FindingToMemoryPromotionPolicy.php",
    """        $groups = [];
        foreach ($findingsById as $finding) {
            $key = $finding->patternKey ?? implode('|', $finding->scope);
""",
    """        $groups = [];
        foreach ($findingsById as $finding) {
            if ($finding->classification === LearningClassification::IGNORE) {
                continue;
            }

            $key = $finding->patternKey ?? implode('|', $finding->scope);
""",
)

replace(
    "tests/FindingClassifierTest.php",
    """    public function testInvalidTriageLeavesFindingByteForByteUnchanged(): void
""",
    """    public function testIgnoreMayRetainPatternLineageWithoutLearningNotePromotion(): void
    {
        $root = $this->createLearningRoot();
        $created = $this->createFinding($root, 'finding.2026-09-12.92a004');

        $ignored = (new FindingClassifier())->classify(
            root: $root,
            findingId: $created->finding->id,
            classification: LearningClassification::IGNORE,
            patternKey: 'finding.already.enforced',
            validationCase: new ValidationCase(
                'A recurring Finding belongs to a pattern already enforced by a deterministic constraint.',
                'The Finding is classified as requiring no new durable guidance.',
                'Pattern lineage remains reviewable without making a LearningNote promotable.',
            ),
        );

        self::assertSame(LearningClassification::IGNORE, $ignored->classification);
        self::assertSame('finding.already.enforced', $ignored->patternKey);
        self::assertInstanceOf(ValidationCase::class, $ignored->validationCase);
        self::assertFalse((new LearningNoteService())->promotionReadiness($root, $created->finding->id)->promotable);
    }

    public function testInvalidTriageLeavesFindingByteForByteUnchanged(): void
""",
)

replace(
    "tests/FindingClassifyCliTest.php",
    """    public function testInvalidClassificationDoesNotChangeFinding(): void
""",
    """    public function testIgnoreMayRetainCompletePatternLineage(): void
    {
        $root = $this->createLearningRoot();
        $findingId = $this->createFinding($root, 'finding.2026-09-12.92c004');

        [$exitCode, $output] = $this->runCli($root, [
            'finding-classify',
            $findingId,
            LearningClassification::IGNORE->value,
            '--pattern-key', 'finding.already.enforced',
            '--given', 'A recurring Finding belongs to a pattern already enforced deterministically.',
            '--when', 'No new soft durable guidance should be created.',
            '--then', 'Pattern lineage remains while LearningNote promotion stays blocked.',
        ]);

        self::assertSame(0, $exitCode, $output);
        $finding = (new FindingValidator())->validateFile($root . '/findings/validated/' . $findingId . '.json');
        self::assertSame(LearningClassification::IGNORE, $finding->classification);
        self::assertSame('finding.already.enforced', $finding->patternKey);
        self::assertInstanceOf(\\voku\\AgentLearning\\ValidationCase::class, $finding->validationCase);
        self::assertFalse((new LearningNoteService())->promotionReadiness($root, $findingId)->promotable);
    }

    public function testInvalidClassificationDoesNotChangeFinding(): void
""",
)

Path("tests/FindingToMemoryPromotionPolicyTest.php").write_text(r'''<?php

declare(strict_types=1);

namespace voku\AgentLearning\Tests;

use PHPUnit\Framework\TestCase;
use voku\AgentLearning\FindingCreator;
use voku\AgentLearning\FindingToMemoryPromotionPolicy;
use voku\AgentLearning\LearningClassification;
use voku\AgentLearning\ValidationCase;

final class FindingToMemoryPromotionPolicyTest extends TestCase
{
    public function testIgnoredPatternBearingEvidenceDoesNotResurrectMemoryCandidate(): void
    {
        $root = sys_get_temp_dir() . '/agent-learning-ignore-promotion-' . bin2hex(random_bytes(8));
        $creator = new FindingCreator();
        $validationCase = new ValidationCase(
            'A recurring pattern is already enforced by a deterministic constraint.',
            'Another real manifestation is captured as a Finding.',
            'The evidence remains attributable without recreating soft guidance.',
        );

        $first = $creator->createValidated(
            root: $root,
            taskId: 'PROJECT-112',
            session: 'session_PROJECT-112',
            createdBy: 'test',
            scope: ['src/Rule.php'],
            observation: 'First post-enforcement manifestation.',
            evidence: [['type' => 'manual_verification', 'summary' => 'Observed first manifestation.']],
            hypothesis: 'The detector needed refinement.',
            validatedConclusion: 'The active detector was refined.',
            confidence: 'high',
            sensitivity: 'public',
            id: 'finding.2026-09-14.112001',
            classification: LearningClassification::IGNORE,
            patternKey: 'rule.already.enforced',
            validationCase: $validationCase,
        )->finding;
        $second = $creator->createValidated(
            root: $root,
            taskId: 'PROJECT-113',
            session: 'session_PROJECT-113',
            createdBy: 'test',
            scope: ['src/Rule.php'],
            observation: 'Second post-enforcement manifestation.',
            evidence: [['type' => 'manual_verification', 'summary' => 'Observed second manifestation.']],
            hypothesis: 'The same enforced pattern appeared again.',
            validatedConclusion: 'No new soft guidance is needed.',
            confidence: 'high',
            sensitivity: 'public',
            id: 'finding.2026-09-14.112002',
            classification: LearningClassification::IGNORE,
            patternKey: 'rule.already.enforced',
            validationCase: $validationCase,
        )->finding;

        self::assertSame([], (new FindingToMemoryPromotionPolicy())->evaluate([
            $first->id => $first,
            $second->id => $second,
        ]));
    }
}
''')

replace(
    "resources/skills/agent-learning-consumer/SKILL.md",
    """   - `IGNORE` is valid for praise, vague reflection, and one-off noise.
""",
    """   - `IGNORE` means no new durable guidance. With no pattern metadata it remains suitable for praise, vague reflection, and one-off noise. When real recurring evidence is already structurally enforced, it may retain a complete `pattern_key` + `validation_case` pair for lineage without making a LearningNote promotable.
""",
)

replace(
    "CHANGELOG.md",
    """## [Unreleased]
""",
    """## [Unreleased]

### Fixed

- Allow `IGNORE` Finding triage to retain an optional complete `pattern_key` + `validation_case` lineage pair when recurring evidence is already structurally enforced, while keeping bare `IGNORE` behavior unchanged and excluding ignored Findings from Finding-to-Memory promotion so hard enforcement does not resurrect superseded soft guidance.
""",
)
