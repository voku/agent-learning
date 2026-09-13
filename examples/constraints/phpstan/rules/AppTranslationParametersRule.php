<?php

declare(strict_types=1);

namespace App\PHPStan\Rules;

use PhpParser\Node;
use PhpParser\Node\Expr\StaticCall;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleError;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * Validates that translation static calls (e.g. AppI18n::_('Hello %s', $name))
 * receive exactly as many arguments as sprintf-style placeholders defined in the format string.
 *
 * @implements Rule<Node\Expr\StaticCall>
 */
final class AppTranslationParametersRule implements Rule
{
    private const TARGET_CLASS = 'AppI18n';
    private const TARGET_METHOD = '_';

    #[\Override]
    public function getNodeType(): string
    {
        return StaticCall::class;
    }

    /**
     * @param StaticCall $node
     *
     * @return list<RuleError>
     */
    #[\Override]
    public function processNode(Node $node, Scope $scope): array
    {
        $methodNameIdentifier = $node->name;
        if (!$methodNameIdentifier instanceof Node\Identifier) {
            return [];
        }

        if ($methodNameIdentifier->toString() !== self::TARGET_METHOD) {
            return [];
        }

        if (!method_exists($node->class, 'toString') || $node->class->toString() !== self::TARGET_CLASS) {
            return [];
        }

        $args = $node->getArgs();
        foreach ($args as $arg) {
            // Boundary: cannot statically count unpacked arguments
            if ($arg->unpack) {
                return [];
            }
        }

        $argsCount = count($args);
        if ($argsCount < 1) {
            return [];
        }

        $formatArgType = $scope->getType($args[0]->value);
        $placeholdersCount = null;
        foreach ($formatArgType->getConstantStrings() as $formatString) {
            $count = $this->countPlaceholders($formatString->getValue());
            if ($placeholdersCount === null || $count > $placeholdersCount) {
                $placeholdersCount = $count;
            }
        }

        if ($placeholdersCount === null) {
            return [];
        }

        $valuesGiven = $argsCount - 1;
        if ($valuesGiven !== $placeholdersCount) {
            return [
                RuleErrorBuilder::message(
                    sprintf(
                        'Call to %s::%s contains %d placeholder(s), but %d parameter value(s) given.',
                        self::TARGET_CLASS,
                        self::TARGET_METHOD,
                        $placeholdersCount,
                        $valuesGiven,
                    )
                )
                    ->identifier('project.translation.parameters')
                    ->line($node->getLine())
                    ->build(),
            ];
        }

        return [];
    }

    private function countPlaceholders(string $format): int
    {
        $specifiers = '[bcdeEfFgGosuxX]';
        $pattern = '~(?<before>%*)%(?:(?<position>\d+)\$)?[-+]?(?:[ 0]|(?:\'[^%]))?-?\d*(?:\.\d*)?' . $specifiers . '~';
        $matches = [];
        preg_match_all($pattern, $format, $matches, \PREG_SET_ORDER);
        if (count($matches) === 0) {
            return 0;
        }

        $placeholders = array_filter(
            $matches,
            static fn (array $match): bool => (strlen($match['before']) % 2) === 0
        );

        if (count($placeholders) === 0) {
            return 0;
        }

        $maxPositionedNumber = 0;
        $maxOrdinaryNumber = 0;
        foreach ($placeholders as $placeholder) {
            if (isset($placeholder['position']) && is_numeric($placeholder['position'])) {
                $maxPositionedNumber = max((int) $placeholder['position'], $maxPositionedNumber);
            } else {
                $maxOrdinaryNumber++;
            }
        }

        return max($maxPositionedNumber, $maxOrdinaryNumber);
    }
}
