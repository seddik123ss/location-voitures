<?php

namespace App\Repository;

use App\Dto\ComplaintFilter;
use App\Entity\Complaint;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<Complaint> */
class ComplaintRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Complaint::class);
    }

    /** @return array{items: list<Complaint>, total: int} plus récentes d'abord */
    public function search(ComplaintFilter $filter): array
    {
        $qb = $this->createQueryBuilder('c');
        $this->applyFilter($qb, $filter);

        $total = (int) (clone $qb)->select('COUNT(c.id)')->getQuery()->getSingleScalarResult();

        /** @var list<Complaint> $items */
        $items = $qb
            ->orderBy('c.createdAt', 'DESC')
            ->addOrderBy('c.id', 'ASC')
            ->setFirstResult($filter->page * $filter->size)
            ->setMaxResults($filter->size)
            ->getQuery()
            ->getResult();

        return ['items' => $items, 'total' => $total];
    }

    private function applyFilter(QueryBuilder $qb, ComplaintFilter $filter): void
    {
        if ($filter->userId !== null) {
            $qb->andWhere('c.userId = :userId')->setParameter('userId', $filter->userId);
        }
        if ($filter->status !== null) {
            $qb->andWhere('c.status = :status')->setParameter('status', $filter->status->value);
        }
        if ($filter->category !== null) {
            $qb->andWhere('c.category = :category')->setParameter('category', $filter->category->value);
        }
        if ($filter->priority !== null) {
            $qb->andWhere('c.priority = :priority')->setParameter('priority', $filter->priority->value);
        }
    }
}
