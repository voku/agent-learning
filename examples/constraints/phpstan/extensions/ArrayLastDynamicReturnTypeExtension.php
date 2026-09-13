<?php

declare(strict_types=1);

namespace App\PHPStan\Extensions;

use PhpParser\Node\Expr\FuncCall;
use PHPStan\Analyser\Scope;
use PHPStan\Reflection\FunctionReflection;
use PHPStan\Reflection\ParametersAcceptorSelector;
use PHPStan\Type\DynamicFunctionReturnTypeExtension;
use PHPStan\Type\NullType;
use PHPStan\Type\Type;
use PHPStan\Type\TypeCombinator;

/**
 * Dynamic return type extension for `array_last($array, $fallback = null)`.
 *
 * Instead of returning a generic `mixed`, this extension inspects:
 * 1. Constant arrays (tuples / shape maps) to return the exact type of the last element.
 * 2. Non-empty iterables (`isIterableAtLeastOnce()->yes()`) to return `IterableValueType`.
 * 3. Potentially empty iterables to return `TypeCombinator::union(IterableValueType, FallbackType)`.
 * 4. Empty arrays to return the explicit fallback type.
 */
final class ArrayLastDynamicReturnTypeExtension implements DynamicFunctionReturnTypeExtension
{
    #[\Override]
    public function isFunctionSupported(FunctionReflection $functionReflection): bool
    {
        return $functionReflection->getName() === 'array_last';
    }

    #[\Override]
    public function getTypeFromFunctionCall(
        FunctionReflection $functionReflection,
        FuncCall $functionCall,
        Scope $scope
    ): Type {
        $args = $functionCall->getArgs();

        if (!isset($args[0])) {
            return ParametersAcceptorSelector::selectFromArgs(
                $scope,
                $functionCall->getArgs(),
                $functionReflection->getVariants()
            )->getReturnType();
        }

        if (isset($args[1])) {
            $fallbackType = $scope->getType($args[1]->value);
        } else {
            $fallbackType = new NullType();
        }

        $argType = $scope->getType($args[0]->value);
        $iterableAtLeastOnce = $argType->isIterableAtLeastOnce();
        if ($iterableAtLeastOnce->no()) {
            return $fallbackType;
        }

        $constantArrays = $argType->getConstantArrays();
        if (\count($constantArrays) > 0) {
            $valueTypes = [];
            foreach ($constantArrays as $constantArray) {
                $arrayValueTypes = $constantArray->getValueTypes();
                if (\count($arrayValueTypes) === 0) {
                    $valueTypes[] = $fallbackType;
                    continue;
                }

                $valueTypes[] = end($arrayValueTypes);
            }

            return TypeCombinator::union(...$valueTypes);
        }

        $valueType = $argType->getIterableValueType();
        if ($iterableAtLeastOnce->yes()) {
            return $valueType;
        }

        return TypeCombinator::union($valueType, $fallbackType);
    }
}
