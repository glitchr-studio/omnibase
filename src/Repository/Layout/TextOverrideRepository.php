<?php

namespace Base\Repository\Layout;

use Base\Database\Repository\ServiceEntityRepository;
use Base\Entity\Layout\TextOverride;

/**
 * @method TextOverride|null find($id, $lockMode = null, $lockVersion = null)
 * @method TextOverride[]    findAll()
 */
class TextOverrideRepository extends ServiceEntityRepository
{
    /** @return array<string, string> every rewritten text, by "domain|locale|key" */
    public function map(): array
    {
        $map = [];
        foreach ($this->createQueryBuilder('t')->select('t.domain, t.locale, t.key, t.value')->getQuery()->getArrayResult() as $row) {
            $map[$row['domain'].'|'.$row['locale'].'|'.$row['key']] = $row['value'];
        }

        return $map;
    }
}
