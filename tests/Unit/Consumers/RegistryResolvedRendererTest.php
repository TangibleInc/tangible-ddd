<?php

namespace TangibleDDD\Tests\Unit\Consumers;

use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use TangibleDDD\Domain\Shared\IValueRenderer;
use TangibleDDD\Domain\Shared\JsonLifecycleValue;
use TangibleDDD\Infra\Consumers\ConsumerRegistry;
use TangibleDDD\Infra\DDDConfig;
use TangibleDDD\Tests\Fakes\Acme\Domain\WidgetSpec;

/**
 * JLV renderers resolve per consumer through owner_of() (0.2.5c) — the
 * stamped per-consumer JsonLifecycleValue middle classes existed only to
 * point get_renderer() at the consumer's container.
 *
 * Renderers are optional DECORATION, unlike prefix()/container(): an
 * unowned VO must fall back (explicit global → null), never throw — the
 * framework's own VOs and test fixtures hydrate with no registry at all.
 */
class RegistryResolvedRendererTest extends TestCase {

  protected function setUp(): void {
    ConsumerRegistry::reset();
    JsonLifecycleValue::set_renderer(null);
  }

  protected function tearDown(): void {
    ConsumerRegistry::reset();
    JsonLifecycleValue::set_renderer(null);
  }

  private function uppercasing_container(): ContainerInterface {
    $renderer = new class implements IValueRenderer {
      public function render_data(\stdClass|array|null $data): \stdClass|array {
        $data = (object) $data;
        $data->label = strtoupper((string) $data->label);
        return $data;
      }
    };

    return new class($renderer) implements ContainerInterface {
      public function __construct(private IValueRenderer $renderer) {}
      public function get(string $id): mixed {
        if ($id === IValueRenderer::class) {
          return $this->renderer;
        }
        throw new class extends \Exception implements \Psr\Container\NotFoundExceptionInterface {};
      }
      public function has(string $id): bool { return $id === IValueRenderer::class; }
    };
  }

  public function test_owned_vo_hydrates_through_its_consumers_renderer(): void {
    ConsumerRegistry::add(
      new DDDConfig(prefix: 'acme', namespace_root: 'TangibleDDD\\Tests\\Fakes\\Acme', version: 't'),
      fn () => $this->uppercasing_container(),
    );

    $spec = WidgetSpec::from_json('{"label":"flux capacitor"}');

    $this->assertSame('FLUX CAPACITOR', $spec->label);
  }

  public function test_unowned_vo_falls_back_without_throwing(): void {
    $spec = WidgetSpec::from_json('{"label":"plain"}');

    $this->assertSame('plain', $spec->label);
  }

  public function test_consumer_container_without_renderer_falls_back(): void {
    $bare = new class implements ContainerInterface {
      public function get(string $id): mixed {
        throw new class extends \Exception implements \Psr\Container\NotFoundExceptionInterface {};
      }
      public function has(string $id): bool { return false; }
    };

    ConsumerRegistry::add(
      new DDDConfig(prefix: 'acme', namespace_root: 'TangibleDDD\\Tests\\Fakes\\Acme', version: 't'),
      static fn () => $bare,
    );

    $spec = WidgetSpec::from_json('{"label":"plain"}');

    $this->assertSame('plain', $spec->label);
  }
}
