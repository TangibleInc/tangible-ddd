<?php

declare(strict_types=1);

namespace TangibleDDD\Symfony\Console\Ops;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use TangibleDDD\Runtime\Effects\IEffectJournal;
use TangibleDDD\Runtime\Effects\ITracksEffectState;
use TangibleDDD\Runtime\ITransactionBoundary;

/**
 * `ddd:ops:effects:invalidate <key>... [--reason=...]` (E2, wave 5): the
 * `invalidate` repair of the operator view's `effect` layer. Each key is
 * invalidated in its own transaction (IEffectJournal::invalidate()), so the
 * effect performs again on its next dispatch; re-dispatching it is the
 * operator's (or the compensation's) next step. The primary consumer's
 * journal.
 *
 * Every key is attempted; the command fails if any key failed or, on a
 * journal that tracks entry states, has no live entry.
 */
#[AsCommand(name: 'ddd:ops:effects:invalidate', description: 'Invalidate effect journal entries so the effect performs again')]
final class EffectsInvalidateCommand extends Command {

  public const DEFAULT_REASON = 'operator: ddd:ops:effects:invalidate';

  public function __construct(
    private readonly IEffectJournal $journal,
    private readonly ITransactionBoundary $boundary,
  ) {
    parent::__construct();
  }

  protected function configure(): void {
    $this->addArgument('key', InputArgument::REQUIRED | InputArgument::IS_ARRAY, 'Idempotency keys (ddd:ops:list --layer=effect)')
      ->addOption('reason', null, InputOption::VALUE_REQUIRED, 'Kept on the journal row for the record', self::DEFAULT_REASON);
  }

  protected function execute(InputInterface $input, OutputInterface $output): int {
    $reason = (string) $input->getOption('reason');
    $exit = Command::SUCCESS;
    foreach ((array) $input->getArgument('key') as $key) {
      $key = (string) $key;
      try {
        if ($this->journal instanceof ITracksEffectState && $this->journal->find_entry($key) === null) {
          $exit = Command::FAILURE;
          $output->writeln("<error>No live journal entry for effect $key.</error>");
          continue;
        }
        $this->boundary->run(fn () => $this->journal->invalidate($key, $reason));
        $output->writeln("Invalidated effect $key; it performs again on its next dispatch.");
      } catch (\Throwable $e) {
        $exit = Command::FAILURE;
        $output->writeln(sprintf('<error>Effect %s not invalidated: %s</error>', $key, $e->getMessage()));
      }
    }
    return $exit;
  }
}
