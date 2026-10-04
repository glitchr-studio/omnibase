<?php

namespace Base\Database\Repository;

use Base\Database\Entity\EntityHydrator;
use Base\Database\Mapping\ClassMetadataManipulator;
use Base\Database\Event\DoctrineQueryEventArgs;
use Base\Database\Events;
use Base\Service\Model\IntlDateTime;
use DateTime;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityNotFoundException;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Mapping\MappingException;
use Doctrine\ORM\NonUniqueResultException;
use Doctrine\ORM\PersistentCollection;
use Doctrine\ORM\Query\Expr\Andx;
use Doctrine\ORM\Query\Expr\Comparison;
use Doctrine\ORM\Query\Expr\Func;
use Doctrine\ORM\Query\Expr\Orx;
use Doctrine\Persistence\Proxy;
use Doctrine\ORM\Query;
use Doctrine\ORM\QueryBuilder;
use Exception;
use ReflectionClass;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\KernelEvent;

class ServiceEntityParser
{
    protected $method;
    protected $args;

    public const ALIAS_ENTITY = 'e';

    public const REQUEST_FIND = "find";
    public const REQUEST_CACHE = "cache";
    public const REQUEST_COUNT = "count";
    public const REQUEST_DISTINCT = "distinctCount";
    public const REQUEST_LENGTH = "lengthOf";

    public const LOAD_EAGERLY = "Eagerly";

    public const SPECIAL_ALL = "All";
    public const SPECIAL_ONE = "One";
    public const SPECIAL_RAND = "Randomly";
    public const SPECIAL_ATMOST = "AtMost(?P<atMost>[0-9])*";
    public const SPECIAL_LASTONE = "LastOne";
    public const SPECIAL_LAST = "Last(?P<last>[0-9])*";
    public const SPECIAL_PREVONE = "PreviousOne";
    public const SPECIAL_PREV = "Previous(?P<prev>[0-9]*)";
    public const SPECIAL_NEXTONE = "NextOne";
    public const SPECIAL_NEXT = "Next(?P<next>[0-9]*)";

    protected static function getSpecial(string $special): string
    {
        return only_alphanumerics(trim_brackets($special));
    }

    protected static function getSpecials(): array
    {
        return [self::SPECIAL_ALL, self::SPECIAL_ONE, self::SPECIAL_ATMOST, self::SPECIAL_LAST, self::SPECIAL_LASTONE,
            self::SPECIAL_RAND, self::SPECIAL_PREVONE, self::SPECIAL_NEXTONE, self::SPECIAL_PREV, self::SPECIAL_NEXT];
    }

    // Default options without argument
    public const OPTION_EMPTY = "Empty";
    public const OPTION_NOT_EMPTY = "NotEmpty";
    public const OPTION_TRUE = "True";
    public const OPTION_NOT_TRUE = "NotTrue";
    public const OPTION_FALSE = "False";
    public const OPTION_NOT_FALSE = "NotFalse";
    public const OPTION_NULL = "Null";
    public const OPTION_NOT_NULL = "NotNull";

    // Default options
    public const OPTION_EQUAL = "EqualTo";
    public const OPTION_NOT_EQUAL = "NotEqualTo";
    public const OPTION_GREATER = "GreaterThan";
    public const OPTION_GREATER_EQUAL = "GreaterEqualTo";
    public const OPTION_LOWER = "LowerThan";
    public const OPTION_LOWER_EQUAL = "LowerEqualTo";

    // Datetime related options
    public const OPTION_WITHIN = "Within";
    public const OPTION_OLDER = "OlderThan";
    public const OPTION_OLDER_EQUAL = "OlderEqualTo";
    public const OPTION_YOUNGER = "YoungerThan";
    public const OPTION_YOUNGER_EQUAL = "YoungerEqualTo";
    public const OPTION_OVER = "IsOver";
    public const OPTION_NOT_OVER = "IsNotOver";

    // String related options
    public const OPTION_STARTING_WITH = "StartingWith";
    public const OPTION_ENDING_WITH = "EndingWith";
    public const OPTION_NOT_STARTING_WITH = "NotStartingWith";
    public const OPTION_NOT_ENDING_WITH = "NotEndingWith";

    // Custom options
    public const OPTION_INSENSITIVE = "Insensitive";
    public const OPTION_WITH_ROUTE = "WithRoute";
    public const OPTION_PARTIAL = "Partial";
    public const OPTION_MODEL = "Model";
    public const OPTION_CLASSOF = "ClassOf";
    public const OPTION_NOT_CLASSOF = "NotClassOf";
    public const OPTION_INSTANCEOF = "InstanceOf";
    public const OPTION_NOT_INSTANCEOF = "NotInstanceOf";
    public const OPTION_MEMBEROF = "MemberOf";
    public const OPTION_NOT_MEMBEROF = "NotMemberOf";
    public const OPTION_BUT = "But";

    public const OPTION_CLOSESTTO = "ClosestTo";
    public const OPTION_FARESTTO = "FarestTo";

    // Separators
    public const SEPARATOR = ":"; // Field separator
    public const SEPARATOR_OR = "Or";
    public const SEPARATOR_AND = "And";
    public const SEPARATOR_FOR = "For";
    public const SEPARATOR_BY = "By";

    // Count options
    public const COUNT_ALL = "ALL";
    public const COUNT_DISTINCT = "DISTINCT";

    protected $operator = null;
    protected mixed $column = null;
    protected bool $cacheable = false;
    protected bool $eagerly = false;
    protected array $criteria = [];
    protected array $options = [];

    protected mixed $classMetadata = null;

    /**
     * @var ClassMetadataManipulator|null
     */
    protected ?ClassMetadataManipulator $classMetadataManipulator = null;

    /**
     * @var ServiceEntityRepository
     */
    protected ServiceEntityRepository $serviceEntity;

    /**
     * @var EntityHydrator
     */
    protected EntityHydrator $entityHydrator;

    /**
     * @var EntityManager
     */
    protected EntityManager $entityManager;

    public function __construct(ServiceEntityRepository $serviceEntity, EntityManager $entityManager, ClassMetadataManipulator $classMetadataManipulator, EntityHydrator $entityHydrator)
    {
        $this->serviceEntity = $serviceEntity;
        $this->entityHydrator = $entityHydrator;
        $this->entityManager = $entityManager;
        $this->classMetadataManipulator = $classMetadataManipulator;

        $this->classMetadata = $entityManager->getClassMetadata($serviceEntity->getFqcnEntityName());

        $this->criteria = [];
        $this->options = [];
        $this->operator = null;
        $this->eagerly = false;

        foreach (self::getSpecials() as $special) {
            $special = self::getSpecial($special);
            if ($special == self::SPECIAL_ALL) {
                continue;
            }

            $specialMethod = "__" . self::REQUEST_FIND . $special . self::SEPARATOR_BY;
            if (!method_exists($this, $specialMethod)) {
                throw new Exception("Special \"$special\" option is missing its find method : " . $specialMethod);
            }
        }
    }

    /**
     * @param array $criteria
     * @param array|null $orderBy
     * @param $limit
     * @param $offset
     * @param array|null $groupBy
     * @param array|null $selectAs
     * @return Query|null
     * @throws Exception
     */
    protected function __findBy(array $criteria = [], ?array $orderBy = null, $limit = null, $offset = null, ?array $groupBy = null, ?array $selectAs = null): ?Query
    {
        return $this->getQuery($criteria, $orderBy, $limit, $offset, $groupBy, $selectAs);
    }

    /**
     * @param array $criteria
     * @param array|null $orderBy
     * @param $limit
     * @param $offset
     * @param array|null $groupBy
     * @param array|null $selectAs
     * @return Query|null
     */
    protected function __findRandomlyBy(array $criteria = [], ?array $orderBy = null, ?int $limit = null, ?int $offset = null, ?array $groupBy = null, ?array $selectAs = null): ?Query
    {
        return $this->__findBy($criteria, array_merge(["id" => "rand"], $orderBy ?? []), $limit, $offset, $groupBy, $selectAs);
    }

    protected function __findAll(?array $orderBy = null, ?array $groupBy = null, ?array $selectAs = null): ?Query
    {
        return $this->__findBy([], $orderBy, null, null, $groupBy, $selectAs);
    }

    /**
     * @param array $criteria
     * @param array|null $orderBy
     * @param array|null $groupBy
     * @param array|null $selectAs
     * @return float|int|mixed|string|null
     * @throws NonUniqueResultException
     */
    protected function __findOneBy(array $criteria = [], ?array $orderBy = null, ?int $offset = null, ?array $groupBy = null, ?array $selectAs = null)
    {
        $results = $this->__findBy($criteria, $orderBy, 1, $offset, $groupBy, $selectAs);
        return $results->getOneOrNullResult();
    }

    /**
     * @param array $criteria
     * @param array|null $orderBy
     * @param array|null $groupBy
     * @param array|null $selectAs
     * @return float|int|mixed|string|null
     */
    protected function __findLastOneBy(array $criteria = [], ?array $orderBy = null, ?array $offset = null, ?array $groupBy = null, ?array $selectAs = null)
    {
        foreach($orderBy ?? [] as $field => $order)
            $orderBy[$field] = ($order == "DESC") ? "ASC" : "DESC";

        return $this->__findOneBy($criteria, array_merge($orderBy ?? [], ['id' => 'DESC']), $offset, $groupBy, $selectAs) ?? null;
    }

    protected function __findLastBy(array $criteria = [], ?array $orderBy = null, ?array $offset = null, ?array $groupBy = null, ?array $selectAs = null): ?Query
    {
        foreach($orderBy ?? [] as $field => $order)
            $orderBy[$field] = ($order == "DESC") ? "ASC" : "DESC";

        $limit = array_pop_key("special:last", $criteria) ?? 1;
        return $this->__findBy($criteria, array_merge($orderBy ?? [], ['id' => 'DESC']), $limit, $offset, $groupBy, $selectAs) ?? null;
    }

    protected function __findAtMostBy(array $criteria = [], ?array $orderBy = null, ?int $offset = null, ?array $groupBy = null, ?array $selectAs = null): ?Query
    {
        $limit = array_pop_key("special:atMost", $criteria);
        return $this->__findBy($criteria, $orderBy, $limit, $offset, $groupBy, $selectAs);
    }

    protected function __findPreviousBy(array $criteria = [], ?array $orderBy = null, ?array $offset = null, ?array $groupBy = null, ?array $selectAs = null): ?Query
    {
        foreach($orderBy ?? [] as $field => $order) // Flipping previous order provided..
            $orderBy[$field] = ($order == "DESC") ? "ASC" : "DESC";

        $limit = array_pop_key("special:prev", $criteria) ?? 1;
        $specialKeys = array_pop_key("special:prevKeys", $criteria) ?? [];
        foreach($specialKeys ?? [] as $field)
            $orderBy[$field] = "DESC";
        
        return $this->__findBy($criteria, array_merge($orderBy ?? [], ['id' => 'DESC']), $limit, $offset, $groupBy, $selectAs);
    }

    /**
     * @param array $criteria
     * @param array|null $orderBy
     * @param array|null $groupBy
     * @param array|null $selectAs
     * @return float|int|mixed|string|null
     */
     protected function __findPreviousOneBy(array $criteria = [], ?array $orderBy = null, ?array $offset = null, ?array $groupBy = null, ?array $selectAs = null)
     {
        return $this->__findPreviousBy($criteria, $orderBy, $offset, $groupBy, $selectAs)->GetOneOrNullResult();
     }
 
