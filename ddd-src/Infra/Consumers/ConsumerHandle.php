<?php

namespace TangibleDDD\Infra\Consumers;

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
   * resolves on. Resolution order:
   *
   *   1. explicit boot() override;
   *   2. the config's own declaration (DDDConfig carries namespace_root as
   *      a ctor arg; hand-written configs may duck-type the method);
   *   3. derived from the config CLASS's namespace, trailing \Infra
   *      stripped (the pre-0.2.5 stamped layout: <root>\Infra\Config).
   *
   * Empty string for un-namespaced/anonymous configs — owns nothing.
   */
  public function namespace_root(): string {
    if ($this->custom_namespace_root !== null) {
      return $this->custom_namespace_root;
    }

    if (is_callable([$this->config, 'namespace_root'])) {
      return (string) $this->config->namespace_root();
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
