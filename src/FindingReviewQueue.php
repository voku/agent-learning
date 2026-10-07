<?php

declare(strict_types=1);

namespace voku\AgentLearning;

use DateTimeImmutable;

/**
 * Read-only projection of the findings that still need downstream Learning
 * handling: validated, not consumed by a current LearningNote, and either
 * without a proposal or cited by one still waiting for a decision.
 *
 * It states facts (proposals, unresolved scope paths, wording already present
 * in probe files). It never recommends a bucket, owner or transition.
 */
final readonly class FindingReviewQueue
{
    private const int EXCERPT_CHARS = 220;

    public function __construct(private FindingLifecycle $lifecycle = new FindingLifecycle())
    {
    }

    /**
     * @param list<string> $probeFiles files to measure the conclusion against, relative to the project root or absolute
     *
     * @return list<FindingReviewRow>
     */
    public function build(string $learningRoot, string $projectRoot, array $probeFiles = [], ?DateTimeImmutable $now = null): array
    {
        $now ??= new DateTimeImmutable();
        $catalog = new LearningCatalog($learningRoot);
        $attention = array_flip($catalog->overview()->findingAttentionIds);

        $statusByProposal = [];
        foreach ($catalog->proposals() as $proposal) {
            $statusByProposal[$proposal->id] = $proposal->status;
        }

        $probeTexts = [];
        foreach ($probeFiles as $probeFile) {
            $path = $this->absolutePath($projectRoot, $probeFile);
            $content = is_file($path) ? file_get_contents($path) : false;
            $probeTexts[$probeFile] = $content === false ? null : $content;
        }

        $rows = [];
        foreach ($catalog->findings(FindingStatus::VALIDATED->value) as $finding) {
            if (!isset($attention[$finding->id])) {
                continue;
            }
            $text = $finding->validatedConclusion ?? $finding->observation;

            $proposals = [];
            $signals = [];
            foreach ($finding->proposalIds as $proposalId) {
                $status = $statusByProposal[$proposalId] ?? 'unknown';
                $proposals[] = $proposalId . '(' . $status . ')';
                if ($status === ProposalStatus::CANDIDATE->value || $status === ProposalStatus::APPROVED->value) {
                    $signals[] = 'proposal_open:' . $proposalId . '(' . $status . ')';
                }
            }
            if ($finding->proposalIds === []) {
                $signals[] = 'no_proposal';
            }
            foreach ($finding->scope as $scopePath) {
                if (!file_exists($this->absolutePath($projectRoot, $scopePath))) {
                    $signals[] = 'scope_path_missing:' . $scopePath;
                }
            }

            $matches = [];
            foreach ($probeTexts as $probeFile => $probeText) {
                if ($probeText === null) {
                    $signals[] = 'probe_file_missing:' . $probeFile;
                    continue;
                }
                $matches[] = (new WordingOverlap())->match($probeFile, $text, $probeText);
            }
            usort(
                $matches,
                static fn (ProposalWordingMatch $left, ProposalWordingMatch $right): int => [$right->exact, $right->containmentPercent, $left->file]
                    <=> [$left->exact, $left->containmentPercent, $right->file],
            );

            $rows[] = new FindingReviewRow(
                id: $finding->id,
                taskId: $finding->taskId,
                ageDays: $this->ageDays($finding->createdAt, $now),
                conclusionExcerpt: $this->excerpt($text),
                allowedTransitions: array_map(
                    static fn (FindingStatus $status): string => $status->value,
                    $this->lifecycle->allowedTransitions(FindingStatus::VALIDATED),
                ),
                proposals: $proposals,
                signals: $signals,
                wordingMatches: $matches,
            );
        }

        usort($rows, static fn (FindingReviewRow $left, FindingReviewRow $right): int => [$right->ageDays, $left->id] <=> [$left->ageDays, $right->id]);

        return $rows;
    }

    private function excerpt(string $text): string
    {
        $text = preg_replace('/\s+/', ' ', trim($text)) ?? trim($text);

        return mb_strlen($text) > self::EXCERPT_CHARS ? mb_substr($text, 0, self::EXCERPT_CHARS) . '…' : $text;
    }

    private function ageDays(string $createdAt, DateTimeImmutable $now): int
    {
        try {
            return max(0, (int) (new DateTimeImmutable($createdAt))->diff($now)->days);
        } catch (\Exception) {
            return 0;
        }
    }

    private function absolutePath(string $projectRoot, string $path): string
    {
        if (str_starts_with($path, '/')) {
            return $path;
        }

        return rtrim($projectRoot, '/\\') . '/' . ltrim($path, '/');
    }
}
