<?php

declare(strict_types=1);

namespace voku\AgentLearning;

use RuntimeException;

/**
 * The derived lineage graph is absent or behind current durable Learning state.
 *
 * Both conditions are recomputable from findings, proposals and active notes, which is
 * what separates them from every other lineage failure: unreadable records, invalid
 * durable data, or Learning state moving mid-rebuild are not projection problems and
 * must keep reaching the caller. Naming the recoverable case in the type is what lets a
 * read repair itself without a `catch (Throwable)` that would swallow the rest.
 */
final class LearningLineageProjectionUnavailable extends RuntimeException
{
}
