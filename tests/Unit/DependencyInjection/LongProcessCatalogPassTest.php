<?php

namespace TangibleDDD\Tests\Unit\DependencyInjection;

require_once __DIR__ . '/../../../ddd-src/Application/Process/LongProcessCatalog.php';
require_once __DIR__ . '/../../../ddd-src/Infra/DependencyInjection/LongProcessCatalogPass.php';
require_once __DIR__ . '/../../../ddd-src/Infra/DependencyInjection/DDDCompilerPasses.php';

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use TangibleDDD\Application\Process\LongProcess;
use TangibleDDD\Application\Process\LongProcessCatalog;
use TangibleDDD\Infra\DependencyInjection\DDDCompilerPasses;
use TangibleDDD\Tests\Fakes\FakeResolvedEvent;

class LongProcessCatalogPassTest extends TestCase {

  protected function setUp(): void {
    RequiredConstructorProcess::$constructions = 0;
  }

  public function test_registers_an_empty_public_catalog(): void {
    $builder = new ContainerBuilder();

    DDDCompilerPasses::register($builder);
    $builder->compile();

    $this->assertTrue($builder->has(LongProcessCatalog::class));
    $this->assertSame([], $builder->get(LongProcessCatalog::class)->all());
  }

  public function test_compiles_tagged_process_types_without_constructing_them(): void {
    $builder = new ContainerBuilder();
    $builder->register(RequiredConstructorProcess::class)
      ->setAutowired(false)
      ->setPublic(false)
      ->addTag('ddd.long_process');

    DDDCompilerPasses::register($builder);
    $builder->compile();

    $this->assertSame(
      [RequiredConstructorProcess::class => [[]]],
      $builder->get(LongProcessCatalog::class)->all(),
    );
    $this->assertSame(0, RequiredConstructorProcess::$constructions);
  }

  public function test_preserves_legacy_tag_attributes(): void {
    $builder = new ContainerBuilder();
    $builder->register(RequiredConstructorProcess::class)
      ->setAutowired(false)
      ->setPublic(false)
      ->addTag('ddd.long_process', [
        'awaits' => [FakeResolvedEvent::class],
      ]);

    DDDCompilerPasses::register($builder);
    $builder->compile();

    $this->assertSame(
      [RequiredConstructorProcess::class => [[
        'awaits' => [FakeResolvedEvent::class],
      ]]],
      $builder->get(LongProcessCatalog::class)->all(),
    );
  }

  public function test_merges_duplicate_definitions_under_one_process_class(): void {
    $builder = new ContainerBuilder();
    $builder->register('first_process_definition', RequiredConstructorProcess::class)
      ->setAutowired(false)
      ->setPublic(false)
      ->addTag('ddd.long_process', ['source' => 'first']);
    $builder->register('second_process_definition', RequiredConstructorProcess::class)
      ->setAutowired(false)
      ->setPublic(false)
      ->addTag('ddd.long_process', ['source' => 'second']);

    DDDCompilerPasses::register($builder);
    $builder->compile();

    $this->assertSame(
      [RequiredConstructorProcess::class => [
        ['source' => 'first'],
        ['source' => 'second'],
      ]],
      $builder->get(LongProcessCatalog::class)->all(),
    );
  }

  public function test_rejects_a_tagged_non_process_definition(): void {
    $builder = new ContainerBuilder();
    $builder->register(\stdClass::class)
      ->setPublic(false)
      ->addTag('ddd.long_process');

    DDDCompilerPasses::register($builder);

    $this->expectException(InvalidArgumentException::class);
    $this->expectExceptionMessage('stdClass is tagged ddd.long_process but does not extend LongProcess');

    $builder->compile();
  }
}

final class RequiredConstructorProcess extends LongProcess {

  public static int $constructions = 0;

  public function __construct(public readonly int $account_id) {
    ++self::$constructions;
    parent::__construct(null);
  }
}
