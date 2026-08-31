<?php

declare(strict_types=1);

namespace voku\AgentLearning;

final readonly class LearningNoteRepositoryEvidence
{
    public function __construct(
        public string $sourceRef,
        public string $contentSha256,
    ) {
        if (trim($sourceRef) === '') {
            throw new ValidationException('LearningNote', null, null, 'repository evidence source_ref must be non-empty');
        }
        if (preg_match('/^[a-f0-9]{64}$/', $contentSha256) !== 1) {
            throw new ValidationException('LearningNote', null, null, 'repository evidence content_sha256 must be a lowercase sha256');
        }
    }

    /** @return array{source_ref: string, content_sha256: string} */
    public function toArray(): array
    {
        return [
            'source_ref' => $this->sourceRef,
            'content_sha256' => $this->contentSha256,
        ];
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data, string $file, ?string $recordId): self
    {
        $sourceRef = $data['source_ref'] ?? null;
        $contentSha256 = $data['content_sha256'] ?? null;
        if (!is_string($sourceRef) || trim($sourceRef) === '') {
            throw new ValidationException($file, null, $recordId, 'LearningNote repository evidence requires source_ref');
        }
        if (!is_string($contentSha256) || preg_match('/^[a-f0-9]{64}$/', $contentSha256) !== 1) {
            throw new ValidationException($file, null, $recordId, 'LearningNote repository evidence requires lowercase sha256 content_sha256');
        }

        return new self(str_replace('\\', '/', trim($sourceRef)), $contentSha256);
    }
}
