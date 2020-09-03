<?php

namespace App\Repository;

use App\Entity\WebHook;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @method WebHook|null find($id, $lockMode = null, $lockVersion = null)
 * @method WebHook|null findOneBy(array $criteria, array $orderBy = null)
 * @method WebHook[]    findAll()
 * @method WebHook[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class WebhookRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, WebHook::class);
    }

    // /**
    //  * @return WebHook[] Returns an array of WebHook objects
    //  */
    /*
    public function findByExampleField($value)
    {
        return $this->createQueryBuilder('w')
            ->andWhere('w.exampleField = :val')
            ->setParameter('val', $value)
            ->orderBy('w.id', 'ASC')
            ->setMaxResults(10)
            ->getQuery()
            ->getResult()
        ;
    }
    */

    /*
    public function findOneBySomeField($value): ?WebHook
    {
        return $this->createQueryBuilder('w')
            ->andWhere('w.exampleField = :val')
            ->setParameter('val', $value)
            ->getQuery()
            ->getOneOrNullResult()
        ;
    }
    */
}
