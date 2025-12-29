<?php

namespace TangibleDDD\Application\QueryHandlers;

use TangibleDDD\Application\Queries\IQuery;

interface IQueryHandler {
  public function handle(IQuery $query): mixed;
}

