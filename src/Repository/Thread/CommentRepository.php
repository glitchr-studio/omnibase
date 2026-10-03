<?php

namespace Base\Repository\Thread;

use Base\Database\Repository\ServiceEntityRepository;
use Base\Entity\Thread;
use Base\Entity\Thread\Comment;
use Base\Enum\CommentState;

/**
 * @method Comment|null find($id, $lockMode = null, $lockVersion = null)
 * @method Comment|null findOneBy(array $criteria, array $orderBy = null)
 * @method Comment[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class CommentRepository extends ServiceEntityRepository
{
    /**
     * The comments online under a thread - or the visitors' book when
     * $thread is null -, the top-level ones, newest first; their replies
     * hang under each (Comment::getVisibleReplies()).
     *
     * @return list<Comment>
     */
    public function findVisible(?Thread $thread, int $limit = 200): array
    {
        $query = $this->createQueryBuilder('c')
            ->andWhere('c.state = :approved')->setParameter('approved', CommentState::APPROVED)
            ->andWhere('c.parent IS NULL')
            ->orderBy('c.createdAt', 'DESC')
            ->setMaxResults($limit);
        $query = $thread ? $query->andWhere('c.thread = :thread')->setParameter('thread', $thread) : $query->andWhere('c.thread IS NULL');

        return $query->getQuery()->getResult();
    }

    public function countVisible(?Thread $thread): int
    {
        $query = $this->createQueryBuilder('c')->select('COUNT(c.id)')
            ->andWhere('c.state = :approved')->setParameter('approved', CommentState::APPROVED);
        $query = $thread ? $query->andWhere('c.thread = :thread')->setParameter('thread', $thread) : $query->andWhere('c.thread IS NULL');

        return (int) $query->getQuery()->getSingleScalarResult();
    }

    public function countPending(): int
    {
        return (int) $this->createQueryBuilder('c')->select('COUNT(c.id)')
            ->andWhere('c.state = :pending')->setParameter('pending', CommentState::PENDING)
            ->getQuery()->getSingleScalarResult();
    }

    /** @return list<Comment> */
    public function findPending(int $limit = 20): array
    {
        return $this->findBy(['state' => CommentState::PENDING], ['createdAt' => 'ASC'], $limit);
    }

    /** The last comment this address left, to hold the flood interval. */
    public function findLastFromIp(string $ip): ?Comment
    {
        return $this->findOneBy(['ip' => $ip], ['createdAt' => 'DESC']);
    }
}
