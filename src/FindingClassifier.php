<?php

declare(strict_types=1);

namespace voku\AgentLearning;

use Throwable;

/**
 * Applies reusable-learning triage after raw Finding capture.
 */
final readonly class FindingClassifier
{
    public function __construct(
        private FindingParser $parser = new FindingParser(),
        private FindingLifecycle $lifecycle = new FindingLifecycle(),
    ) {
    }

    public function classify(
        string $root,
        string $findingId,
        LearningClassification $classification,
        ?string $patternKey = null,
        ?ValidationCase $validationCase = null,
        ?string $taskIdPattern = null,
    ): Finding {
        $path = $this->resolveFindingPath($root, $findingId);
        $finding = $this->parser->parseFile($path);
        $raw = $finding->raw;
        $raw['classification'] = $classification->value;

        if ($classification === LearningClassification::IGNORE) {
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

        $updated = $this->parser->parseRecord($raw, $path);
        $validator = $taskIdPattern === null
            ? new FindingValidator()
            : new FindingValidator(taskIdPattern: $taskIdPattern);
        $validator->validate($updated, $path);

        $encoded = json_encode(
            $updated->raw,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        ) . "\n";
        $this->replaceAtomically($path, $encoded, $findingId);

        return $updated;
    }

    private function resolveFindingPath(string $root, string $findingId): string
    {
        foreach ($this->lifecycle->directories() as $directory) {
            $path = $root . '/findings/' . $directory . '/' . $findingId . '.json';
            if (is_file($path)) {
                return $path;
            }
        }

        throw new ValidationException($root, null, $findingId, 'finding file not found: ' . $findingId);
    }

    private function replaceAtomically(string $path, string $content, string $findingId): void
    {
        $temporaryPath = dirname($path) . '/.' . basename($path) . '.classify.' . bin2hex(random_bytes(8));
        $handle = fopen($temporaryPath, 'xb');
        if ($handle === false) {
            throw new ValidationException($temporaryPath, null, $findingId, 'cannot create temporary finding file');
        }

        try {
            $offset = 0;
            $length = strlen($content);
            while ($offset < $length) {
                $written = fwrite($handle, substr($content, $offset));
                if ($written === false || $written === 0) {
                    throw new ValidationException($temporaryPath, null, $findingId, 'cannot write temporary finding file');
                }
                $offset += $written;
            }
            if (!fflush($handle)) {
                throw new ValidationException($temporaryPath, null, $findingId, 'cannot flush temporary finding file');
            }
            if (!fsync($handle)) {
                throw new ValidationException($temporaryPath, null, $findingId, 'cannot sync temporary finding file');
            }
        } catch (Throwable $throwable) {
            fclose($handle);
            @unlink($temporaryPath);
            throw $throwable;
        }

        if (!fclose($handle)) {
            @unlink($temporaryPath);
            throw new ValidationException($temporaryPath, null, $findingId, 'cannot close temporary finding file');
        }

        if (!rename($temporaryPath, $path)) {
            @unlink($temporaryPath);
            throw new ValidationException($path, null, $findingId, 'cannot atomically replace finding file');
        }
    }
}