    protected function __findNextBy(array $criteria = [], ?array $orderBy = null, ?array $offset = null, ?array $groupBy = null, ?array $selectAs = null): ?Query
    {
        $limit = array_pop_key("special:next", $criteria) ?? 1;
        $specialKeys = array_pop_key("special:nextKeys", $criteria) ?? [];
        foreach($specialKeys ?? [] as $field)
            $orderBy[$field] = "ASC";

        return $this->__findBy($criteria, array_merge($orderBy ?? [], ['id' => 'ASC']), $limit, $offset, $groupBy, $selectAs);
    }

    /**
     * @param array $criteria
     * @param array|null $orderBy
     * @param array|null $groupBy
     * @param array|null $selectAs
     * @return float|int|mixed|string|null
     */
    protected function __findNextOneBy(array $criteria = [], ?array $orderBy = null, ?array $offset = null, ?array $groupBy = null, ?array $selectAs = null)
    {
        return $this->__findNextBy($criteria, $orderBy, $offset, $groupBy, $selectAs)->GetOneOrNullResult();
    }

    /**
     * @param array $criteria
     * @param array|null $orderBy
     * @param array|null $groupBy
     * @param $limit
     * @param $offset
     * @param array|null $selectAs
     * @return float|int|mixed|string
     * @throws Exception
     */
    protected function __lengthOfBy(array $criteria = [], ?array $orderBy = null, ?array $groupBy = null, $limit = null, $offset = null, ?array $selectAs = null)
    {
        return $this->getQueryWithLength($criteria, $orderBy, $limit, $offset, $groupBy, $selectAs)->getResult();
    }

    /**
     * @return int|array an integer; rows of counts when grouped
     */
    protected function __distinctCountBy(array $criteria = [], ?array $groupBy = null, ?array $selectAs = null): int|array
    {
        return $this->__countBy($criteria, self::COUNT_DISTINCT, null, $groupBy, $selectAs);
    }

    /**
     * Counts the rows matching $criteria: an integer. Grouped (a $groupBy or a
     * $selectAs), it gives the rows of the grouped query instead, each with
     * its "count" (ThreadRepository::countForChildrenIn reads them so).
     *
     * Ungrouped, the query used to select the entity next to COUNT() and group
     * by its id, so count([]) came back as one row per entity - an array where
     * ServiceEntityRepository::count() promises an int.
     *
     * @param array $criteria
     * @param string|null $mode
     * @param array|null $orderBy
     * @param array|null $groupBy
     * @param array|null $selectAs
     * @return int|array
     * @throws Exception
     */
    protected function __countBy(array $criteria = [], ?string $mode = null, ?array $orderBy = null, ?array $groupBy = null, ?array $selectAs = null)
    {
        $mode = $mode ?: self::COUNT_ALL;
        $query = $this->getQueryWithCount($criteria, $mode, $orderBy, $groupBy, $selectAs);
        if ($query === null) {
            return 0;
        }

        if (empty($groupBy) && empty($selectAs)) {
            return (int) $query->getSingleScalarResult();
        }

        return $query->getResult();
    }


    /**
     * @param $method
     * @param $arguments
     * @return mixed
     * @throws MappingException
     */
    public function parse($method, $arguments): mixed
    {
        // Parse method and call it
        list($method, $arguments) = $this->__parse($method, $arguments);

        try {
            $ret = $this->$method(...$arguments);
        } finally { // Reset internal variables, even if exception happens.
            // (e.g. wrong Query in Controller, but additional queries in Subscriber)

            $this->criteria = [];
            $this->options = [];
            $this->operator = null;
            $this->cacheable = false;
            $this->eagerly = false;
        }

        return $ret;
    }

    /**
     * @param $alias
     * @return string
     */
    protected function getAlias($alias)
    {
        return $this->classMetadataManipulator->getFieldName($this->classMetadata->name, $alias) ?? $alias;
    }

    /**
     * @param string|null $by
     * @param $value
     * @return string
     * @throws Exception
     */
    protected function addCriteria(?string $by, $value)
    {
        if ($by != null && empty($by)) {
            throw new Exception("Tried to add unnamed criteria");
        }

        $index = 0;
        while (array_key_exists($by . self::SEPARATOR . $index, $this->criteria)) {
            $index++;
        }

        $this->criteria[$by . self::SEPARATOR . $index] = $value;
        return $by . self::SEPARATOR . $index;
    }

    /**
     * @return mixed|null
     */
    protected function getColumn()
    {
        return $this->column;
    }

    /**
     * @param string $column
     * @return $this
     */
    protected function setColumn(string $column)
    {
        $this->column = $column;
        return $this;
    }

    /**
     * @return mixed|null
     */
    protected function getSeparator()
    {
        return $this->operator;
    }

    /**
     * @param $operator
     * @return $this
     */
    protected function setSeparator($operator)
    {
        $this->operator = $operator;
        return $this;
    }

    /**
     * @param $method
     * @param $by
     * @param $option
     * @return array
     */
    protected function stripByFront($method, $by, $option)
    {
        $method = str_starts_with($method, $option) ? substr($method, strlen($option), strlen($method)) : $method;
        $by = str_starts_with($by, $option) ? substr($by, strlen($option), strlen($by)) : $by;

        return [$method, $by];
    }

    /**
     * @param $method
     * @param $by
     * @param $option
     * @return array
     */
    protected function stripByEnd($method, $by, $option)
    {
        $method = str_ends_with($method, $option) ? substr($method, 0, strpos($method, $option)) : $method;
        $by = str_ends_with($by, $option) ? substr($by, 0, strlen($by) - strlen($option)) : $by;

        return [$method, $by];
    }

    /**
     * @param $date
     * @param $format
     * @return bool
     * @throws Exception
     */
    protected function validateDate($date, $format = 'Y-m-d H:i:s')
    {
        $d = DateTime::createFromFormat($format, $date);
        return $d && IntlDateTime::createFromDateTime($d)->format($format) == $date;
    }

    /**
     * @param string $id
     * @return array|mixed
     */
    public function getCustomOption(string $id)
    {
        return $this->options[$id] ?? [];
    }

    /**
     * @param string $id
     * @param string $option
     * @return bool
     */
    public function findCustomOption(string $id, string $option)
    {
        return in_array($option, $this->getCustomOption($id));
    }

    protected function addCustomOption(string $id, string $option)
    {
        if (!array_key_exists($id, $this->criteria)) {
            throw new Exception("Criteria ID \"$id\" not found in criteria list.. wrong usage? use \"addCriteria\" first.");
        }

        if (!array_key_exists($id, $this->options)) {
            $this->options[$id] = [];
        }

        $this->options[$id][] = $option;
    }

