<?php

namespace TangibleDDD\Tests\Unit\Exceptions;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use TangibleDDD\Application\Exceptions\ApplicationException;
use TangibleDDD\Domain\Exceptions\InvariantException;
use TangibleDDD\Domain\Exceptions\IncorrectUsage;
use TangibleDDD\Infra\Consumers\NoConsumerOwnsClass;
use TangibleDDD\Infra\Exceptions\IncorrectUsageException;

/**
 * Programming errors are IncorrectUsage, an \Error: a consumer's
 * catch (\Exception), the usual WordPress idiom, must not swallow them
 * (ADR 0018). Runtime and domain failures stay \Exception.
 */
class IncorrectUsageTest extends TestCase {

  /** @return array<string, array{class-string}> */
  public static function incorrect_usages(): array {
    $classes = [
      \TangibleDDD\Application\Exceptions\CommandDispatchedInsideCommand::class,
      \TangibleDDD\Application\Exceptions\DomainEventAfterSealException::class,
      \TangibleDDD\Application\Exceptions\SelfHandlingCommandHasNoHandler::class,
      \TangibleDDD\Application\Exceptions\SelfHandlingCommandWrapsHandler::class,
      \TangibleDDD\Application\Exceptions\UnresolvableHandleDependency::class,
      \TangibleDDD\Application\Process\ProcessStartedInsideCommand::class,
      \TangibleDDD\Application\Process\ProcessStartedInsideProcess::class,
      \TangibleDDD\Application\Process\AwaitedEventNotRegistered::class,
      \TangibleDDD\Infra\Services\FactPublishedInsideProcess::class,
      \TangibleDDD\Domain\Events\AlreadyIntegrated::class,
      \TangibleDDD\Domain\Events\TouchesNonAggregate::class,
      \TangibleDDD\Domain\Events\NonReversibleValue::class,
      IncorrectUsageException::class,
    ];
    return array_combine($classes, array_map(fn( $c ) => [$c], $classes));
  }

  #[DataProvider('incorrect_usages')]
  public function test_a_programming_error_is_an_incorrect_usage_error( string $class ): void {
    $this->assertTrue(is_subclass_of($class, IncorrectUsage::class), "$class must extend IncorrectUsage");
    $this->assertTrue(is_subclass_of($class, \Error::class), "$class must be an \\Error");
    $this->assertFalse(is_subclass_of($class, \Exception::class), "$class must not be an \\Exception");
  }

  public function test_catch_exception_does_not_swallow_it(): void {
    $this->expectException(IncorrectUsage::class);

    try {
      throw new IncorrectUsage('wired wrong');
    } catch( \Exception $e ) {
      $this->fail('catch (\Exception) caught an IncorrectUsage');
    }
  }

  /** @return array<string, array{class-string}> */
  public static function runtime_failures(): array {
    return [
      'domain invariant' => [InvariantException::class],
      'application failure' => [ApplicationException::class],
      // An absent plugin is survivable, so IntegrationHookName catches it (0.6.6)
      'unowned class' => [NoConsumerOwnsClass::class],
    ];
  }

  #[DataProvider('runtime_failures')]
  public function test_a_runtime_failure_stays_an_exception( string $class ): void {
    $this->assertTrue(is_subclass_of($class, \Exception::class), "$class must stay an \\Exception");
  }

  public function test_the_framework_never_throws_a_bare_logic_exception(): void {
    $root = dirname(__DIR__, 3);
    $offenders = [];

    foreach( ['ddd-src', 'ddd-wordpress'] as $dir ) {
      $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator("$root/$dir", \FilesystemIterator::SKIP_DOTS));
      foreach( $files as $file ) {
        if( $file->getExtension() !== 'php' ) continue;
        foreach( file($file->getPathname()) as $n => $line ) {
          if( preg_match('/throw new \\\\?LogicException\b/', $line) ) {
            $offenders[] = substr($file->getPathname(), strlen($root) + 1) . ':' . ($n + 1);
          }
        }
      }
    }

    $this->assertSame([], $offenders, 'Throw IncorrectUsage for a programming error, not \\LogicException');
  }
}
