<?php

declare(strict_types=1);

namespace App\PHPStan\Extensions;

use PhpParser\Node\Arg;
use PhpParser\Node\Expr\BinaryOp\BooleanAnd;
use PhpParser\Node\Expr\BinaryOp\NotIdentical;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\String_;
use PHPStan\Analyser\Scope;
use PHPStan\Analyser\SpecifiedTypes;
use PHPStan\Analyser\TypeSpecifier;
use PHPStan\Analyser\TypeSpecifierAwareExtension;
use PHPStan\Analyser\TypeSpecifierContext;
use PHPStan\Reflection\FunctionReflection;
use PHPStan\Type\Accessory\AccessoryLiteralStringType;
use PHPStan\Type\Accessory\AccessoryNonEmptyStringType;
use PHPStan\Type\Accessory\AccessoryNumericStringType;
use PHPStan\Type\FunctionTypeSpecifyingExtension;
use PHPStan\Type\IntersectionType;
use PHPStan\Type\StringType;

/**
 * Type specifying extension for string-search functions (e.g. `str_contains`, `app_str_contains`).
 *
 * When `str_contains($haystack, $needle)` is truthy and `$needle` is a non-empty string,
 * PHPStan narrows `$haystack` to `non-empty-string` inside the truthy condition branch.
 */
final class StrContainingTypeSpecifyingExtension implements FunctionTypeSpecifyingExtension, TypeSpecifierAwareExtension
{
    /**
     * Maps supported function names to [haystackArgIndex, needleArgIndex]
     *
     * @var array<string, array{0: int, 1: int}>
     */
    private array $supportedFunctions = [
        'str_contains'        => [0, 1],
        'str_starts_with'     => [0, 1],
        'str_ends_with'       => [0, 1],
        'app_str_contains'    => [0, 1],
        'app_str_starts_with' => [0, 1],
        'app_str_ends_with'   => [0, 1],
    ];

    private TypeSpecifier $typeSpecifier;

    #[\Override]
    public function setTypeSpecifier(TypeSpecifier $typeSpecifier): void
    {
        $this->typeSpecifier = $typeSpecifier;
    }

    #[\Override]
    public function isFunctionSupported(
        FunctionReflection $functionReflection,
        FuncCall $node,
        TypeSpecifierContext $context
    ): bool {
        return array_key_exists(strtolower($functionReflection->getName()), $this->supportedFunctions)
            && $context->truthy();
    }

    #[\Override]
    public function specifyTypes(
        FunctionReflection $functionReflection,
        FuncCall $node,
        Scope $scope,
        TypeSpecifierContext $context
    ): SpecifiedTypes {
        $args = $node->getArgs();

        if (count($args) >= 2) {
            [$haystackIdx, $needleIdx] = $this->supportedFunctions[strtolower($functionReflection->getName())];

            $haystackType = $scope->getType($args[$haystackIdx]->value);
            $needleType = $scope->getType($args[$needleIdx]->value);

            if ($needleType->isNonEmptyString()->yes() && $haystackType->isString()->yes()) {
                $accessories = [
                    new StringType(),
                    new AccessoryNonEmptyStringType(),
                ];

                if ($haystackType->isLiteralString()->yes()) {
                    $accessories[] = new AccessoryLiteralStringType();
                }
                if ($haystackType->isNumericString()->yes()) {
                    $accessories[] = new AccessoryNumericStringType();
                }

                return $this->typeSpecifier->create(
                    $args[$haystackIdx]->value,
                    new IntersectionType($accessories),
                    $context,
                    $scope,
                )->setRootExpr(new BooleanAnd(
                    new NotIdentical($args[$needleIdx]->value, new String_('')),
                    new FuncCall(new Name('boolval'), [new Arg($args[$needleIdx]->value)]),
                ));
            }
        }

        return new SpecifiedTypes();
    }
}