    /**
     * A bare clause is a Query-by-Example select clause when it names the generic
     * "Model" keyword OR the entity's own / an ancestor's short class name
     * (findByModel / findBySubscriber / findByThread). A real field or association
     * of the entity always takes precedence.
     */
    protected function isSelectClause(string $by): bool
    {
        $name = lcfirst($by);
        if ($this->classMetadata->hasField($name) || $this->classMetadata->hasAssociation($name)) {
            return false;
        }

        if ($by === self::OPTION_MODEL) {
            return true;
        }

        $entity = $this->classMetadata->getName();
        foreach (array_merge([$entity], array_values(class_parents($entity) ?: [])) as $class) {
            $short = ($pos = strrpos($class, "\\")) !== false ? substr($class, $pos + 1) : $class;
            if ($by === $short) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param $method
     * @param $arguments
     * @return array
     * @throws MappingException
     */
    protected function __parse($method, $arguments)
    {
        $method = str_strip($method, "__");
        $method = explode("::", $method)[1] ?? $method;

        // Head parameters (depends on the method name) + default ones
        // Default parameters:
        // - "MAGIC ARGUMENTS"
        // - "MAGIC ARGUMENTS"+0: criteria
        // - "MAGIC ARGUMENTS"+1: orderBy
        // - "MAGIC ARGUMENTS"+2: limit
        // - "MAGIC ARGUMENTS"+3: offset
        // - "MAGIC ARGUMENTS"+4: groupBy
        // - "MAGIC ARGUMENTS"+5: selectAs

        // Definition of the returned values
        $magicFn = null;
        $magicArgs = [];

        foreach ($this->classMetadataManipulator->getFieldNames($this->classMetadata->name) as $field) {
            $conflictingOption = null;
            $optionsToCheck = [
                self::OPTION_WITH_ROUTE,
                self::OPTION_BUT,
                self::OPTION_MODEL,
                self::OPTION_INSTANCEOF,
                self::OPTION_NOT_INSTANCEOF,
                self::OPTION_CLASSOF,
                self::OPTION_NOT_CLASSOF,
                self::OPTION_MEMBEROF,
                self::OPTION_NOT_MEMBEROF,
                self::OPTION_PARTIAL,
                self::OPTION_INSENSITIVE,
                self::OPTION_CLOSESTTO,
                self::OPTION_FARESTTO,
                self::OPTION_STARTING_WITH,
                self::OPTION_ENDING_WITH,
                self::OPTION_NOT_STARTING_WITH,
                self::OPTION_NOT_ENDING_WITH,
                self::OPTION_LOWER,
                self::OPTION_GREATER,
                self::OPTION_LOWER_EQUAL,
                self::OPTION_GREATER_EQUAL,
                self::OPTION_OVER,
                self::OPTION_NOT_OVER,
                self::OPTION_YOUNGER,
                self::OPTION_OLDER,
                self::OPTION_WITHIN,
                self::OPTION_YOUNGER_EQUAL,
                self::OPTION_OLDER_EQUAL,
                self::OPTION_EQUAL,
                self::OPTION_NOT_EQUAL,
                self::OPTION_NULL,
                self::OPTION_NOT_NULL,
                self::OPTION_EMPTY,
                self::OPTION_NOT_EMPTY,
                self::OPTION_TRUE,
                self::OPTION_NOT_TRUE,
                self::OPTION_FALSE,
                self::OPTION_NOT_FALSE,
                self::SEPARATOR_AND,
                self::SEPARATOR_OR,
                self::SEPARATOR
            ];
            foreach ($optionsToCheck as $option) {
                $pos = strpos($field, $option);
                if ($pos !== false) {
                    $nextChar = substr($field, $pos + strlen($option), 1);
                    if ($nextChar !== '' && ctype_upper($nextChar)) {
                        $conflictingOption = $option;
                        break;
                    }
                }
            }
            if ($conflictingOption !== null) {
                throw new Exception(
                    "\"" . $this->serviceEntity->getFqcnEntityName() . "\" entity has a field called \"$field\". " .
                    "This is unfortunate, because this word (\"$conflictingOption\") is used to customize DQL queries. " .
                    "Please build your own DQL query or change your database field name"
                );
            }
        }

        // Find and sort the "With" list
        $withs = array_filter([
            self::OPTION_WITH_ROUTE => strpos($method, self::OPTION_WITH_ROUTE)
            /* ... more type of options here */
        ], fn($value) => ($value !== false));
        asort($withs);


        // Here are the resulting parameters
        $routeParameters = null;
        foreach (array_keys($withs) as $with) {
            switch ($with) {
                // If variable with route parameter found.
                // Extract request from the provided arguments
                case self::OPTION_WITH_ROUTE:

                    $arrayOrEventOrRequest = array_shift($arguments);
                    if (!is_array($arrayOrEventOrRequest)) {
                        $request =
                            ($arrayOrEventOrRequest instanceof Request ? $arrayOrEventOrRequest :
                                ($arrayOrEventOrRequest instanceof KernelEvent ? $arrayOrEventOrRequest->getRequest() : null));

                        if (!$request) {
                            throw new Exception("At least one parameter requires route parameter in your method call. First parameter must be either an instance of 'Request', 'KernelEvent' or 'array'");
                        }

                        $arrayOrEventOrRequest = $request->attributes->get('_route_params');
                    }

                    $routeParameters = $arrayOrEventOrRequest;
                    break;

                default:
                    throw new Exception("Unexpected \"Entity\" proposition found: \"" . $with . "\"");
            }
        }

        // Extract method name and extra parameters
        $findRequest = self::REQUEST_CACHE . "|" .
            self::REQUEST_FIND;

        $countRequest = self::REQUEST_DISTINCT . "|" .
            self::REQUEST_COUNT . "|" .
            self::REQUEST_CACHE . ucfirst(self::REQUEST_DISTINCT) . "|" .
            self::REQUEST_CACHE . ucfirst(self::REQUEST_COUNT);

        $lengthRequest = self::REQUEST_LENGTH;
        $specials = implode("|", self::getSpecials());

        $eagerly = self::LOAD_EAGERLY;
        $by = self::SEPARATOR_BY;
        $for = self::SEPARATOR_FOR;

        $special = null;
        $magicExtra = [];

        $requestType = self::REQUEST_FIND;
        if (preg_match('/^(?P<fn>' . $countRequest . ')(?:' . $for . '(?P<column>[^' . $by . ']*))?' . $by . '(?P<names>.*)/', $method, $magicExtra)) {
            $requestType = self::REQUEST_COUNT;
            $byNames = array_pop_key("names", $magicExtra);
            $this->setColumn(lcfirst(array_pop_key("column", $magicExtra)) ?? null);

            $magicFn = only_alphanumerics(trim_brackets(array_pop_key("fn", $magicExtra)));
            $magicExtra = array_filter(array_key_removes_numerics($magicExtra));
        } elseif (preg_match('/^(?P<fn>' . $countRequest . ')(?:' . $for . '(?P<column>[^' . $by . ']*))?(?P<names>.*)/', $method, $magicExtra)) {
            $requestType = self::REQUEST_COUNT;
            $byNames = array_pop_key("names", $magicExtra);
            $this->setColumn(lcfirst(array_pop_key("column", $magicExtra)) ?? null);

            $magicFn = only_alphanumerics(trim_brackets(array_pop_key("fn", $magicExtra)));
            $magicExtra = array_filter(array_key_removes_numerics($magicExtra));
        } elseif (preg_match('/^(?P<fn>' . $lengthRequest . ')(?P<column>[^' . $by . ']+)' . $by . '(?P<names>.*)/', $method, $magicExtra)) {
            $requestType = self::REQUEST_LENGTH;
            $byNames = array_pop_key("names", $magicExtra);
            $this->setColumn(lcfirst(array_pop_key("column", $magicExtra)) ?? null);

            $magicFn = only_alphanumerics(trim_brackets(array_pop_key("fn", $magicExtra)));
            $magicExtra = array_filter(array_key_removes_numerics($magicExtra));
        } elseif (preg_match('/^(?P<fn>' . $lengthRequest . ')(?P<column>[^' . $by . ']+)(?P<names>.*)/', $method, $magicExtra)) {
            $requestType = self::REQUEST_LENGTH;
            $byNames = array_pop_key("names", $magicExtra);

            $magicFn = only_alphanumerics(trim_brackets(array_pop_key("fn", $magicExtra)));
            $magicExtra = array_filter(array_key_removes_numerics($magicExtra));

            $this->setColumn(lcfirst(array_pop_key("column", $magicExtra)) ?? null);
        } elseif (preg_match('/^(?P<fn>(?:' . $findRequest . ')(?P<special>' . $specials . ')?(?P<eagerly>' . $eagerly . ')?' . $by . ')(?P<names>.*)/', $method, $magicExtra)) {
            $this->eagerly = !empty(array_pop_key("eagerly", $magicExtra));
            $byNames = array_pop_key("names", $magicExtra);
            $special = array_pop_key("special", $magicExtra);
            $special = $special ? only_alphachars(ucfirst($special)) : null;

            $magicFn = only_alphachars(trim_brackets(array_pop_key("fn", $magicExtra)));
            $magicExtra = array_filter(array_key_removes_numerics($magicExtra));
        } elseif (preg_match('/^(?P<fn>(?:' . $findRequest . ')(?P<special>' . $specials . ')?(?P<eagerly>' . $eagerly . ')?)(?P<names>.*)/', $method, $magicExtra)) {
            $this->eagerly = !empty(array_pop_key("eagerly", $magicExtra));
            $byNames = array_pop_key("names", $magicExtra);
            $special = array_pop_key("special", $magicExtra);
            $special = $special ? only_alphachars(ucfirst($special)) : null;

            $magicFn = only_alphachars(trim_brackets(array_pop_key("fn", $magicExtra)));
            $magicExtra = array_filter(array_key_removes_numerics($magicExtra));
        } else {
            throw new Exception(sprintf(
                'Undefined method "%s". The method name must start with ' .
                'either cache[PreviousOne|NextOne|One|Randomly|AtMost|Next|Previous|Last|LastOne|All][By], find[PreviousOne|NextOne|One|Randomly|AtMost|Next|Previous|Last|LastOne][By], distinctCount, count, lengthOf!',
                $method
            ));
        }

        // Handle special cases
        if (in_array($special, [self::getSpecial(self::SPECIAL_NEXTONE), self::getSpecial(self::SPECIAL_NEXT)])) {
            
            $specialArgs = array_shift($arguments);
            if(is_int($specialArgs)) $specialArgs = ["id" => $specialArgs];
            if(!is_array($specialArgs)) {
                throw new Exception("Warning /!\ Array expected for getting result on `findNext|findNextOne` special instruction");
            }

            $magicExtra["nextKeys"] = array_keys($specialArgs ?? []);
            foreach($specialArgs as $_fieldName => $_arg) {
                $this->addCustomOption($this->addCriteria($_fieldName, $_arg), self::OPTION_GREATER);
            }

        } elseif (in_array($special, [self::getSpecial(self::SPECIAL_PREVONE), self::getSpecial(self::SPECIAL_PREV)])) {

            $specialArgs = array_shift($arguments);
            if(is_int($specialArgs)) $specialArgs = ["id" => $specialArgs];
            if(!is_array($specialArgs)) {
                throw new Exception("Warning /!\ Array expected for getting result on `findPrevious|findPreviousOne` special instruction");
            }

            $magicExtra["prevKeys"] = array_keys($specialArgs);
            foreach($specialArgs as $_fieldName => $_arg) {
                $this->addCustomOption($this->addCriteria($_fieldName, $_arg), self::OPTION_LOWER);
            }
        }

        if ($this->eagerly !== false) {
            $magicFn = str_replace(self::LOAD_EAGERLY, "", $magicFn);
            $this->eagerly = array_shift($arguments);
            if (!is_array($this->eagerly)) {
                throw new Exception("Warning /!\ Eager queries requires an array as first argument");
            }
        }

        // Reveal obvious logical ambiguities..
        $byNames = str_starts_with($byNames, self::SEPARATOR_BY) ? substr($byNames, strlen(self::SEPARATOR_BY)) : $byNames;
        if (str_contains($byNames, self::SEPARATOR_AND) && str_contains($byNames, self::SEPARATOR_OR)) {
            throw new Exception("\"" . $byNames . "\" method gets an AND/OR ambiguity");
        }

        // Desintangle logical operators
        if (str_contains($byNames, self::SEPARATOR_OR)) {
            $this->setSeparator(self::SEPARATOR_OR);
            $byNames = explode(self::SEPARATOR_OR, $byNames);
        } else {
            $this->setSeparator(self::SEPARATOR_AND);
            $byNames = explode(self::SEPARATOR_AND, $byNames);
        }

        $methodBak = $method;
        $method = str_starts_with($method, $magicFn) ? substr($method, strlen($magicFn)) : $method;

        $operator = self::OPTION_EQUAL; // Default case (e.g. "findBy" alone)
        foreach ($byNames as $id => $by) {
            $oldBy = false;

            $isModel = false;
            $isInsensitive = $isPartial = false;
            $withRoute = false;
            $but = false;

            $closestTo = $farestTo = false;
            $instanceOf = $notInstanceOf = false;
            $classOf = $notClassOf = false;
            $memberOf = $notMemberOf = false;

            $operator = self::OPTION_EQUAL;
            while ($oldBy != $by) {
                $oldBy = $by;

                $option = null;
                if (str_starts_with($by, self::OPTION_PARTIAL)) {
                    $option = self::OPTION_PARTIAL;
                } elseif (str_starts_with($by, self::OPTION_INSENSITIVE)) {
                    $option = self::OPTION_INSENSITIVE;
                } elseif (str_ends_with($by, self::OPTION_WITH_ROUTE)) {
                    $option = self::OPTION_WITH_ROUTE;
                } elseif (str_ends_with($by, self::OPTION_CLOSESTTO)) {
                    $option = self::OPTION_CLOSESTTO;
                } elseif (str_ends_with($by, self::OPTION_FARESTTO)) {
                    $option = self::OPTION_FARESTTO;
                } elseif (str_ends_with($by, self::OPTION_BUT)) {
                    $option = self::OPTION_BUT;
                } elseif (str_ends_with($by, self::OPTION_INSTANCEOF)) {
                    $option = self::OPTION_INSTANCEOF;
                } elseif (str_ends_with($by, self::OPTION_NOT_INSTANCEOF)) {
                    $option = self::OPTION_NOT_INSTANCEOF;
                } elseif (str_ends_with($by, self::OPTION_CLASSOF)) {
                    $option = self::OPTION_CLASSOF;
                } elseif (str_ends_with($by, self::OPTION_NOT_CLASSOF)) {
                    $option = self::OPTION_NOT_CLASSOF;
                } elseif (str_ends_with($by, self::OPTION_MEMBEROF)) {
                    $option = self::OPTION_MEMBEROF;
                } elseif (str_ends_with($by, self::OPTION_NOT_MEMBEROF)) {
                    $option = self::OPTION_NOT_MEMBEROF;
                } elseif ($this->isSelectClause($by)) {
                    $option = self::OPTION_MODEL;
                } elseif (str_ends_with($by, self::OPTION_STARTING_WITH)) {
                    $option = self::OPTION_STARTING_WITH;
                } elseif (str_ends_with($by, self::OPTION_ENDING_WITH)) {
                    $option = self::OPTION_ENDING_WITH;
                } elseif (str_ends_with($by, self::OPTION_NOT_STARTING_WITH)) {
                    $option = self::OPTION_NOT_STARTING_WITH;
                } elseif (str_ends_with($by, self::OPTION_NOT_ENDING_WITH)) {
                    $option = self::OPTION_NOT_ENDING_WITH;
                } elseif (str_ends_with($by, self::OPTION_OVER)) {
                    $option = self::OPTION_OVER;
                } elseif (str_ends_with($by, self::OPTION_NOT_OVER)) {
                    $option = self::OPTION_NOT_OVER;
                } elseif (str_ends_with($by, self::OPTION_YOUNGER)) {
                    $option = self::OPTION_YOUNGER;
                } elseif (str_ends_with($by, self::OPTION_YOUNGER_EQUAL)) {
                    $option = self::OPTION_YOUNGER_EQUAL;
                } elseif (str_ends_with($by, self::OPTION_WITHIN)) {
                    $option = self::OPTION_WITHIN;
                } elseif (str_ends_with($by, self::OPTION_OLDER)) {
                    $option = self::OPTION_OLDER;
                } elseif (str_ends_with($by, self::OPTION_OLDER_EQUAL)) {
                    $option = self::OPTION_OLDER_EQUAL;
                } elseif (str_ends_with($by, self::OPTION_LOWER_EQUAL)) {
                    $option = self::OPTION_LOWER_EQUAL;
                } elseif (str_ends_with($by, self::OPTION_LOWER)) {
                    $option = self::OPTION_LOWER;
                } elseif (str_ends_with($by, self::OPTION_GREATER_EQUAL)) {
                    $option = self::OPTION_GREATER_EQUAL;
                } elseif (str_ends_with($by, self::OPTION_GREATER)) {
                    $option = self::OPTION_GREATER;
                } elseif (str_ends_with($by, self::OPTION_NOT_EQUAL)) {
                    $option = self::OPTION_NOT_EQUAL;
                } elseif (str_ends_with($by, self::OPTION_EQUAL)) {
                    $option = self::OPTION_EQUAL;
                } elseif (str_ends_with($by, self::OPTION_NOT_NULL)) {
                    $option = self::OPTION_NOT_NULL;
                } elseif (str_ends_with($by, self::OPTION_NULL)) {
                    $option = self::OPTION_NULL;
                } elseif (str_ends_with($by, self::OPTION_NOT_EMPTY)) {
                    $option = self::OPTION_NOT_EMPTY;
                } elseif (str_ends_with($by, self::OPTION_EMPTY)) {
                    $option = self::OPTION_EMPTY;
                } elseif (str_ends_with($by, self::OPTION_NOT_TRUE)) {
                    $option = self::OPTION_NOT_TRUE;
                } elseif (str_ends_with($by, self::OPTION_TRUE)) {
                    $option = self::OPTION_TRUE;
                } elseif (str_ends_with($by, self::OPTION_NOT_FALSE)) {
                    $option = self::OPTION_NOT_FALSE;
                } elseif (str_ends_with($by, self::OPTION_FALSE)) {
                    $option = self::OPTION_FALSE;
                }

                switch ($option) {
                    case self::OPTION_PARTIAL:
                        $isPartial = true;
                        list($method, $by) = $this->stripByFront($method, $by, $option);
                        break;

                    case self::OPTION_INSENSITIVE:
                        $isInsensitive = true;
                        list($method, $by) = $this->stripByFront($method, $by, $option);
                        break;

                    case self::OPTION_WITH_ROUTE:
                        $withRoute = true;
                        list($method, $by) = $this->stripByEnd($method, $by, $option);
                        break;

                    case self::OPTION_BUT:
                        $but = true;
                        list($method, $by) = $this->stripByEnd($method, $by, $option);
                        break;

                    case self::OPTION_CLOSESTTO:
                        $closestTo = true;
                        list($method, $by) = $this->stripByEnd($method, $by, $option);
                        break;
                    case self::OPTION_FARESTTO:
                        $farestTo = true;
                        list($method, $by) = $this->stripByEnd($method, $by, $option);
                        break;

                    case self::OPTION_INSTANCEOF:
                        $instanceOf = true;
                        list($method, $by) = $this->stripByEnd($method, $by, $option);
                        break;

                    case self::OPTION_NOT_INSTANCEOF:
                        $notInstanceOf = true;
                        list($method, $by) = $this->stripByEnd($method, $by, $option);
                        break;

                    case self::OPTION_CLASSOF:
                        $classOf = true;
                        list($method, $by) = $this->stripByEnd($method, $by, $option);
                        break;

                    case self::OPTION_NOT_CLASSOF:
                        $notClassOf = true;
                        list($method, $by) = $this->stripByEnd($method, $by, $option);
                        break;

                    case self::OPTION_MEMBEROF:
                        $memberOf = true;
                        list($method, $by) = $this->stripByEnd($method, $by, $option);
                        break;

                    case self::OPTION_NOT_MEMBEROF:
                        $notMemberOf = true;
                        list($method, $by) = $this->stripByEnd($method, $by, $option);
                        break;

                    case self::OPTION_MODEL:
                        $isModel = true;
                        list($method, $by) = $this->stripByEnd($method, $by, $option);
                        break;

                    // String related
                    case self::OPTION_STARTING_WITH:
                    case self::OPTION_ENDING_WITH:
                    case self::OPTION_NOT_STARTING_WITH:
                    case self::OPTION_NOT_ENDING_WITH:

                        // Datetime related
                    case self::OPTION_YOUNGER:
                    case self::OPTION_YOUNGER_EQUAL:
                    case self::OPTION_OLDER:
                    case self::OPTION_OLDER_EQUAL:
                    case self::OPTION_WITHIN:
                    case self::OPTION_OVER:
                    case self::OPTION_NOT_OVER:

                        // Number related
                    case self::OPTION_LOWER:
                    case self::OPTION_LOWER_EQUAL:
                    case self::OPTION_GREATER:
                    case self::OPTION_GREATER_EQUAL:
                    case self::OPTION_EQUAL:
                    case self::OPTION_NOT_EQUAL:
                    case self::OPTION_EMPTY:
                    case self::OPTION_NOT_EMPTY:
                    case self::OPTION_TRUE:
                    case self::OPTION_NOT_TRUE:
                    case self::OPTION_FALSE:
                    case self::OPTION_NOT_FALSE:
                    case self::OPTION_NULL:
                    case self::OPTION_NOT_NULL:
                        $operator = $option;
                        list($method, $by) = $this->stripByEnd($method, $by, $option);
                        break;
                }
            }

            // First check if WithRoute special argument is found..
            // This argument will retrieve the value of the corresponding route parameter
            // and use it in the query
            if ($withRoute) {
                $key = array_shift($arguments);

                // Stop dev using partial information when using route parameter
                // Doing so.. user would be able to inject LIKE commands directly from URL..
                if ($isPartial) {
                    throw new Exception("Partial field \"$by\" using route parameter is not implemented. Consider removing \"Partial\" prefix from \"$methodBak\"");
                }

                // Process self::OPTION_WITH_ROUTE method
                if ($method == self::OPTION_WITH_ROUTE) {
                    throw new Exception("Missing parameter to associate with operator 'withRouteParameter'");
                }

                $fieldValue = $routeParameters[$key] ?? null;
                if (!empty($fieldValue)) {
                    // Check if partial match is enabled
                    $by = lcfirst($by);

                    $id = $this->addCriteria($by, $fieldValue);
                    if ($operator == self::OPTION_EQUAL) {
                        $this->addCustomOption($id, $operator);
                    } else {
                        throw new Exception("Unexpected operator \"" . $operator . "\" found in model definition");
                    }
                }
            } elseif ($but) {
                $fieldValue = array_shift($arguments);
                if (!empty($fieldValue)) {
                    $by = "id";
                    $operator = self::OPTION_NOT_EQUAL;

                    $id = $this->addCriteria($by, $fieldValue);
                    $this->addCustomOption($id, $operator);
                }
            } elseif ($instanceOf) {
                $fieldValue = array_shift($arguments);
                if (!empty($fieldValue)) {
                    $by = lcfirst(self::OPTION_INSTANCEOF);

                    $id = $this->addCriteria($by, $fieldValue);
                    $this->addCustomOption($id, self::OPTION_INSTANCEOF);
                }
            } elseif ($notInstanceOf) {
                $fieldValue = array_shift($arguments);
                if (!empty($fieldValue)) {
                    $by = lcfirst(self::OPTION_NOT_INSTANCEOF);

                    $id = $this->addCriteria($by, $fieldValue);
                    $this->addCustomOption($id, self::OPTION_NOT_INSTANCEOF);
                }
            } elseif ($classOf) {
                $fieldValue = array_shift($arguments);
                if (!empty($fieldValue)) {
                    $by = lcfirst(self::OPTION_CLASSOF);

                    $id = $this->addCriteria($by, $fieldValue);
                    $this->addCustomOption($id, self::OPTION_CLASSOF);
                }
            } elseif ($notClassOf) {
                $fieldValue = array_shift($arguments);
                if (!empty($fieldValue)) {
                    $by = lcfirst(self::OPTION_NOT_CLASSOF);

                    $id = $this->addCriteria($by, $fieldValue);
                    $this->addCustomOption($id, self::OPTION_NOT_CLASSOF);
                }
            } elseif ($memberOf) {
                $fieldValue = array_shift($arguments);
                if (!empty($fieldValue)) {
                    $by = lcfirst(self::OPTION_MEMBEROF);

                    $id = $this->addCriteria($by, $fieldValue);
                    $this->addCustomOption($id, self::OPTION_MEMBEROF);
                }
            } elseif ($notMemberOf) {
                $fieldValue = array_shift($arguments);
                if (!empty($fieldValue)) {
                    $by = lcfirst(self::OPTION_NOT_MEMBEROF);

                    $id = $this->addCriteria($by, $fieldValue);
                    $this->addCustomOption($id, self::OPTION_NOT_MEMBEROF);
                }
            } elseif ($isModel) {
                // Query-by-Example clause: consume a select entity and expand its
                // set fields into criteria (AND-combined at compile time). Composes
                // with "And" only — "Or" would need parentheses this DSL forbids.
                if ($this->getSeparator() === self::SEPARATOR_OR) {
                    throw new Exception("\"Model\" cannot be combined with \"Or\" (would need parentheses); use \"And\" only.");
                }

                $select = array_shift($arguments);
                if (!is_object($select)) {
                    throw new Exception("\"Model\" expects a select object argument, got \"" . gettype($select) . "\".");
                }

                $modelCriteria = $this->serviceEntity->criteriaFromSelect($select);
                if (empty($modelCriteria)) {
                    throw new Exception("\"Model\" select (" . get_class($select) . ") has no queryable set fields for \"" . $this->classMetadata->getName() . "\".");
                }

                $id = $this->addCriteria(lcfirst(self::OPTION_MODEL), $modelCriteria);
                if ($isPartial) {         // findByPartialModel -> LIKE per select field
                    $this->addCustomOption($id, self::OPTION_PARTIAL);
                }
                if ($isInsensitive) {     // findByInsensitiveModel -> LOWER() per select field
                    $this->addCustomOption($id, self::OPTION_INSENSITIVE);
                }
            } elseif ($by) {
                $by = lcfirst($by);

                $fieldDate = ($operator == self::OPTION_OVER || $operator == self::OPTION_NOT_OVER);
                $fieldNull = ($operator == self::OPTION_NULL || $operator == self::OPTION_NOT_NULL);
                $fieldEmpty = ($operator == self::OPTION_EMPTY || $operator == self::OPTION_NOT_EMPTY);
                $fieldBool = ($operator == self::OPTION_TRUE || $operator == self::OPTION_NOT_TRUE ||
                    $operator == self::OPTION_FALSE || $operator == self::OPTION_NOT_FALSE);

                if ($fieldEmpty) {
                    $fieldValue = "";
                } elseif ($fieldNull) {
                    $fieldValue = null;
                } elseif ($fieldBool) {
                    $fieldValue = true;
                } elseif ($fieldDate) {
                    $fieldValue = "CURDATE()";
                } else {
                    $fieldValue = array_shift($arguments);
                }

                $id = $this->addCriteria($by, $fieldValue);

                if ($isPartial) {
                    $this->addCustomOption($id, self::OPTION_PARTIAL);
                }
                if ($isInsensitive) {
                    $this->addCustomOption($id, self::OPTION_INSENSITIVE);
                }
                if ($closestTo) {
                    $this->addCustomOption($id, self::OPTION_CLOSESTTO);
                }
                if ($farestTo) {
                    $this->addCustomOption($id, self::OPTION_FARESTTO);
                }
                if ($operator) {
                    $this->addCustomOption($id, $operator);
                }
            }
        }

        // Index definition:
        // "criteria"  = argument #0, after removal of head parameters
        foreach ($arguments as $i => $arg) {
            $magicArgs[$i] = $arg;
        } // +1 because of array shift

        //
        // Mark as cacheable (to be used in self::getQueryBuilder)
        if (str_starts_with($magicFn, self::REQUEST_CACHE)) {
            $magicFn = lcfirst(str_lstrip($magicFn, self::REQUEST_CACHE));
            if ($requestType == self::REQUEST_FIND && !str_starts_with($magicFn, self::REQUEST_FIND)) {
                $magicFn = self::REQUEST_FIND . ucfirst($magicFn);
            } // find <-> cache, findOne <-> cacheOne, [...]

            $this->cacheable = !is_cli();
        }

        if (str_starts_with($magicFn, self::REQUEST_FIND . self::SPECIAL_ALL)) {
            if (str_ends_with($magicFn, self::SEPARATOR_BY)) {
                $magicFn = substr($magicFn, 0, -strlen(self::SEPARATOR_BY));
            }
        } elseif (!str_ends_with($magicFn, self::SEPARATOR_BY)) { // "Find" method without "By" must include a criteria to call __findBy
            $magicFn .= self::SEPARATOR_BY;
            array_unshift($magicArgs);
        }

        $magicFn = "__" . $magicFn;
        $magicArgs[0] = array_merge($magicArgs[0] ?? [], $this->criteria ?? []); // Criteria
        $magicArgs[0] = array_merge($magicArgs[0], array_transforms(fn($k, $v): array => ["special:" . $k, $v], $magicExtra));

        // Remove criteria for findAll requests..
        if (str_starts_with($magicFn, self::REQUEST_FIND . self::SPECIAL_ALL)) {
            array_shift($magicArgs);
        }

        return [$magicFn, $magicArgs];
    }

    /**
     * @param $className
     * @return string|null
     * @throws Exception
     */
    protected function getRealClassName($className): ?string
    {
        if (!class_exists($className)) {
            return null;
        }

        if (!is_instanceof($className, Proxy::class)) {
            return $className;
        }

        return get_parent_class($className);
    }

    /**
     * @param QueryBuilder $queryBuilder
     * @param $field
     * @param $fieldValue
     * @return Andx|Comparison|Func|Orx|string
     * @throws EntityNotFoundException
     */
    /**
     * The mapped type to bind a date or time criterion with. Untyped, Doctrine
     * guesses "datetime" for any DateTime and sends the moment (in UTC), which
     * a DATE or TIME column never equals: findOneBy(['day' => $today]) found
     * nothing. Other values keep Doctrine's own inference.
     */
    protected function dateTypeOf(string $fieldHead, mixed $fieldValue): ?string
    {
        if (!$fieldValue instanceof \DateTimeInterface || !$this->classMetadata->hasField($fieldHead)) {
            return null;
        }

        return $this->classMetadata->getTypeOfField($fieldHead);
    }

    protected function buildQueryExpr(QueryBuilder $queryBuilder, $field, $fieldValue)
    {
        $fieldID = str_replace(".", "_", implode("_", $field));
        $fieldName = $this->getAlias($field[0]);
        $fieldRoot = implode(self::SEPARATOR, array_slice($field, count($field) - 2, 2));
        $fieldHead = explode(".", $fieldName)[0];

        $isNull = $this->findCustomOption($fieldRoot, self::OPTION_NULL);
        $isNotNull = $this->findCustomOption($fieldRoot, self::OPTION_NOT_NULL);
        $isEmpty = $this->findCustomOption($fieldRoot, self::OPTION_EMPTY);
        $isNotEmpty = $this->findCustomOption($fieldRoot, self::OPTION_NOT_EMPTY);
        $isBool = $this->findCustomOption($fieldRoot, self::OPTION_TRUE) ||
            $this->findCustomOption($fieldRoot, self::OPTION_NOT_TRUE) ||
            $this->findCustomOption($fieldRoot, self::OPTION_FALSE) ||
            $this->findCustomOption($fieldRoot, self::OPTION_NOT_FALSE);

        $isPartial = $this->findCustomOption($fieldRoot, self::OPTION_PARTIAL);
        $isInsensitive = $this->findCustomOption($fieldRoot, self::OPTION_INSENSITIVE);
        $closestTo = $this->findCustomOption($fieldRoot, self::OPTION_CLOSESTTO);
        $farestTo = $this->findCustomOption($fieldRoot, self::OPTION_FARESTTO);

        $isMemberOf = $this->findCustomOption($fieldRoot, self::OPTION_MEMBEROF);
        $isNotMemberOf = $this->findCustomOption($fieldRoot, self::OPTION_NOT_MEMBEROF);
        $isInstanceOf = $this->findCustomOption($fieldRoot, self::OPTION_INSTANCEOF);
        $isNotInstanceOf = $this->findCustomOption($fieldRoot, self::OPTION_NOT_INSTANCEOF);
        $isClassOf = $this->findCustomOption($fieldRoot, self::OPTION_CLASSOF);
        $isNotClassOf = $this->findCustomOption($fieldRoot, self::OPTION_NOT_CLASSOF);


        $tableOperator = self::OPTION_EQUAL;
        if ($this->findCustomOption($fieldRoot, self::OPTION_INSTANCEOF)) {
            $tableOperator = self::OPTION_INSTANCEOF;
        } elseif ($this->findCustomOption($fieldRoot, self::OPTION_NOT_INSTANCEOF)) {
            $tableOperator = self::OPTION_NOT_INSTANCEOF;
        } elseif ($this->findCustomOption($fieldRoot, self::OPTION_CLASSOF)) {
            $tableOperator = self::OPTION_CLASSOF;
        } elseif ($this->findCustomOption($fieldRoot, self::OPTION_NOT_CLASSOF)) {
            $tableOperator = self::OPTION_NOT_CLASSOF;
        } elseif ($this->findCustomOption($fieldRoot, self::OPTION_MEMBEROF)) {
            $tableOperator = self::OPTION_MEMBEROF;
        } elseif ($this->findCustomOption($fieldRoot, self::OPTION_NOT_MEMBEROF)) {
            $tableOperator = self::OPTION_NOT_MEMBEROF;
        } // Datetime related options
        elseif ($this->findCustomOption($fieldRoot, self::OPTION_OVER)) {
            $tableOperator = self::OPTION_OVER;
        } elseif ($this->findCustomOption($fieldRoot, self::OPTION_NOT_OVER)) {
            $tableOperator = self::OPTION_NOT_OVER;
        } elseif ($this->findCustomOption($fieldRoot, self::OPTION_OLDER)) {
            $tableOperator = self::OPTION_OLDER;
        } elseif ($this->findCustomOption($fieldRoot, self::OPTION_OLDER_EQUAL)) {
            $tableOperator = self::OPTION_OLDER_EQUAL;
        } elseif ($this->findCustomOption($fieldRoot, self::OPTION_WITHIN)) {
            $tableOperator = self::OPTION_WITHIN;
        } elseif ($this->findCustomOption($fieldRoot, self::OPTION_YOUNGER)) {
            $tableOperator = self::OPTION_YOUNGER;
        } elseif ($this->findCustomOption($fieldRoot, self::OPTION_YOUNGER_EQUAL)) {
            $tableOperator = self::OPTION_YOUNGER_EQUAL;
        } // String related options
        elseif ($this->findCustomOption($fieldRoot, self::OPTION_STARTING_WITH)) {
            $tableOperator = self::OPTION_STARTING_WITH;
        } elseif ($this->findCustomOption($fieldRoot, self::OPTION_ENDING_WITH)) {
            $tableOperator = self::OPTION_ENDING_WITH;
        } elseif ($this->findCustomOption($fieldRoot, self::OPTION_NOT_STARTING_WITH)) {
            $tableOperator = self::OPTION_NOT_STARTING_WITH;
        } elseif ($this->findCustomOption($fieldRoot, self::OPTION_NOT_ENDING_WITH)) {
            $tableOperator = self::OPTION_NOT_ENDING_WITH;
        } // Number related options
        elseif ($this->findCustomOption($fieldRoot, self::OPTION_GREATER)) {
            $tableOperator = self::OPTION_GREATER;
        } elseif ($this->findCustomOption($fieldRoot, self::OPTION_GREATER_EQUAL)) {
            $tableOperator = self::OPTION_GREATER_EQUAL;
        } elseif ($this->findCustomOption($fieldRoot, self::OPTION_LOWER)) {
            $tableOperator = self::OPTION_LOWER;
        } elseif ($this->findCustomOption($fieldRoot, self::OPTION_LOWER_EQUAL)) {
            $tableOperator = self::OPTION_LOWER_EQUAL;
        } elseif ($this->findCustomOption($fieldRoot, self::OPTION_NULL)) {
            $tableOperator = self::OPTION_NULL;
        } elseif ($this->findCustomOption($fieldRoot, self::OPTION_NOT_NULL)) {
            $tableOperator = self::OPTION_NOT_NULL;
        } elseif ($this->findCustomOption($fieldRoot, self::OPTION_EMPTY)) {
            $tableOperator = self::OPTION_EMPTY;
        } elseif ($this->findCustomOption($fieldRoot, self::OPTION_NOT_EMPTY)) {
            $tableOperator = self::OPTION_NOT_EMPTY;
        } elseif ($this->findCustomOption($fieldRoot, self::OPTION_TRUE)) {
            $tableOperator = self::OPTION_TRUE;
        } elseif ($this->findCustomOption($fieldRoot, self::OPTION_NOT_TRUE)) {
            $tableOperator = self::OPTION_NOT_TRUE;
        } elseif ($this->findCustomOption($fieldRoot, self::OPTION_FALSE)) {
            $tableOperator = self::OPTION_FALSE;
        } elseif ($this->findCustomOption($fieldRoot, self::OPTION_NOT_FALSE)) {
            $tableOperator = self::OPTION_NOT_FALSE;
        } elseif ($this->findCustomOption($fieldRoot, self::OPTION_NOT_EQUAL)) {
            $tableOperator = self::OPTION_NOT_EQUAL;
        }

        switch ($tableOperator) {
            case self::OPTION_MEMBEROF:
            case self::OPTION_NOT_MEMBEROF:
            case self::OPTION_INSTANCEOF:
            case self::OPTION_NOT_INSTANCEOF:
            case self::OPTION_CLASSOF:
            case self::OPTION_NOT_CLASSOF:
                $tableColumn = self::ALIAS_ENTITY;
                break;

            default:

                if ($this->classMetadata->hasAssociation($fieldHead)) {
                    $tableColumn = self::ALIAS_ENTITY . "_" . $fieldName;
                } elseif ($this->classMetadata->hasField($fieldHead)) {
                    $tableColumn = self::ALIAS_ENTITY . "." . $fieldName;
                } else {
                    $tableColumn = $fieldName;
                }
        }

        $datetimeRequested = in_array($tableOperator, [self::OPTION_OVER, self::OPTION_NOT_OVER, self::OPTION_OLDER, self::OPTION_WITHIN, self::OPTION_OLDER_EQUAL, self::OPTION_YOUNGER, self::OPTION_YOUNGER_EQUAL]);
        if ($datetimeRequested) {
            if (is_numeric($fieldValue)) {
                $fieldValue = ($fieldValue > 0 ? "+" : "-") . $fieldValue . " second" . ($fieldValue > 1 ? "s" : "");
            }

            if ($fieldValue instanceof DateTime && in_array($tableOperator, [self::OPTION_YOUNGER, self::OPTION_YOUNGER_EQUAL, self::OPTION_OLDER, self::OPTION_OLDER_EQUAL])) {
                throw new Exception("Please use string or int with Older/Younger operands");
            }

            if (in_array($tableOperator, [self::OPTION_OVER, self::OPTION_NOT_OVER])) {
                $fieldValue = new DateTime("now");
            } elseif ($this->validateDate($fieldValue) && !$fieldValue instanceof DateTime) {
                $fieldValue = new DateTime($fieldValue);
            } elseif (!$fieldValue instanceof DateTime) {
                $subtract = $fieldValue;
                if (in_array($tableOperator, [self::OPTION_YOUNGER, self::OPTION_YOUNGER_EQUAL])) {
                    $subtract = strtr($fieldValue, ["+" => "-", "-" => "+"]);
                }

                $fieldValue = round_datetime(new DateTime("now"), $subtract);
            }
        }

        $regexRequested = in_array($tableOperator, [self::OPTION_STARTING_WITH, self::OPTION_ENDING_WITH, self::OPTION_NOT_STARTING_WITH, self::OPTION_NOT_ENDING_WITH]);

        if ($this->classMetadataManipulator->getTypeOfField($this->classMetadata, $fieldName) == "json") {

            if (is_array($fieldValue)) {

                if (empty($fieldValue)) {
                    return $queryBuilder->expr()->eq(1, 1);
                }

                $queryExpr = [];
                foreach ($fieldValue as $subFieldID => $subFieldValue) {

                    if ($isInsensitive) {
                        $queryExpr[] = $queryBuilder->expr()->eq("JSON_CONTAINS(LOWER(JSON_EXTRACT(" . self::ALIAS_ENTITY . "." . $fieldName . ", '$')), LOWER(:" . $fieldID . "_" . $subFieldID . "), '$')", "1");
                    } else {
                        $queryExpr[] = $queryBuilder->expr()->eq("JSON_CONTAINS(" . self::ALIAS_ENTITY . "." . $fieldName . ", :" . $fieldID . "_" . $subFieldID . ", '$')", "1");
                    }

                    $queryBuilder->setParameter(":" . $fieldID . "_" . $subFieldID, '"' . $subFieldValue . '"');
                }

                if ($tableOperator == self::OPTION_EQUAL) {
                    return $queryBuilder->expr()->andX(...$queryExpr);
                } elseif ($tableOperator == self::OPTION_NOT_EQUAL) {
                    return $queryBuilder->expr()->not($queryBuilder->expr()->andX(...$queryExpr));
                }

                throw new Exception("Invalid operator for field \"$fieldName\": " . $tableOperator);

            } elseif ($isEmpty || $isNotEmpty) {

                $fnExpr = $tableOperator == self::OPTION_EMPTY ? "eq" : "neq";
                $queryBuilder->expr()->$fnExpr("JSON_LENGTH(" . self::ALIAS_ENTITY . "." . $fieldName . ", :" . $fieldID . ", '$')", "0");

            } else {

                if ($isInsensitive) {
                    return $queryBuilder->expr()->eq("JSON_CONTAINS(JSON_EXTRACT(LOWER(" . self::ALIAS_ENTITY . "." . $fieldName . "), '$.NODE'), LOWER(:" . $fieldID . "), '$')", "1");
                } else {
                    return $queryBuilder->expr()->eq("JSON_CONTAINS(" . self::ALIAS_ENTITY . "." . $fieldName . ", :" . $fieldID . ", '$')", "1");
                }
            }

            throw new \LogicException("Operation not supported for json-like field \"" . $fieldName . "\" in \"" . $this->classMetadata->getName() . "\"");

        } elseif ($regexRequested) {

            $fieldValue = str_replace(["_", "\%"], ["\_", "\%"], $fieldValue);

            if ($tableOperator == self::OPTION_STARTING_WITH) {
                $fieldValue = $fieldValue . "%";
            } elseif ($tableOperator == self::OPTION_ENDING_WITH) {
                $fieldValue = "%" . $fieldValue;
            } elseif ($tableOperator == self::OPTION_NOT_STARTING_WITH) {
                $fieldValue = $fieldValue . "%";
            } elseif ($tableOperator == self::OPTION_NOT_ENDING_WITH) {
                $fieldValue = "%" . $fieldValue;
            }

            $fieldValue = ($isInsensitive ? mb_strtolower($fieldValue) : $fieldValue);
        }

        //
        // Standard field..
        if ($isInstanceOf || $isNotInstanceOf) {

            // Cast to array
            if (!is_array($fieldValue)) {
                $fieldValue = $fieldValue !== null ? [$fieldValue] : [];
            }

            $instanceOf = [];
            $notInstanceOf = [];
            if ($this->classMetadata->discriminatorColumn !== null) {
                foreach ($fieldValue as $value) {
                    $reverseAssert = str_starts_with($value, "^");
                    if ($reverseAssert) {
                        $value = ltrim($value, "^");
                    }

                    $realValue = $this->getRealClassName($value);
                    if ($realValue === null) {
                        throw new EntityNotFoundException("Entity \"$value\" doesn't exists");
                    }

                    if ($isInstanceOf) {
                        if ($reverseAssert) {
                            $notInstanceOf[] = $queryBuilder->expr()->not($queryBuilder->expr()->isInstanceOf($tableColumn, ltrim($realValue, "^")));
                        } else {
                            $instanceOf[] = $queryBuilder->expr()->isInstanceOf($tableColumn, $realValue);
                        }
                    } else {
                        if ($reverseAssert) {
                            $instanceOf[] = $queryBuilder->expr()->isInstanceOf($tableColumn, ltrim($realValue, "^"));
                        } else {
                            $notInstanceOf[] = $queryBuilder->expr()->not($queryBuilder->expr()->isInstanceOf($tableColumn, $realValue));
                        }
                    }
                }
            }

            $instanceOf = $instanceOf ? [$queryBuilder->expr()->orX(...$instanceOf)] : [];
            if ($notInstanceOf) {
                $instanceOf[] = $queryBuilder->expr()->andX(...$notInstanceOf);
            }

            return $instanceOf ? $queryBuilder->expr()->andX(...$instanceOf) : "";

        } elseif ($isClassOf || $isNotClassOf) {

            // Cast to array
            if (!is_array($fieldValue)) {
                $fieldValue = $fieldValue !== null ? [$fieldValue] : [];
            }

            $classOf = [];
            $notClassOf = [];
            if ($this->classMetadata->discriminatorColumn !== null) {
                foreach ($fieldValue as $value) {

                    $reverseAssert = str_starts_with($value, "^");
                    if ($reverseAssert) {
                        $value = ltrim($value, "^");
                    }

                    $realValue = $this->getRealClassName($value);
                    if ($realValue === null) {
                        throw new EntityNotFoundException("Entity \"$value\" doesn't exists");
                    }

                    $classMetadata = $this->entityManager->getClassMetadata($realValue);
                    $childClass = array_filter(array_values($classMetadata->discriminatorMap), fn($c) => $c != $realValue && is_instanceof($c, $realValue));

                    if ($isClassOf) {

                        if (!$reverseAssert) {

                            $queryPart = [$queryBuilder->expr()->isInstanceOf($tableColumn, $realValue)];
                            foreach ($childClass as $child) {
                                $queryPart[] = $queryBuilder->expr()->not($queryBuilder->expr()->isInstanceOf($tableColumn, $child));
                            }

                            $classOf[] = $queryBuilder->expr()->andX(...$queryPart);
                        } else {
                            $queryPart = [$queryBuilder->expr()->not($queryBuilder->expr()->isInstanceOf($tableColumn, $realValue))];
                            foreach ($childClass as $child) {
                                $queryPart[] = $queryBuilder->expr()->isInstanceOf($tableColumn, $child);
                            }

                            $classOf[] = $queryBuilder->expr()->orX(...$queryPart);
                        }

                    } else {

                        if ($reverseAssert) {

                            $queryPart = [$queryBuilder->expr()->not($queryBuilder->expr()->isInstanceOf($tableColumn, $realValue))];
                            foreach ($childClass as $child) {
                                $queryPart[] = $queryBuilder->expr()->isInstanceOf($tableColumn, $child);
                            }

                            $classOf[] = $queryBuilder->expr()->orX(...$queryPart);
                        } else {
                            $queryPart = [$queryBuilder->expr()->isInstanceOf($tableColumn, $realValue)];
                            foreach ($childClass as $child) {
                                $queryPart[] = $queryBuilder->expr()->not($queryBuilder->expr()->isInstanceOf($tableColumn, $child));
                            }

                            $classOf[] = $queryBuilder->expr()->andX(...$queryPart);
                        }
                    }
                }
            }

            $classOf = $classOf ? [$queryBuilder->expr()->orX(...$classOf)] : [];
            if ($notClassOf) {
                $classOf[] = $queryBuilder->expr()->andX(...$notClassOf);
            }

            return $classOf ? $queryBuilder->expr()->andX(...$classOf) : "";

        } elseif ($isMemberOf || $isNotMemberOf) {

            // Cast to array
            if (!is_array($fieldValue)) {
                $fieldValue = $fieldValue !== null ? [$fieldValue] : [];
            }

            $memberOf = [];
            $notMemberOf = [];
            if ($this->classMetadata->discriminatorColumn !== null) {
                foreach ($fieldValue as $value) {
                    $realValue = $this->getRealClassName($value);
                    if ($realValue === null) {
                        throw new EntityNotFoundException("Entity \"$value\" doesn't exists");
                    }

                    if ($isMemberOf) {
                        $memberOf[] = $queryBuilder->expr()->isMemberOf($tableColumn, $realValue);
                    } else {
                        $notMemberOf[] = $queryBuilder->expr()->isMemberOf($tableColumn, $realValue);
                    }
                }
            }

            $memberOf = $memberOf ? [$queryBuilder->expr()->orX(...$memberOf)] : [];
            if ($notMemberOf) {
                $memberOf[] = $queryBuilder->expr()->andX(...$notMemberOf);
            }

            return $memberOf ? $queryBuilder->expr()->andX(...$memberOf) : "";
        } else {

            if ($this->classMetadata->hasAssociation($fieldHead)) {

                // NB:: Still useful for select.. to be improved (fetch owning side ?)
                // if($this->classMetadata->isAssociationInverseSide($fieldHead))
                //     throw new Exception("Association \"$fieldHead\" for \"".$this->classMetadata->getName()."\" is not owning side");

                $fieldID = self::ALIAS_ENTITY . "_" . $fieldID;

                if ($isPartial) { // PARTIAL HAS TO BE CHECKED SINCE THE UPDATE.. NOT TESTED
                    // Cast to array
                    if ($this->classMetadata->hasAssociation($fieldHead)) {
                        $this->leftJoin($queryBuilder, self::ALIAS_ENTITY . "." . $fieldHead);
                    }

                    if (!is_array($fieldValue)) {
                        $fieldValue = ($fieldValue !== null) ? [$fieldValue] : [];
                    }
                    foreach ($fieldValue as $subFieldID => $subFieldValue) {
                        $queryBuilder->setParameter($fieldID . "_" . $subFieldID, $subFieldValue);
                    }
                } else {

                    if (!is_array($fieldValue)) {

                        if ($this->classMetadata->hasAssociation($fieldHead)) {
                            $this->leftJoin($queryBuilder, self::ALIAS_ENTITY . "." . $fieldHead);
                        }

                        if(!$isEmpty && !$isNotEmpty && !$isBool) {
                            $queryBuilder->setParameter($fieldID, $fieldValue, $this->dateTypeOf($fieldHead, $fieldValue));
                        }
                    } else {

                        $fieldValue = array_filter($fieldValue);
                        if ($fieldValue) {
                            if ($this->classMetadata->hasAssociation($fieldHead)) {
                                $this->leftJoin($queryBuilder, self::ALIAS_ENTITY . "." . $fieldHead);
                            }

                            $queryBuilder->setParameter($fieldID, $fieldValue);
                        }
                    }
                }

            } elseif (is_array($fieldValue)) {

                if (!empty($fieldValue)) {
                    $queryBuilder->setParameter($fieldID, $fieldValue);
                }

            } elseif (!$isEmpty && !$isNotEmpty && !$isBool) {
                $queryBuilder->setParameter($fieldID, $fieldValue, $this->dateTypeOf($fieldHead, $fieldValue));
            }
            
            if ($isInsensitive) {
                $tableColumn = "LOWER(" . $tableColumn . ")";
            }

            if ($isPartial) {

                if ($tableOperator != self::OPTION_EQUAL && $tableOperator != self::OPTION_NOT_EQUAL) {
                    throw new Exception("Invalid operator for association field \"$fieldName\": " . $tableOperator);
                }

                $queryExpr = [];
                $fnExpr = ($tableOperator == self::OPTION_EQUAL ? "like" : "notLike");

                if (!is_array($fieldValue)) {
                    $queryExpr[] = $queryBuilder->expr()->$fnExpr($tableColumn, ":$fieldID");
                } else {
                    foreach ($fieldValue as $subFieldID => $_) {
                        $queryExpr[] = $queryBuilder->expr()->$fnExpr($fieldID, ":{$fieldID}_$subFieldID");
                    }
                }

                $fnExpr = ($tableOperator == self::OPTION_EQUAL ? "orX" : "andX");
                return $queryBuilder->expr()->$fnExpr(...$queryExpr);

            } elseif (is_array($fieldValue)) {

                if ($tableOperator == self::OPTION_EQUAL) {
                    $fnExpr = "in";
                } elseif ($tableOperator == self::OPTION_NOT_EQUAL) {
                    $fnExpr = "notIn";
                } else {
                    throw new Exception("Invalid operator for field \"$fieldName\": " . $tableOperator);
                }

                if (empty($fieldValue)) {
                    return $queryBuilder->expr()->eq(1, 1);
                }

                return $queryBuilder->expr()->$fnExpr($tableColumn, ":$fieldID");

            } elseif ($regexRequested) {

                if ($tableOperator == self::OPTION_STARTING_WITH) {
                    $fnExpr = "like";
                } elseif ($tableOperator == self::OPTION_ENDING_WITH) {
                    $fnExpr = "like";
                } elseif ($tableOperator == self::OPTION_NOT_STARTING_WITH) {
                    $fnExpr = "notLike";
                } elseif ($tableOperator == self::OPTION_NOT_ENDING_WITH) {
                    $fnExpr = "notLike";
                }

                return $queryBuilder->expr()->$fnExpr($tableColumn, ":$fieldID");
            } elseif ($closestTo || $farestTo) {
                return $queryBuilder->expr()->abs($tableColumn . " - :" . $fieldID);
            } elseif ($isNull || $isNotNull) {
                if ($tableOperator == self::OPTION_NULL) {
                    $fnExpr = "isNull";
                } elseif ($tableOperator == self::OPTION_NOT_NULL) {
                    $fnExpr = "isNotNull";
                } else {
                    throw new Exception("Invalid operator for field \"$fieldName\": " . $tableOperator);
                }

                return $queryBuilder->expr()->$fnExpr($tableColumn, ":$fieldID");
            
            } elseif ($isEmpty || $isNotEmpty) {

                if ($tableOperator == self::OPTION_EMPTY) {
                    
                    $queryExpr = [];
                    $queryExpr[] = $queryBuilder->expr()->isNull($tableColumn);
                    $queryExpr[] = $queryBuilder->expr()->eq($tableColumn, "''");

                    return $queryBuilder->expr()->orX(...$queryExpr);

                } elseif ($tableOperator == self::OPTION_NOT_EMPTY) {

                    $queryExpr = [];
                    $queryExpr[] = $queryBuilder->expr()->isNotNull($tableColumn);
                    $queryExpr[] = $queryBuilder->expr()->neq($tableColumn, "''");

                    return $queryBuilder->expr()->andX(...$queryExpr);
                }

                throw new Exception("Invalid operator for field \"$fieldName\": " . $tableOperator);
            
            } elseif ($isBool) {
            
                if ($tableOperator == self::OPTION_TRUE || $tableOperator == self::OPTION_NOT_FALSE) {
                    $fnExpr = "eq";
                } elseif ($tableOperator == self::OPTION_FALSE || $tableOperator == self::OPTION_NOT_TRUE) {
                    $fnExpr = "neq";
                } else {
                    throw new Exception("Invalid operator for field \"$fieldName\": " . $tableOperator);
                }

                return $queryBuilder->expr()->$fnExpr($tableColumn, true);

            } else {

                if ($tableOperator == self::OPTION_EQUAL) {
                    $fnExpr = "eq";
                } elseif ($tableOperator == self::OPTION_NOT_EQUAL) {
                    $fnExpr = "neq";
                } elseif ($tableOperator == self::OPTION_GREATER) {
                    $fnExpr = "gt";
                } elseif ($tableOperator == self::OPTION_GREATER_EQUAL) {
                    $fnExpr = "ge";
                } elseif ($tableOperator == self::OPTION_LOWER) {
                    $fnExpr = "lt";
                } elseif ($tableOperator == self::OPTION_LOWER_EQUAL) {
                    $fnExpr = "le";
                } elseif ($tableOperator == self::OPTION_YOUNGER) {
                    $fnExpr = "gt";
                } elseif ($tableOperator == self::OPTION_YOUNGER_EQUAL) {
                    $fnExpr = "ge";
                } elseif ($tableOperator == self::OPTION_OVER) {
                    $fnExpr = "lt";
                } elseif ($tableOperator == self::OPTION_NOT_OVER) {
                    $fnExpr = "gt";
                } elseif ($tableOperator == self::OPTION_OLDER) {
                    $fnExpr = "lt";
                } elseif ($tableOperator == self::OPTION_OLDER_EQUAL) {
                    $fnExpr = "le";
                } elseif ($tableOperator == self::OPTION_WITHIN) {
                    $fnExpr = "lt";
                } else {
                    throw new Exception("Invalid operator for field \"$fieldName\": " . $tableOperator);
                }

                return $queryBuilder->expr()->$fnExpr($tableColumn, ":$fieldID");
            }
        }

        throw new Exception("Failed to build expression \"" . $field . "\": " . $fieldValue);
    }

    protected static int $i = 0;

    /**
     * @param array $criteria
     * @param array $orderBy
     * @param $limit
     * @param $offset
     * @param array $groupBy
     * @param array $selectAs
     * @return QueryBuilder|null
     * @throws EntityNotFoundException
     */
    protected function getQueryBuilder(array $criteria = [], array $orderBy = [], $limit = null, $offset = null, array $groupBy = [], array $selectAs = []): ?QueryBuilder
    {
        /**
         * @QueryBuilder
         */
        $queryBuilder = $this->serviceEntity
            ->createQueryBuilder(self::ALIAS_ENTITY)
            ->setMaxResults($limit ?? null)
            ->setFirstResult($offset ?? null)
            ->setCacheable($this->cacheable);

        $this->joinList[spl_object_hash($queryBuilder)] = [];

        // Prepare criteria variable
        foreach ($criteria as $field => $fieldValue) {
            $field = explode(self::SEPARATOR, $field);
            $fieldName = $field[0];

            if ($fieldValue instanceof PersistentCollection) {
                throw new Exception("You passed a PersistentCollection for field \"" . $fieldName . "\"");
            }

            // Handle partial entity/model input criteria
            if ($fieldName == lcfirst(self::OPTION_MODEL)) {
                $queryExpr = [];
                foreach ($fieldValue ?? [] as $entryID => $entryValue) {
                    $newField = [];
                    foreach ($field as $key => $value) {
                        $newField[$key] = $value;
                    }

                    array_unshift($newField, $entryID);

                    $queryExprPart = $this->buildQueryExpr($queryBuilder, $newField, $entryValue);
                    if ($queryExprPart == null) {
                        continue;
                    }

                    // In case of association field, compare value directly
                    if ($this->classMetadata->hasAssociation($entryID)) {
                        $queryBuilder->andWhere($queryExprPart);
                    } // If standard field, check for partial information
                    else {
                        $queryExpr[] = $queryExprPart;
                    }
                }

                // Query-by-Example: the select's set fields are AND-combined.
                $queryExpr = empty($queryExpr) ? null : $queryBuilder->expr()->andX(...$queryExpr);
            } else {
                // Default query builder
                $queryExpr = $this->buildQueryExpr($queryBuilder, $field, $fieldValue);
                if ($queryExpr == null) {
                    continue;
                }

                // Custom process in case of closest/farest
                $fieldRoot = implode(self::SEPARATOR, array_slice($field, count($field) - 2, 2));
                $closestTo = $this->findCustomOption($fieldRoot, self::OPTION_CLOSESTTO);
                $farestTo = $this->findCustomOption($fieldRoot, self::OPTION_FARESTTO);
                if ($closestTo || $farestTo) {
                    $orderBy[(string)$queryExpr] = $closestTo ? "ASC" : "DESC";
                    $queryExpr = null;
                }
            }

            if ($queryExpr !== null) {
                // Apply logical operator (if needed)
                $separator = $this->getSeparator();
                switch ($separator) {
                    case self::SEPARATOR_OR:
                        $queryBuilder->orWhere($queryExpr);
                        break;

                    case self::SEPARATOR_AND:
                        $queryBuilder->andWhere($queryExpr);
                        break;

                    default:
                        throw new Exception("Unknown separator \"" . $separator . "\" provided");
                }
            }
        }

        // Ordering result by group
        if ($groupBy) {
            $queryBuilder->select(self::ALIAS_ENTITY . " AS entity");
        } else {
            $queryBuilder->select(self::ALIAS_ENTITY);
        }
        
        $queryBuilder = $this->selectAs($queryBuilder, $selectAs);
        $queryBuilder = $this->orderBy($queryBuilder, $orderBy);
        $queryBuilder = $this->groupBy($queryBuilder, $groupBy);

        return $queryBuilder;
    }

    protected function getEagerQuery(QueryBuilder $queryBuilder, array $options = [], ?ClassMetadata $classMetadata = null): Query
    {
        $aliasRoot = $options["alias"] ?? self::ALIAS_ENTITY;
        $required = $options["required"] ?? false;

        $depth = $options["depth"] ?? 4;
        if ($depth-- < 1) {
            return $queryBuilder->getQuery();
        }

        $joinList = $options["join"] ?? array_combine($this->eagerly ?? [], array_fill(0, count($this->eagerly ?? []), []));
        $joinList = array_key_removes_numerics(array_inflate(".", $joinList));

        if ($classMetadata === null) {
            $classMetadata = $this->classMetadata;
        }

        foreach ($classMetadata->getAssociationMappings() as $associationMapping) {
            if ($associationMapping["fetch"] == ClassMetadata::FETCH_EAGER) {
                continue;
            }
            $aliasExpr = $aliasRoot . "." . $associationMapping["fieldName"];
            $aliasIdentifier = str_replace(".", "_", $aliasExpr);

            $continue = false;
            $expressions = $queryBuilder->getDQLParts()["select"];
            foreach ($expressions as $expr) {
                $continue = in_array($aliasIdentifier, $expr->getParts());
                if ($continue) {
                    break;
                }
            }

            if ($continue) {
                continue;
            }

            $expressions = $queryBuilder->getDQLParts()["join"][$aliasExpr] ?? [];
            foreach ($expressions as $expr) {
                $continue = $expr->getAlias() == $aliasIdentifier;
                if ($continue) {
                    break;
                }
            }

            if ($continue) {
                continue;
            }

            $sourceEntity = $associationMapping["sourceEntity"];
            $targetEntity = $associationMapping["targetEntity"];

            $targetEntityCacheable = $this->entityManager->getClassMetadata($targetEntity)->cache != null;
            while ($targetEntityCacheable && $targetEntity = get_parent_class($targetEntity)) {
                if (!$this->classMetadataManipulator->isEntity($targetEntity)) {
                    break;
                }
                $targetEntityCacheable &= $this->entityManager->getClassMetadata($targetEntity)->cache != null;
            }

            if ($required && !$targetEntityCacheable) {
                throw new Exception("\"" . $sourceEntity . "\" cannot be cached eagerly because of target entity is not configured as a second level cache.");
            }

            // if (class_implements_interface($sourceEntity, TranslatableInterface::class) && $associationMapping["fieldName"] == TranslatableWalker::COLUMN_NAME) {
            //     continue;
            // } // This is to make sure Translations are not eagerly loaded... see @WARN below.

            $targetEntity = $associationMapping["targetEntity"];
            if ($targetEntityCacheable && array_key_exists($associationMapping["fieldName"], $joinList)) {

                $this->leftJoin($queryBuilder, $aliasExpr);
                $queryBuilder->addSelect($aliasIdentifier);

                // $newOptions = [
                //     "alias" => $aliasIdentifier,
                //     "required" => $required,
                //     "depth" => $depth,
                //     "join" => $joinList[$associationMapping["fieldName"]]
                // ];

                // Map associations (eagerly loaded) to the query builder to make sure they are cached
                // $targetClassMetadata = $this->entityManager->getClassMetadata($targetEntity);
                // $this->getEagerQuery($queryBuilder, array_merge($options, $newOptions), $targetClassMetadata);
            }
        }

        return $queryBuilder->getQuery();
    }

    /**
     * @param array $criteria
     * @param array|null $orderBy
     * @param $limit
     * @param $offset
     * @param array|null $groupBy
     * @param array|null $selectAs
     * @return Query|null
     * @throws Exception
     */
    protected function getQuery(array $criteria = [], ?array $orderBy = null, $limit = null, $offset = null, ?array $groupBy = null, ?array $selectAs = null): ?Query
    {
        $queryBuilder = $this->getQueryBuilder($criteria, $orderBy ?? [], $limit, $offset, $groupBy ?? [], $selectAs ?? []);

        $dispatcher = $this->entityManager->getEventManager();
        if ($dispatcher->hasListeners(Events::preQuery)) {
            $eventArgs = new DoctrineQueryEventArgs($this->classMetadata, $queryBuilder, null);
            $dispatcher->dispatchEvent(Events::preQuery, $eventArgs);
            $queryBuilder = $eventArgs->getQueryBuilder();
        }

        $query = $this->eagerly === false ? $queryBuilder->getQuery() : $this->getEagerQuery($queryBuilder);
        if ($dispatcher->hasListeners(Events::onQuery)) {
            $eventArgs = new DoctrineQueryEventArgs($this->classMetadata, $queryBuilder, $query);
            $dispatcher->dispatchEvent(Events::onQuery, $eventArgs);
            $query = $eventArgs->getQuery();
        }        

        // @WARN, if groupBy is used, cache must be disabled.. id column not stored for some reasons.
        if ($groupBy) $query->setCacheable(false);

        $query->useQueryCache($this->cacheable);
    	if($this->classMetadata->cache !== null && array_key_exists("region", $this->classMetadata->cache)) {
            $query->setCacheRegion($this->classMetadata->cache["region"]);
        }

        if ($dispatcher->hasListeners(Events::postQuery)) {
            $eventArgs = new DoctrineQueryEventArgs($this->classMetadata, null, $query);
            $dispatcher->dispatchEvent(Events::postQuery, $eventArgs);
            $query = $eventArgs->getQuery();
        }

        return $query;
    }

    /**
     * @param array $criteria
     * @param string|null $mode
     * @param array|null $orderBy
     * @param array|null $groupBy
     * @param array|null $selectAs
     * @return Query
     * @throws Exception
     */
    protected function getQueryWithCount(array $criteria = [], ?string $mode = self::COUNT_ALL, ?array $orderBy = null, ?array $groupBy = null, ?array $selectAs = null)
    {
        if ($mode == self::COUNT_ALL) {
            $mode = "";
        }
        if ($mode && $mode != self::COUNT_DISTINCT) {
            throw new Exception("Unexpected \"mode\" provided: \"" . $mode . "\"");
        }

        $column = $this->getAlias($this->getColumn());
        if (!$column) {
            $column = "id";
        }

        $e_column = $column;
        if ($this->classMetadata->hasAssociation($column)) {
            $e_column = self::ALIAS_ENTITY . "_" . $e_column;
        } elseif ($this->classMetadata->hasField($column)) {
            $e_column = self::ALIAS_ENTITY . "." . $e_column;
        }

        $grouped = !empty($groupBy) || !empty($selectAs);

        $queryBuilder = $this->getQueryBuilder($criteria, $grouped ? ($orderBy ?? []) : [], null, null, $groupBy ?? $selectAs ?? [], $selectAs ?? []);
        if ($this->classMetadata->hasAssociation($column)) {
            $this->leftJoin($queryBuilder, self::ALIAS_ENTITY . "." . $column);
        }

        if (!$grouped) {
            // One number: COUNT() alone, neither the entity nor a GROUP BY
            // (grouped by the counted column, each row counted itself).
            $queryBuilder->select('COUNT(' . trim($mode . ' ' . $e_column) . ')')->resetDQLPart('orderBy');
            return $queryBuilder->getQuery();
        }

        $queryBuilder->addSelect('COUNT(' . trim($mode . ' ' . $e_column) . ') AS count');
        $queryBuilder->addGroupBy($e_column);

        return $queryBuilder->getQuery();
    }

    /**
     * @param array $criteria
     * @param array|null $orderBy
     * @param $limit
     * @param $offset
     * @param array|null $groupBy
     * @param array|null $selectAs
     * @return Query
     * @throws Exception
     */
    protected function getQueryWithLength(array $criteria = [], ?array $orderBy = null, $limit = null, $offset = null, ?array $groupBy = null, ?array $selectAs = null)
    {
        $column = $this->getAlias($this->getColumn());

        $e_column = $column;
        if ($this->classMetadata->hasAssociation($column)) {
            $e_column = self::ALIAS_ENTITY . "_" . $column;
        } elseif ($this->classMetadata->hasField($column)) {
            $e_column = self::ALIAS_ENTITY . "." . $column;
        }

        $queryBuilder = $this->getQueryBuilder($criteria, $orderBy ?? [], $limit, $offset, $groupBy ?? $selectAs ?? [], $selectAs ?? []);

        if ($this->classMetadata->hasAssociation($column)) {
            $this->leftJoin($queryBuilder, $column);
        }

        $queryBuilder->addSelect("LENGTH(" . $e_column . ") as length");

        return $queryBuilder->getQuery();
    }

    /**
     * @param QueryBuilder $queryBuilder
     * @param $selectAs
     * @return QueryBuilder
     */
    protected function selectAs(QueryBuilder $queryBuilder, $selectAs)
    {
        if (!$selectAs) {
            return $queryBuilder;
        }

        foreach ($selectAs as $select => $as) {
            $queryBuilder->addSelect(is_string($select) ? $as . " AS " . $select : $as);
        }

        return $queryBuilder;
    }

    /**
     * @param QueryBuilder $queryBuilder
     * @param $groupBy
     * @return QueryBuilder
     * @throws Exception
     */
    protected function groupBy(QueryBuilder $queryBuilder, $groupBy)
    {
        if (!$groupBy) {
            return $queryBuilder;
        }

        $column = explode("\\", $this->classMetadata->getName());
        $column = lcfirst(end($column));
        $column = $this->getAlias($column);

        if (is_string($groupBy)) {
            $groupBy = [$groupBy];
        }
        if (!is_array($groupBy)) {
            throw new Exception("Unexpected \"groupBy\" argument type provided \"" . gettype($groupBy) . "\"");
        }

        foreach ($groupBy as $key => $column) {
            $aliasIdentifier = str_replace(".", "_", $column);

            $column = implode(".", array_map(fn($c) => $this->getAlias($c), explode(".", $column)));
            $columnHead = explode(".", $column)[0] ?? $column;

            $groupBy[$key] = $column;
            if ($this->classMetadata->hasAssociation($columnHead)) {
                $groupBy[$key] = self::ALIAS_ENTITY . "_" . $groupBy[$key];
            } elseif ($this->classMetadata->hasField($columnHead)) {
                $groupBy[$key] = self::ALIAS_ENTITY . "." . $groupBy[$key];
            }

            if ($this->classMetadata->hasAssociation($column)) {
                $this->leftJoin($queryBuilder, self::ALIAS_ENTITY . "." . $column, $groupBy[$key]);
                $queryBuilder->addSelect("(" . $groupBy[$key] . ".id) AS " . $aliasIdentifier);
            }
        }

        return $queryBuilder->groupBy(implode(",", $groupBy));
    }

    protected array $joinList = [];

    /**
     * @param QueryBuilder $queryBuilder
     * @param $join
     * @param $alias
     * @param $conditionType
     * @param $condition
     * @param $indexBy
     * @return bool
     */
    protected function innerJoin(QueryBuilder $queryBuilder, $join, $alias = null, $conditionType = null, $condition = null, $indexBy = null): bool
    {
        if (in_array($join, $this->joinList[spl_object_hash($queryBuilder)] ?? [])) {
            return false;
        }

        $queryBuilder->innerJoin($join, $alias ?? str_replace(".", "_", $join), $conditionType, $condition, $indexBy);
        $this->joinList[spl_object_hash($queryBuilder)][] = $join;

        return true;
    }

    /**
     * @param QueryBuilder $queryBuilder
     * @param $join
     * @param $alias
     * @param $conditionType
     * @param $condition
     * @param $indexBy
     * @return bool
     */
    protected function leftJoin(QueryBuilder $queryBuilder, $join, $alias = null, $conditionType = null, $condition = null, $indexBy = null): bool
    {
        if (in_array($join, $this->joinList[spl_object_hash($queryBuilder)] ?? [])) {
            return false;
        }

        $queryBuilder->leftJoin($join, $alias ?? str_replace(".", "_", $join), $conditionType, $condition, $indexBy);
        $this->joinList[spl_object_hash($queryBuilder)][] = $join;
        return true;
    }

    /**
     * @param QueryBuilder $queryBuilder
     * @param string|array|null $orderBy
     * @return QueryBuilder
     * @throws Exception
     */
    protected function orderBy(QueryBuilder $queryBuilder, string|array|null $orderBy)
    {
        if (!$orderBy) {
            return $queryBuilder;
        }

        $column = explode("\\", $this->classMetadata->getName());
        $column = lcfirst(end($column));

        if (is_string($orderBy)) {
            $orderBy = [$orderBy => "ASC"];
        }
        if (!is_array($orderBy)) {
            throw new Exception("Unexpected \"orderBy\" argument type provided \"" . gettype($orderBy) . "\"");
        }

        $first = true;
        foreach ($orderBy as $name => $value) {
            $path = array_map(fn($name) => $this->getAlias($name), explode(".", $name));
            $name = implode(".", $path);
            $entity = $path[0];

            $isRandom = ($name == "id" && strtolower($value) == "rand");
            if (!$isRandom) {
                $formattedName = $name;
                if ($this->classMetadata->hasField($entity)) {
                    $formattedName = self::ALIAS_ENTITY . "." . $name;
                } elseif ($this->classMetadata->hasAssociation($entity)) {
                    $formattedName = self::ALIAS_ENTITY . "_" . $name;
                }

                if ($this->classMetadata->hasAssociation($entity)) {
                    $this->leftJoin($queryBuilder, self::ALIAS_ENTITY . "." . $entity);
                }
            }

            $orderBy = $first ? "orderBy" : "addOrderBy";

            if ($isRandom) {
                $queryBuilder->orderBy('RAND()');
            } elseif (is_array($value)) {
                $queryBuilder->add($orderBy, "FIELD(" . $formattedName . "," . implode(",", $value) . ")");
            } else {
                $queryBuilder->$orderBy($formattedName, $value);
            }

            $first = false;
        }

        return $queryBuilder;
    }
}
