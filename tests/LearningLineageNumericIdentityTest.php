<?php

declare(strict_types=1);

namespace voku\AgentLearning\Tests;

use PHPUnit\Framework\TestCase;
use voku\AgentLearning\LearningLineageService;

final class LearningLineageNumericIdentityTest extends TestCase
{
    public function testNumericTaskIdRemainsStringInLosslessDepthProjection(): void
    {
        $root = sys_get_temp_dir() . '/agent-learning-numeric-lineage-' . bin2hex(random_bytes(6));
        self::assertTrue(mkdir($root, 0o775, true));

        try {
            $result = (new LearningLineageService())->precedentsForTask(
                $root,
                '403',
                maximumRelatedIdentities: 10,
            );
            $lineage = $result->lineage->toArray();

            self::assertSame('403', $lineage['identity_id']);
            self::assertSame(
                [
                    [
                        'identity_id' => '403',
                        'depth' => 0,
                    ],
                ],
                $lineage['identity_depths'],
            );
        } finally {
            rmdir($root);
        }
    }
}
