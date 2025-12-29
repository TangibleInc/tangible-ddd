<?php

namespace TangibleDDD\Application\Process;

use Attribute;

/**
 * Mark a process step to always execute asynchronously.
 *
 * When the runner encounters a step with this attribute, it will
 * reschedule via ActionScheduler before executing the step.
 *
 * Use for steps that are known to be resource-intensive.
 *
 * Example:
 * ```php
 * #[Async]
 * protected function generate_large_report(array $data): Result {
 *   // This will always run in a background job
 * }
 * ```
 */
#[Attribute(Attribute::TARGET_METHOD)]
final class Async {
}
