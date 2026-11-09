<?php

namespace App\Repository;

use App\Entity\ComplaintStatusHistory;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<ComplaintStatusHistory> */
class ComplaintStatusHistoryRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ComplaintStatusHistory::class);
    }
}
