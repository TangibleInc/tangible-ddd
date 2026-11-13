<?php

namespace TangibleDDD\Application\Intents;

use TangibleDDD\Application\Commands\ICommand;
use TangibleDDD\Application\Correlation\TraceContext;

/**
 * An intent captured instead of thrown, waiting for the act to decide it.
 * `origin` is where the entry point sent it (file:line).
 */
final class CapturedIntent extends Intent {

  private Decision $decision = Decision::undecided;
  private ?string $reason = null;

  public function __construct(
    ICommand $command,
    TraceContext $born_in,
    public readonly string $origin,
  ) {
    parent::__construct($command, $born_in);
  }

  public function decision(): Decision {
    return $this->decision;
  }

  public function reason(): ?string {
    return $this->reason;
  }

  public function is_decided(): bool {
    return $this->decision !== Decision::undecided;
  }

  /** @internal Decide through CapturedIntents, not directly. */
  public function decide(Decision $decision, ?string $reason = null): void {
    if( $this->is_decided() ) {
      throw new IntentAlreadyDecided($this);
    }
    $this->decision = $decision;
    $this->reason = $reason;
  }

  public function to_record(): array {
    $record = [
      'command'  => get_class($this->command),
      'origin'   => $this->origin,
      'decision' => $this->decision->value,
    ];
    if( $this->decision === Decision::rejected ) {
      $record['reason'] = $this->reason;
    }
    return $record;
  }
}
