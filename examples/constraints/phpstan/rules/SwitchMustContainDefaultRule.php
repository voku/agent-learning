<?php

declare(strict_types=1);

namespace App\PHPStan\Rules;

use PhpParser\Node;
use PhpParser\Node\Stmt\Switch_;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleError;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * Ensures that all switch statements include an explicit default case.
 *
 * @implements Rule<Node\Stmt\Switch_>
 */
final class SwitchMustContainDefaultRule implements Rule
{
    #[\Override]
    public function getNodeType(): string
    {
        return Switch_::class;
    }

    /**
     * @param Switch_ $node
     *
     * @return list<RuleError>
     */
    #[\Override]
    public function processNode(Node $node, Scope $scope): array
    {
        foreach ($node->cases as $case) {
            // In PHP-Parser, a default case has $case->cond === null
            if ($case->cond === null) {
                return [];
            }
        }

        return [
            RuleErrorBuilder::message('Switch statement does not have a "default" case.')
                ->identifier('project.switchMustContainDefault')
                ->line($node->getLine())
                ->build(),
        ];
    }
}
