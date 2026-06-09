<?php

declare(strict_types=1);

namespace voku\AgentLearning;

final class ConsolidationPromptBuilder
{
    /**
     * @param list<Finding> $findings
     */
    public function build(string $taskId, array $findings, string $rejectedHistory = ''): string
    {
        $lines = [
            '# Agent Learning Consolidation Input',
            '',
            'You are reviewing candidate coding-agent findings from one repository.',
            '',
            'These findings may be incomplete, duplicated, overly broad, or wrong.',
            '',
            'Separate:',
            '',
            '- verified observations',
            '- hypotheses',
            '- unsupported assumptions',
            '- repeated patterns',
            '- contradictions',
            '- existing guidance already covering the problem',
            '',
            'Do not make a rule broader than the evidence supports.',
            'Preserve repository-specific names, paths, commands, and exceptions.',
            '',
            'Return exactly one result:',
            '',
            '- NO_DURABLE_LEARNING',
            '- ADD',
            '- DELETE',
            '- REPLACE',
            '- REJECT',
            '',
            'For a durable proposal, include target, source findings, exact scope, exact wording, evidence, known boundary, validation, and remaining uncertainty.',
            'Do not approve the proposal.',
            'Do not create several unrelated learnings.',
            'Do not replace concise project-specific guidance with longer generic prose.',
            '',
            '## Target',
            '',
            'Task/scope: `' . $taskId . '`',
            '',
            '## Validated Findings',
            '',
        ];

        foreach ($findings as $finding) {
            $lines[] = '### ' . $finding->id;
            $lines[] = '';
            $lines[] = '- Task: `' . $finding->taskId . '`';
            $lines[] = '- Session: `' . $finding->session . '`';
            $lines[] = '- Scope: `' . implode('`, `', $finding->scope) . '`';
            $lines[] = '- Observation: ' . $finding->observation;
            $lines[] = '- Hypothesis: ' . $finding->hypothesis;
            $lines[] = '- Validated conclusion: ' . (string)$finding->validatedConclusion;
            $lines[] = '- Confidence: ' . $finding->confidence;
            $lines[] = '- Evidence:';
            foreach ($finding->evidence as $evidence) {
                $lines[] = '  - ' . $this->formatEvidence($evidence);
            }
            $lines[] = '';
        }

        $lines[] = '## Rejected Proposal History';
        $lines[] = '';
        $lines[] = '```jsonl';
        $lines[] = trim($rejectedHistory);
        $lines[] = '```';
        $lines[] = '';

        return implode("\n", $lines);
    }

    /**
     * @param array<string, mixed> $evidence
     */
    private function formatEvidence(array $evidence): string
    {
        $parts = [];
        foreach ($evidence as $key => $value) {
            if (is_scalar($value) || $value === null) {
                $parts[] = $key . '=' . (string)$value;
            }
        }

        return implode('; ', $parts);
    }
}
