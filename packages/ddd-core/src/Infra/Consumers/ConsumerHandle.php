<?php

namespace TangibleDDD\Infra\Consumers;

use TangibleDDD\Infra\IDDDConfig;

/**
 * One DDD config/container routing handle.
 *
 * Top-level handles are visible to discovery surfaces and own persistence,
 * hooks, workers, CLI, and dashboard identity. Module handles live only in
 * ConsumerRegistry's routing overlay: they reuse their host's exact config,
 * expose their module container, and never become a second consumer in all().
 *
 * The getter stays uncalled until container(); registration happens during
 * plugin bootstrap, before it is safe or cheap to build containers.
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
   *   1. explicit boot()/boot_module() override;
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
   * Whether a repeated top-level registration describes this exact handle.
   *
   * @internal Used by ConsumerRegistry to keep a host immutable once modules
   *   share its config and runtime services.
   */
  public function matches_registration(
    IDDDConfig $config,
    callable $di_getter,
    ?string $label = null,
    ?string $namespace_root = null,
  ): bool {
    $candidate = new self($config, $di_getter, $label, $namespace_root);

    return $this->config === $candidate->config
      && $this->di_getter === $candidate->di_getter
      && $this->label() === $candidate->label()
      && $this->namespace_root() === $candidate->namespace_root();
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
