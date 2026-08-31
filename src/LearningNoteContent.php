<?php

declare(strict_types=1);

namespace voku\AgentLearning;

final readonly class LearningNoteContent
{
    /**
     * @param list<string> $failedApproaches
     * @param list<string> $examples
     */
    public function __construct(
        public string $title,
        public string $context,
        public string $guidance,
        public string $whyItWorks,
        public string $whenToApply,
        public string $whenNotToApply,
        public string $verification,
        public ?string $symptoms = null,
        public array $failedApproaches = [],
        public ?string $rootCause = null,
        public array $examples = [],
    ) {
    }

    /**
     * @param array<string, mixed> $record
     */
    public static function fromArray(array $record, string $file, ?string $recordId = null): self
    {
        return new self(
            title: self::requiredString($record, 'title', $file, $recordId),
            context: self::requiredString($record, 'context', $file, $recordId),
            guidance: self::requiredString($record, 'guidance', $file, $recordId),
            whyItWorks: self::requiredString($record, 'why_it_works', $file, $recordId),
            whenToApply: self::requiredString($record, 'when_to_apply', $file, $recordId),
            whenNotToApply: self::requiredString($record, 'when_not_to_apply', $file, $recordId),
            verification: self::requiredString($record, 'verification', $file, $recordId),
            symptoms: self::optionalString($record, 'symptoms', $file, $recordId),
            failedApproaches: self::stringList($record, 'failed_approaches', $file, $recordId),
            rootCause: self::optionalString($record, 'root_cause', $file, $recordId),
            examples: self::stringList($record, 'examples', $file, $recordId),
        );
    }

    /**
     * @return array{
     *   title: string,
     *   context: string,
     *   guidance: string,
     *   why_it_works: string,
     *   when_to_apply: string,
     *   when_not_to_apply: string,
     *   verification: string,
     *   symptoms: ?string,
     *   failed_approaches: list<string>,
     *   root_cause: ?string,
     *   examples: list<string>
     * }
     */
    public function toArray(): array
    {
        return [
            'title' => $this->title,
            'context' => $this->context,
            'guidance' => $this->guidance,
            'why_it_works' => $this->whyItWorks,
            'when_to_apply' => $this->whenToApply,
            'when_not_to_apply' => $this->whenNotToApply,
            'verification' => $this->verification,
            'symptoms' => $this->symptoms,
            'failed_approaches' => $this->failedApproaches,
            'root_cause' => $this->rootCause,
            'examples' => $this->examples,
        ];
    }

    /** @param array<string, mixed> $record */
    private static function requiredString(array $record, string $field, string $file, ?string $recordId): string
    {
        $value = $record[$field] ?? null;
        if (!is_string($value) || trim($value) === '') {
            throw new ValidationException($file, null, $recordId, 'content.' . $field . ' must be a non-empty string');
        }

        return trim($value);
    }

    /** @param array<string, mixed> $record */
    private static function optionalString(array $record, string $field, string $file, ?string $recordId): ?string
    {
        if (!array_key_exists($field, $record) || $record[$field] === null) {
            return null;
        }
        if (!is_string($record[$field]) || trim($record[$field]) === '') {
            throw new ValidationException($file, null, $recordId, 'content.' . $field . ' must be a non-empty string when present');
        }

        return trim($record[$field]);
    }

    /**
     * @param array<string, mixed> $record
     * @return list<string>
     */
    private static function stringList(array $record, string $field, string $file, ?string $recordId): array
    {
        $value = $record[$field] ?? [];
        if (!is_array($value)) {
            throw new ValidationException($file, null, $recordId, 'content.' . $field . ' must be an array');
        }

        $result = [];
        foreach ($value as $item) {
            if (!is_string($item) || trim($item) === '') {
                throw new ValidationException($file, null, $recordId, 'content.' . $field . ' entries must be non-empty strings');
            }
            $result[] = trim($item);
        }

        return array_values(array_unique($result));
    }
}
