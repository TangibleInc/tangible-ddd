<?php

namespace Tangible\Cred\Infra;

use TangibleDDD\Infra\IDDDConfig;

class Config implements IDDDConfig {

  public function __construct(
    private readonly string $version = 'dev',
  ) {}

  public function prefix(): string {
    return 'tgbl_cred';
  }

  public function table(string $name): string {
    global $wpdb;
    return $wpdb->prefix . 'tgbl_cred_' . $name;
  }

  public function hook(string $name): string {
    return 'tgbl_cred_' . $name;
  }

  public function as_group(string $name): string {
    return 'tgbl-cred-' . $name;
  }

  public function option(string $name): string {
    return 'tgbl_cred_' . $name;
  }

  public function domain_action(string $event_name): string {
    return 'tgbl_cred_domain_' . $event_name;
  }

  public function integration_action(string $event_name): string {
    return 'tgbl_cred_integration_' . $event_name;
  }

  public function version(): string {
    return $this->version;
  }
}
