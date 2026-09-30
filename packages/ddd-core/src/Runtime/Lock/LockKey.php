<?php

declare(strict_types=1);

namespace TangibleDDD\Runtime\Lock;

/**
 * Identity of one process lock (register 3.7): consumer + tenant + process id.
 * Tenant is the blog id on wp multisite, '' elsewhere.
 */
final class LockKey {

  public function __construct(
    public readonly string $consumer,
    public readonly string $tenant,
    public readonly int $processId,
  ) {}

  /** Stable string form, used as the in-process map key. */
  public function id(): string {
    return $this->consumer . '|' . $this->tenant . '|' . $this->processId;
  }

  /**
   * MySQL GET_LOCK name (wp, pdo): 'ddd:' + sha1(consumer|tenant|process_id),
   * truncated to the 64-character GET_LOCK limit. wp also takes the legacy
   * `ddd_process_<id>` name after this one during the compatibility window.
   */
  public function mysqlName(): string {
    return substr('ddd:' . sha1($this->id()), 0, 64);
  }

  /**
   * Postgres advisory-lock key (section 5.2):
   * (crc32(consumer_prefix) << 32) | (process_id & 0xffffffff), as a signed bigint.
   * A collision only adds serialization, because state is re-read under the lock.
   */
  public function postgresKey(): int {
    return (crc32($this->consumer) << 32) | ($this->processId & 0xffffffff);
  }
}
