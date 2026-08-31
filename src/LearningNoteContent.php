<?php

declare(strict_types=1);

namespace voku\AgentLearning;

final readonly class LearningNoteContent
{
    public function __construct(
        public string $title,
        public string $context,
        public string $guidance,
        public ?string $symptoms = null,
        public ?string $failedApproaches = null,
        public ?string $rootCause = null,
        public ?string $whyItWorks = null,
        public ?string $whenToApply = null,
        public ?string $whenNotToApply = null,
        public ?string $verification = null,
    ) {
    }

    /** @return array<string, string> */
    public function toArray(): array
    {
        $data = [
            'title' => $this->title,
            'context' => $this->context,
            'guidance' => $this->guidance,
        ];
        foreach ([
            'symptoms' => $this->symptoms,
            'failed_approaches' => $this->failedApproaches,
            'root_cause' => $this->rootCause,
            'why_it_works' => $this->whyItWorks,
            'when_to_apply' => $this->whenToApply,
            'when_not_to_apply' => $this->whenNotToApply,
            'verification' => $this->verification,
        ] as $key => $value) {
            if ($value !== null) {
                $data[$key] = $value;
            }
        }

        return $data;
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data, string $file, ?string $recordId): self
    {
        $required = static function (string $key) use ($data, $file, $recordId): string {
            $value = $data[$key] ?? null;
            if (!is_string($value) || trim($value) === '') {
                throw new ValidationException($file, null, $recordId, 'LearningNote content requires non-empty string ' . $key);
            }

            return trim($value);
        };
        $optional = static function (string $key) use ($data, $file, $recordId): ?string {
            if (!array_key_exists($key, $data) || $data[$key] === null) {
                return null;
            }
            $value = $data[$key];
            if (!is_string($value) || trim($value) === '') {
                throw new ValidationException($file, null, $recordId, 'LearningNote content ' . $key . ' must be a non-empty string when present');
            }

            return trim($value);
        };

        return new self(
            title: $required('title'),
            context: $required('context'),
            guidance: $required('guidance'),
            symptoms: $optional('symptoms'),
            failedApproaches: $optional('failed_approaches'),
            rootCause: $optional('root_cause'),
            whyItWorks: $optional('why_it_works'),
            whenToApply: $optional('when_to_apply'),
            whenNotToApply: $optional('when_not_to_apply'),
            verification: $optional('verification'),
        );
    }
}
