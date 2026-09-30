<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\ImportRun;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ImportRun>
 */
class ImportRunRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ImportRun::class);
    }

    /**
     * Прогоны списка: от новых к старым.
     *
     * @return ImportRun[]
     */
    public function findAllNewestFirst(): array
    {
        return $this->createQueryBuilder('r')
            ->orderBy('r.createdAt', 'DESC')
            ->addOrderBy('r.id', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Незавершённый прогон, самый свежий (design D9): активен ровно один, и
     * открытие страницы другого прогона перенаправляет на него.
     */
    public function findUnfinished(): ?ImportRun
    {
        return $this->createQueryBuilder('r')
            ->where('r.processedRows < r.totalRows')
            ->orderBy('r.createdAt', 'DESC')
            ->addOrderBy('r.id', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Сумма processedRows по всем прогонам — «обработано всего» в итоговом
     * flash-сообщении (design D11).
     */
    public function sumProcessedRows(): int
    {
        $row = $this->createQueryBuilder('r')
            ->select('COALESCE(SUM(r.processedRows), 0) AS total')
            ->getQuery()
            ->getSingleScalarResult();

        return (int) $row;
    }
}
