<?php

declare(strict_types=1);

namespace voku\AgentLearning;

use DateTimeImmutable;
use voku\AgentLearning\Catalog\ProposalProjection;

/**
 * Read-only projection of the proposals still waiting for a human decision
 * (candidate) or for application (approved).
 *
 * It states facts a reviewer would otherwise gather by hand: lineage, other
 * proposals on the same target, whether the proposed wording already exists in
 * the target or in probe files, whether scope paths still resolve. It never
 * recommends a transition; that judgement stays with a named human.
 */
final readonly class ProposalReviewQueue
{
    private const int SHINGLE_WORDS = 4;
    private const int REASON_EXCERPT_CHARS = 220;

    /**
     * @param list<string> $probeFiles files to measure the proposed wording against, relative to the project root or absolute
     *
     * @return list<ProposalReviewRow>
     */
    public function build(string $learningRoot, string $projectRoot, array $probeFiles = [], ?DateTimeImmutable $now = null): array
    {
        $now ??= new DateTimeImmutable();
        $all = (new LearningCatalog($learningRoot))->proposals();

        $probeTexts = [];
        foreach ($probeFiles as $probeFile) {
            $path = $this->absolutePath($projectRoot, $probeFile);
            $content = is_file($path) ? file_get_contents($path) : false;
            $probeTexts[$probeFile] = $content === false ? null : $content;
        }

        $rows = [];
        foreach ($all as $proposal) {
            if ($proposal->status !== ProposalStatus::CANDIDATE->value && $proposal->status !== ProposalStatus::APPROVED->value) {
                continue;
            }
            $rows[] = $this->row($proposal, $all, $projectRoot, $probeTexts, $now);
        }

        usort(
            $rows,
            static fn (ProposalReviewRow $left, ProposalReviewRow $right): int => [$left->status, $right->ageDays * -1, $left->id]
                <=> [$right->status, $left->ageDays * -1, $right->id],
        );

        return $rows;
    }

    /**
     * @param list<ProposalProjection>   $all
     * @param array<string, string|null> $probeTexts
     */
    private function row(ProposalProjection $proposal, array $all, string $projectRoot, array $probeTexts, DateTimeImmutable $now): ProposalReviewRow
    {
        $signals = [];

        if ($proposal->correctsProposalId !== null) {
            $signals[] = 'corrects:' . $proposal->correctsProposalId;
        }
        foreach ($proposal->supersedesProposalIds as $id) {
            $signals[] = 'supersedes:' . $id;
        }
        foreach ($proposal->conflictsWithProposalIds as $id) {
            $signals[] = 'conflicts_with:' . $id;
        }
        foreach ($all as $other) {
            if ($other->id === $proposal->id) {
                continue;
            }
            if ($other->correctsProposalId === $proposal->id) {
                $signals[] = 'corrected_by:' . $other->id . '(' . $other->status . ')';
            }
            if ($proposal->target !== null && $other->target === $proposal->target) {
                $signals[] = 'same_target:' . $other->id . '(' . $other->status . ')';
            }
        }

        foreach ($proposal->scope as $scopePath) {
            if (!file_exists($this->absolutePath($projectRoot, $scopePath))) {
                $signals[] = 'scope_path_missing:' . $scopePath;
            }
        }

        $files = [];
        if ($proposal->targetType === GuidanceType::SKILL->value && $proposal->target !== null) {
            $targetPath = $this->absolutePath($projectRoot, $proposal->target);
            if (is_file($targetPath)) {
                $content = file_get_contents($targetPath);
                $files[$proposal->target] = $content === false ? null : $content;
            } else {
                $signals[] = 'target_file_missing:' . $proposal->target;
            }
        }
        foreach ($probeTexts as $probeFile => $probeText) {
            if ($probeText === null) {
                $signals[] = 'probe_file_missing:' . $probeFile;
                continue;
            }
            $files[$probeFile] = $probeText;
        }

        $matches = [];
        if ($proposal->proposedChange !== null && trim($proposal->proposedChange) !== '') {
            foreach ($files as $file => $text) {
                if ($text === null) {
                    continue;
                }
                $matches[] = $this->wordingMatch($file, $proposal->proposedChange, $text);
            }
            usort(
                $matches,
                static fn (ProposalWordingMatch $left, ProposalWordingMatch $right): int => [$right->exact, $right->containmentPercent, $left->file]
                    <=> [$left->exact, $left->containmentPercent, $right->file],
            );
        }

        return new ProposalReviewRow(
            id: $proposal->id,
            status: $proposal->status,
            action: $proposal->action,
            targetType: $proposal->targetType,
            target: $proposal->target,
            ageDays: $this->ageDays($proposal->createdAt, $now),
            sourceFindingCount: count($proposal->sourceFindingIds),
            reasonExcerpt: $this->excerpt($proposal->reason),
            allowedTransitions: $this->allowedTransitions($proposal),
            signals: $signals,
            wordingMatches: $matches,
        );
    }

    /** @return list<string> */
    private function allowedTransitions(ProposalProjection $proposal): array
    {
        if ($proposal->status === ProposalStatus::APPROVED->value) {
            return ['proposal-mark-applied'];
        }

        $transitions = ['proposal-approve', 'proposal-reject'];
        if ($proposal->action === Action::NO_DURABLE_LEARNING->value) {
            $transitions[] = 'proposal-acknowledge';
        }

        return $transitions;
    }

    private function wordingMatch(string $file, string $proposed, string $existing): ProposalWordingMatch
    {
        $normalisedProposed = $this->normalise($proposed);
        if (str_contains($this->normalise($existing), $normalisedProposed)) {
            return new ProposalWordingMatch($file, 100, true);
        }

        $proposedShingles = $this->shingles($proposed);
        if ($proposedShingles === []) {
            return new ProposalWordingMatch($file, 0, false);
        }
        $existingShingles = array_flip($this->shingles($existing));
        $shared = 0;
        foreach ($proposedShingles as $shingle) {
            if (isset($existingShingles[$shingle])) {
                ++$shared;
            }
        }

        return new ProposalWordingMatch($file, intdiv($shared * 100, count($proposedShingles)), false);
    }

    /** @return list<string> */
    private function shingles(string $text): array
    {
        $words = preg_split('/[^\p{L}\p{N}_]+/u', mb_strtolower($text), -1, PREG_SPLIT_NO_EMPTY);
        if ($words === false || count($words) < self::SHINGLE_WORDS) {
            return [];
        }

        $shingles = [];
        for ($index = 0, $last = count($words) - self::SHINGLE_WORDS; $index <= $last; ++$index) {
            $shingles[implode(' ', array_slice($words, $index, self::SHINGLE_WORDS))] = true;
        }

        return array_keys($shingles);
    }

    private function normalise(string $text): string
    {
        return preg_replace('/\s+/', ' ', trim($text)) ?? trim($text);
    }

    private function excerpt(string $reason): string
    {
        $reason = $this->normalise($reason);

        return mb_strlen($reason) > self::REASON_EXCERPT_CHARS ? mb_substr($reason, 0, self::REASON_EXCERPT_CHARS) . '…' : $reason;
    }

    private function ageDays(string $createdAt, DateTimeImmutable $now): int
    {
        try {
            $created = new DateTimeImmutable($createdAt);
        } catch (\Exception) {
            return 0;
        }

        return max(0, (int) $created->diff($now)->days);
    }

    private function absolutePath(string $projectRoot, string $path): string
    {
        if (str_starts_with($path, '/')) {
            return $path;
        }

        return rtrim($projectRoot, '/\\') . '/' . ltrim($path, '/');
    }
}
