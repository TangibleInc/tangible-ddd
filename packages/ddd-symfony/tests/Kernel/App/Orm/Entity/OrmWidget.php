<?php

declare(strict_types=1);

namespace TangibleDDD\Symfony\Tests\Kernel\App\Orm\Entity;

use Doctrine\ORM\Mapping as ORM;

/** An ORM-mapped row for the L6/L8 kernel tests; `name` is unique so a flush can conflict. */
#[ORM\Entity]
#[ORM\Table(name: 'app_orm_widgets')]
class OrmWidget {

  public function __construct(
    #[ORM\Id]
    #[ORM\Column(type: 'string')]
    public string $id,
    #[ORM\Column(type: 'string', unique: true)]
    public string $name,
  ) {}
}
