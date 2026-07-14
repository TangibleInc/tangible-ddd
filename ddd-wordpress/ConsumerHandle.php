<?php

namespace TangibleDDD\WordPress;

use TangibleDDD\Infra\IDDDConfig;

/**
 * One registered ddd consumer, as seen by discovery surfaces (the ops
 * dashboard, WP-CLI, cross-consumer tooling).
 *
 * Carries exactly what register_hooks()/boot() received: the consumer's
 * IDDDConfig (prefix → tables, hooks, options) and its DI getter. The
 * getter stays uncalled until container() — registration happens during
 * plugin bootstrap, long before it is safe or cheap to build containers.
 */
final class ConsumerHandle {

  /** @var callable */
  private $di_getter;

  public function __construct(
    private readonly IDDDConfig $config,
    callable $di_getter,
    private readonly ?string $custom_label = null,
  ) {
    $this->di_getter = $di_getter;
  }

  public function prefix(): string {
    return $this->config->prefix();
  }

  public function label(): string {
    return $this->custom_label ?? $this->config->prefix();
  }

  public function version(): string {
    return $this->config->version();
  }

  public function config(): IDDDConfig {
    return $this->config;
  }

  /**
   * The consumer's DI container, resolved lazily through its getter.
   * Framework write actions (replay/retry/pause) dispatch through THIS
   * consumer's bus — never another's.
   */
  public function container(): object {
    return ($this->di_getter)();
  }
}
