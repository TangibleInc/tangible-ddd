<?php

declare(strict_types=1);

namespace TangibleDDD\Symfony\Runtime\Actor;

use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\Console\Event\ConsoleCommandEvent;
use Symfony\Component\Console\Event\ConsoleTerminateEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use TangibleDDD\Runtime\Audit\Actor;
use TangibleDDD\Runtime\Audit\ActorKind;

/**
 * D5, console operator: while a console command runs, the actor is
 * ActorKind::Cli with id = the operator and label = the command name. The
 * operator is `DDD_OPERATOR` when set (CI, ops runbooks, `sudo -u`), else the
 * OS user running the process.
 *
 * Long-running workers (messenger:consume, ddd:relay) are console commands
 * too; a fact delivered there has no human behind it, so those commands are
 * reported as ActorKind::System unless DDD_OPERATOR says otherwise.
 */
final class ConsoleOperatorActorProvider implements EventSubscriberInterface {

  /** Commands that run unattended: their commands are System, not an operator. */
  private const WORKERS = ['messenger:consume', 'ddd:relay'];

  private ?string $command = null;

  public static function getSubscribedEvents(): array {
    return [
      ConsoleEvents::COMMAND => ['onCommand', 1024],
      ConsoleEvents::TERMINATE => ['onTerminate', -1024],
    ];
  }

  public function onCommand(ConsoleCommandEvent $event): void {
    $this->command = $event->getCommand()?->getName() ?? 'console';
  }

  public function onTerminate(ConsoleTerminateEvent $event): void {
    $this->command = null;
  }

  public function enter(string $commandName): void {
    $this->command = $commandName;
  }

  public function resolve(): ?Actor {
    if ($this->command === null) {
      return null;
    }
    $operator = getenv('DDD_OPERATOR');
    if (($operator === false || $operator === '') && in_array($this->command, self::WORKERS, true)) {
      return new Actor(ActorKind::System, null, $this->command);
    }
    return new Actor(ActorKind::Cli, ($operator === false || $operator === '') ? self::osUser() : $operator, $this->command);
  }

  private static function osUser(): ?string {
    if (function_exists('posix_geteuid') && function_exists('posix_getpwuid')) {
      $pw = posix_getpwuid(posix_geteuid());
      if (is_array($pw) && isset($pw['name'])) {
        return (string) $pw['name'];
      }
    }
    $user = getenv('USER') ?: get_current_user();
    return $user === '' ? null : $user;
  }
}
