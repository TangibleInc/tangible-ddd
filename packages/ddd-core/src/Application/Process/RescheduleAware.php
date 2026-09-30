<?php

namespace TangibleDDD\Application\Process;

/**
 * Trait for runners that need to check resource limits and reschedule.
 */
trait RescheduleAware {
  protected ?int $started_at = null;
  protected int $max_execution_seconds = 25;
  protected float $memory_limit_percent = 0.8;

  /**
   * Check if execution time limit has been exceeded.
   */
  protected function time_exceeded(): bool {
    if ($this->started_at === null) {
      return false;
    }

    return (time() - $this->started_at) >= $this->max_execution_seconds;
  }

  /**
   * Check if memory usage has exceeded threshold.
   */
  protected function memory_exceeded(): bool {
    $limit = ini_get('memory_limit');

    if ($limit === '-1') {
      return false;
    }

    $limit_bytes = $this->parse_memory_limit($limit);
    $usage = memory_get_usage(true);

    return $usage >= ($limit_bytes * $this->memory_limit_percent);
  }

  /**
   * Check if either resource limit has been exceeded.
   */
  protected function resources_exceeded(): bool {
    return $this->time_exceeded() || $this->memory_exceeded();
  }

  /**
   * Parse a PHP memory_limit value to bytes.
   */
  private function parse_memory_limit(string $limit): int {
    $limit = trim($limit);
    $last = strtolower($limit[strlen($limit) - 1]);
    $value = (int) $limit;

    switch ($last) {
      case 'g':
        $value *= 1024 * 1024 * 1024;
        break;
      case 'm':
        $value *= 1024 * 1024;
        break;
      case 'k':
        $value *= 1024;
        break;
    }

    return $value;
  }
}
