<?php

namespace TangibleDDD\Infra;

/**
 * The framework's concrete IDDDConfig (0.2.5c) — consumers instantiate
 * instead of implement:
 *
 *   boot(
 *     new DDDConfig(prefix: 'tgbl_cred', namespace_root: 'Tangible\\Cred', version: TCRED_VERSION),
 *     fn () => \Tangible\Cred\WordPress\DI\di(),
 *   );
 *
 * The eight derivations below were byte-identical in every consumer's
 * stamped Infra\Config — framework knowledge wearing a consumer costume.
 * namespace_root is explicit: it is the identity ConsumerRegistry::owner_of()
 * resolves on, stated plainly rather than inferred from a class location.
 *
 * Hand-written IDDDConfig implementations remain fully legal (bespoke table
 * naming, multi-prefix schemes); this is the default, not the law.
 */
final class DDDConfig implements IDDDConfig {

  public function __construct(
    private readonly string $prefix,
    private readonly string $namespace_root,
    private readonly string $version,
  ) {}

  public function prefix(): string {
    return $this->prefix;
  }

  /** The PHP namespace subtree this consumer owns — owner_of()'s axis. */
  public function namespace_root(): string {
    return $this->namespace_root;
  }

  public function table(string $name): string {
    global $wpdb;
    return $wpdb->prefix . $this->prefix . '_' . $name;
  }

  public function hook(string $name): string {
    return $this->prefix . '_' . $name;
  }

  public function as_group(string $name): string {
    return $this->prefix . '-' . $name;
  }

  public function option(string $name): string {
    return $this->prefix . '_' . $name;
  }

  public function domain_action(string $event_name): string {
    return $this->prefix . '_domain_' . $event_name;
  }

  public function integration_action(string $event_name): string {
    return $this->prefix . '_integration_' . $event_name;
  }

  public function version(): string {
    return $this->version;
  }
}
