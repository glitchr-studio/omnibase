<?php

namespace Base\Entity\Hours;

use Base\Repository\Hours\ScopedWeekRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * The usual week of one place, when a site has several: a shop among the
 * shops, a practice among the practices. The place is a scope key - any
 * string its owner chooses ("store:12"), or an entity
 * (Base\Service\OpeningHours::scopeOf()).
 *
 * The site's own week stays where it was (WeekDayHours, one row per day,
 * else base.opening_hours.week): a place without a row here follows it.
 * One row per place, the seven days together: {"1": [["09:00","13:00"]], ...}.
 */
#[ORM\Entity(repositoryClass: ScopedWeekRepository::class)]
#[ORM\Table(name: 'hoursScopedWeek')]
#[ORM\UniqueConstraint(name: 'hours_scoped_week_scope_uniq', columns: ['scope'])]
class ScopedWeek
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    protected ?int $id = null;

    #[ORM\Column(type: 'string', length: 190)]
    protected string $scope;

    /** @var array<int, array<array{0: string, 1: string}>> ISO day (1 = Monday) => hours */
    #[ORM\Column(type: 'json')]
    protected array $week = [];

    /** @param array<int, array<array{0: string, 1: string}>> $week */
    public function __construct(string $scope, array $week = [])
    {
        $scope = trim($scope);
        if ('' === $scope || \strlen($scope) > 190) {
            throw new \InvalidArgumentException('A scope is a key of 1 to 190 characters.');
        }
        $this->scope = $scope;
        $this->setWeek($week);
    }

    public function getId(): ?int { return $this->id; }
    public function getScope(): string { return $this->scope; }

    /** @return array<int, array<array{0: string, 1: string}>> all seven days */
    public function getWeek(): array
    {
        $week = [];
        for ($n = 1; $n <= 7; ++$n) {
            $week[$n] = array_values($this->week[$n] ?? $this->week[(string) $n] ?? []);
        }

        return $week;
    }

    /** @param array<int, array<array{0: string, 1: string}>> $week ISO day => hours, in order, closing after opening */
    public function setWeek(array $week): static
    {
        $full = [];
        for ($n = 1; $n <= 7; ++$n) {
            // The same checks as a day of the site's week.
            $full[$n] = (new WeekDayHours($n, $week[$n] ?? []))->getHours();
        }
        $this->week = $full;

        return $this;
    }
}
