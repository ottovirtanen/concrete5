<?
namespace Concrete\Core\User;

use Concrete\Core\Search\DatabaseItemList;
use Concrete\Core\User\Group\Group;
use Pagerfanta\Adapter\DoctrineDbalAdapter;
use Concrete\Core\Search\Pagination\Pagination;
use UserInfo as CoreUserInfo;

class UserList extends DatabaseItemList
{

    protected $attributeClass = 'UserAttributeKey';

    /**
     * Columns in this array can be sorted via the request.
     * @var array
     */
    protected $autoSortColumns = array(
        'u.uName',
        'u.uEmail',
        'u.uDateAdded',
        'u.uLastLogin',
        'u.uNumLogins',
        'u.uLastOnline'
    );

    /**
     * Whether to include inactive users.
     * @var bool
     */
    protected $includeInactiveUsers = false;


    /**
     * Whether to include unvalidated users.
     * @var bool
     */
    protected $includeUnvalidatedUsers = false;

    /**
     * The total results of the query
     * @return int
     */
    public function getTotalResults()
    {
        $query = $this->deliverQueryObject();
        return $query->select('count(distinct u.uID)')->setMaxResults(1)->execute()->fetchColumn();
    }

    /**
     * Gets the pagination object for the query.
     * @return Pagination
     */
    protected function createPaginationObject()
    {
        $adapter = new DoctrineDbalAdapter($this->deliverQueryObject(), function ($query) {
            $query->select('count(distinct u.uID)')->setMaxResults(1);
        });
        $pagination = new Pagination($this, $adapter);
        return $pagination;
    }

    /**
     * @param $queryRow
     * @return \Concrete\Core\User\UserInfo
     */
    public function getResult($queryRow)
    {
        $ui = CoreUserInfo::getByID($queryRow['uID']);
        return $ui;
    }

    /**
     * similar to get except it returns an array of userIDs
     * much faster than getting a UserInfo object for each result if all you need is the user's id
     * @return array $userIDs
     */
    public function getResultIDs()
    {
        $results = $this->executeGetResults();
        $ids = array();
        foreach($results as $result) {
            $ids[] = $result['uID'];
        }
        return $ids;
    }

    public function createQuery()
    {
        $this->query->select('u.uID')
            ->from('Users', 'u')
            ->leftJoin('u', 'UserSearchIndexAttributes', 'ua', 'u.uID = ua.uID');
    }

    public function finalizeQuery(\Doctrine\DBAL\Query\QueryBuilder $query)
    {
        if (!$this->includeInactiveUsers) {
            $query->andWhere('u.uIsActive = :uIsActive');
            $query->setParameter('uIsActive', true);
        }
        if (!$this->includeUnvalidatedUsers) {
            $query->andWhere('u.uIsValidated != 0');
        }
        return $query;
    }

    public function includeInactiveUsers()
    {
        $this->includeInactiveUsers = true;
    }

    public function includeUnvalidatedUsers()
    {
        $this->includeUnvalidatedUsers = true;
    }

    /**
     * Explicitly filters by whether a user is active or not. Does this by setting "include inactive users"
     * to true, THEN filtering them in our out. Some settings here are redundant given the default settings
     * but a little duplication is ok sometimes.
     * @param $val
     */
    public function filterByIsActive($isActive)
    {
        $this->includeInactiveUsers();
        $this->query->andWhere('u.uIsActive = :uIsActive');
        $this->query->setParameter('uIsActive', $isActive);
    }

    /**
     * Filter list by user name
     * @param $username
     */
    public function filterByUserName($username)
    {
        $this->query->andWhere('u.uName = :uName');
        $this->query->setParameter('uName', $username);
    }

    /**
     * Filters keyword fields by keywords (including username, email and attributes).
     * @param $keywords
     */
    public function filterByKeywords($keywords)
    {
        $expressions = array(
            $this->query->expr()->like('u.uName', ':keywords'),
            $this->query->expr()->like('u.uEmail', ':keywords')
        );

        $keys = \Concrete\Core\Attribute\Key\UserKey::getSearchableIndexedList();
        foreach ($keys as $ak) {
            $cnt = $ak->getController();
            $expressions[] = $cnt->searchKeywords($keywords, $this->query);
        }
        $expr = $this->query->expr();
        $this->query->andWhere(call_user_func_array(array($expr, 'orX'), $expressions));
        $this->query->setParameter('keywords', '%' . $keywords . '%');
    }


    /**
     * Filters the user list for only users within the provided group.  Accepts an instance of a group object or a string group name
     * @param \Concrete\Core\User\Group | string $group
     * @param boolean $inGroup
     * @return void
     */
    public function filterByGroup($group = '', $inGroup = true)
    {
        if (!($group instanceof \Concrete\Core\User\Group\Group)) {
            $group = \Concrete\Core\User\Group\Group::getByName($group);
        }

        $table = 'ug' . $group->getGroupID();
        $this->query->leftJoin('u', 'UserGroups', $table, 'u.uID = ' . $table . '.uID');
        if ($inGroup) {
            $this->query->andWhere($table . '.gID = :gID' . $group->getGroupID());
            $this->query->setParameter('gID' . $group->getGroupID(), $group->getGroupID());
        } else {
            $this->query->andWhere($table . '.gID is null');
        }
    }

    /**
     * Filters by date added
     * @param string $date
     */
    public function filterByDateAdded($date, $comparison = '=')
    {
        $this->query->andWhere($this->query->expr()->comparison('u.uDateAdded', $comparison, ':uDateAdded'));
        $this->query->setParameter('uDateAdded', $date);
    }

    /**
     * Filters by Group ID
     */
    public function filterByGroupID($gID)
    {
        $group = Group::getByID($gID);
        $this->filterByGroup($group);
    }

    public function sortByUserID()
    {
        $this->query->orderBy('u.uID', 'asc');
    }

    /* magic method for filtering by user attributes. */
    public function __call($nm, $a)
    {
        if (substr($nm, 0, 8) == 'filterBy') {
            $handle = uncamelcase(substr($nm, 8));
            if (count($a) == 2) {
                $this->filterByAttribute($attrib, $a[0], $a[1]);
            } else {
                $this->filterByAttribute($attrib, $a[0]);
            }
        } else {
            if (substr($nm, 0, 6) == 'sortBy') {
                $handle = uncamelcase(substr($nm, 6));
                if (count($a) == 1) {
                    $this->sortBy($attrib, $a[0]);
                } else {
                    $this->sortBy($attrib);
                }
            } else {
                throw new \Exception(t('%s method does not exist for the UserList class', $nm));
            }
        }
    }



}