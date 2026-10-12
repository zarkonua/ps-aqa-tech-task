<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'tags')]
#[ORM\UniqueConstraint(name: 'uniq_tags_owner_name', columns: ['owner_id', 'name_key'])]
class Tag extends TaxonomyItem
{
}
