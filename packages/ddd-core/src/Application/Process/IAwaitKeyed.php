<?php

declare(strict_types=1);

namespace TangibleDDD\Application\Process;

/**
 * A fact that answers keyed awaits (D3): it reports the key a process minted
 * for it (a job id, a request ref; see LongProcess::step_ref()). A keyed
 * AwaitEvent / AwaitAll accepts the fact only when await_key() equals its
 * key, and the runner narrows the lookup with
 * IProcessStore::findWaitingFor($class, $key).
 *
 * Return null (or '') when this instance answers no keyed await.
 */
interface IAwaitKeyed {

  public function await_key(): ?string;
}
