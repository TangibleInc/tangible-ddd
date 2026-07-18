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
    private readonly ?string $custom_namespace_root = null,
  ) {
    $this->di_getter = $di_getter;
  }

  /**
   * The PHP namespace subtree this consumer owns — the axis owner_of()
   * resolves on. Defaults to the config class's own namespace with a
   * trailing \Infra segment stripped (the scaffolder stamps Config at
   * <root>\Infra\Config); pass an explicit root to boot() when the config
   * lives elsewhere. Empty string for un-namespaced/anonymous configs —
   * such a consumer owns nothing.
   */
  public function namespace_root(): string {
    if ($this->custom_namespace_root !== null) {
      return $this->custom_namespace_root;
    }

    $class = get_class($this->config);
    $cut = strrpos($class, '\\');
    if ($cut === false) {
      return '';
    }

    $namespace = substr($class, 0, $cut);
    if (str_ends_with($namespace, '\\Infra')) {
      $namespace = substr($namespace, 0, -strlen('\\Infra'));
    }

    return $namespace;
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
