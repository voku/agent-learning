<?php

declare(strict_types=1);

namespace App\PHPStan\Rules;

use PhpParser\Node;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleError;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * Forbids hardcoded absolute developer host paths (e.g. "/home/<user>/Projects/...")
 * in committed code. Such paths break on other developer machines and in CI environments.
 *
 * @implements Rule<Node\Scalar\String_>
 */
final class NoHardcodedHostPathRule implements Rule
{
    private const HOST_PATH_PATTERN = '#/(?:home|Users)/[^/\s"\']+/Projects#';

    #[\Override]
    public function getNodeType(): string
    {
        return Node\Scalar\String_::class;
    }

    /**
     * @param Node\Scalar\String_ $node
     *
     * @return list<RuleError>
     */
    #[\Override]
    public function processNode(Node $node, Scope $scope): array
    {
        if (preg_match(self::HOST_PATH_PATTERN, $node->value) !== 1) {
            return [];
        }

        return [
            RuleErrorBuilder::message(
                'Do not hardcode machine-specific absolute host paths; use a repository-relative path, "vendor/bin/...", or configuration instead.'
            )
                ->identifier('project.noHardcodedHostPath')
                ->line($node->getLine())
                ->build(),
        ];
    }
}
