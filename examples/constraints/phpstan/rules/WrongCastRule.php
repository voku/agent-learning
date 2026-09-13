<?php

declare(strict_types=1);

namespace App\PHPStan\Rules;

use PhpParser\Node;
use PhpParser\Node\Expr\Cast;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleError;
use PHPStan\Rules\RuleErrorBuilder;
use PHPStan\Type\ErrorType;
use PHPStan\Type\GeneralizePrecision;
use PHPStan\Type\VerbosityLevel;

/**
 * Validates that string-to-int casts are not applied on specific high-precision or domain classes.
 *
 * Demonstrates:
 * - Type retrieval via $scope->getType($node)
 * - Type generalization via ->generalize(GeneralizePrecision::lessSpecific())
 * - Class reflection via $scope->getClassReflection()
 *
 * @implements Rule<Node\Expr\Cast>
 */
final class WrongCastRule implements Rule
{
    /**
     * @param list<class-string> $classesForCheckStringToIntCast
     */
    public function __construct(
        private readonly array $classesForCheckStringToIntCast = [],
    ) {
    }

    #[\Override]
    public function getNodeType(): string
    {
        return Cast::class;
    }

    /**
     * @param Cast $node
     *
     * @return list<RuleError>
     */
    #[\Override]
    public function processNode(Node $node, Scope $scope): array
    {
        $castType = $scope->getType($node);
        if ($castType instanceof ErrorType) {
            return [];
        }

        $castTypeGeneralize = $castType->generalize(GeneralizePrecision::lessSpecific());
        $expressionType = $scope->getType($node->expr);
        $expressionTypeGeneralize = $expressionType->generalize(GeneralizePrecision::lessSpecific());

        $currentClass = $scope->getClassReflection();
        if ($currentClass === null) {
            return [];
        }

        foreach ($this->classesForCheckStringToIntCast as $targetClass) {
            if (
                $expressionTypeGeneralize->isString()->yes()
                && $castTypeGeneralize->isInteger()->yes()
                && ($currentClass->getName() === $targetClass || $currentClass->isSubclassOf($targetClass))
            ) {
                return [
                    RuleErrorBuilder::message(
                        sprintf(
                            'Casting expression of type %s to %s is prohibited in %s.',
                            $expressionType->describe(VerbosityLevel::typeOnly()),
                            $castType->describe(VerbosityLevel::typeOnly()),
                            $targetClass,
                        )
                    )
                        ->identifier('project.wrongCast')
                        ->line($node->getLine())
                        ->build(),
                ];
            }
        }

        return [];
    }
}
