<?php
/**
 * @author  Almog Baku
 *          almog@GoDisco.net
 *          http://www.GoDisco.net/
 *
 * AclHelper - Based by gist authored by Anil (https://gist.github.com/mailaneel/1363377)
 */

namespace GoDisco\AclTreeBundle\Security\Helper;

use Doctrine\ORM\Query;
use Doctrine\ORM\QueryBuilder;
use GoDisco\AclTreeBundle\Annotation\AclParentReader;
use Symfony\Bridge\Doctrine\RegistryInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Core\SecurityContextInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Doctrine\Common\Persistence\ObjectManager;

class AclTreeHelper
{
    /** @var  ObjectManager */
    private $em;
    /** @var SecurityContextInterface */
    private $securityContext;
    /** @var  AclParentReader */
    private $aclReader;

    private $maskBuilderClass;
    private $aclConnection;

    /**
     * Constructor
     *
     * @param RegistryInterface $doctrine
     * @param SecurityContextInterface $securityContext
     * @param AclParentReader $aclReader
     * @param $aclConnection
     * @param $maskBuilderClass - Mask builder class name
     */
    function __construct(RegistryInterface $doctrine, SecurityContextInterface $securityContext, AclParentReader $aclReader, $aclConnection, $maskBuilderClass = 'Symfony\Component\Security\Acl\Permission\MaskBuilder')
    {
        $this->em = $doctrine->getManager();
        $this->securityContext = $securityContext;
        $this->aclReader = $aclReader;
        $this->aclConnection = $doctrine->getConnection($aclConnection);
        $this->maskBuilderClass = $maskBuilderClass;
    }

    /**
     * Deep clone the query
     *
     * @param Query $query
     * @return Query
     */
    protected function cloneQuery(Query $query)
    {
        $aclAppliedQuery = clone $query;
        $params = $query->getParameters();

        foreach ($params as $param)
            $aclAppliedQuery->setParameter($param->getName(), $param->getValue(), $param->getType());

        return $aclAppliedQuery;
    }

    /**
     * Make the query supported ACL permissions
     *
     * @param QueryBuilder $queryBuilder
     * @param array $permissions
     * @param UserInterface $user
     * @throws AccessDeniedException not found logged-in user
     * @return Query
     */
    public function apply(QueryBuilder $queryBuilder, array $permissions = array("VIEW"), UserInterface $user = null)
    {
        if($user==null) {
            $token = $this->securityContext->getToken();
            $user = $token->getUser();
            if(!($user instanceof UserInterface))
                throw new AccessDeniedException();
        }

        //create cloned query with sterility aliases
        $query = $this->cloneQuery($queryBuilder->getQuery());

        //Build permissions-mask
        $builder = new $this->maskBuilderClass();
        foreach ($permissions as $permission) {
            $mask = constant(get_class($builder) . '::MASK_' . strtoupper($permission));
            $builder->add($mask);
        }
        $mask = $builder->get();

        //Build the AclTree
        $classesMap = $this->aclReader->getAclMetaTree($queryBuilder->getRootEntities()[0]);
        $query->setHint('acl.tree.classes.map', $classesMap);

        //Build the Acl query for the tree
        $aclQuery = $this->getAclQuery($classesMap, $mask, $user);
        $query->setHint('acl.query', $aclQuery);

        //add master alias
        $query->setHint('acl.original.dqlAlias', $queryBuilder->getRootAliases()[0]);

        //add the SqlWalker
        $query->setHint(Query::HINT_CUSTOM_OUTPUT_WALKER, 'GoDisco\AclTreeBundle\Security\ORM\SqlWalker\AclTreeWalker');

        return $query;
    }

    /**
     * Get query for permitted entity ids(by acl) for user
     *
     * @param Query $query
     * @param QueryBuilder $queryBuilder
     * @return String Sql
     */
    private function getAclQuery($classesMap, $mask, UserInterface $user)
    {
        //get the database name of the ACL
        $database = $this->aclConnection->getDatabase();

        //classes to search
        $classes = $this->aclReader->classesMap_to_list($classesMap);
        foreach ($classes as $key=>$class)
            $classes[$key] = '"' . $this->escapeNamespace($class) . '"';
        $classes = implode(", ", $classes);

        $userRoles = array('""');
        if (is_object($user)) {
            $userClassIdn = $this->escapeNamespace(get_class($user));
            $userRoles[] = '"' . $userClassIdn . '-' . $user->getUserName() . '"';

            // The reason we ignore this is because by default FOSUserBundle adds ROLE_USER for every user
            foreach ($user->getRoles() as $role)
                if ($role !== 'ROLE_USER')
                    $userRoles[] = '"' . $role . '"';
        }
        $userRoles = implode(", ", $userRoles);

        return <<<SQL
SELECT o.object_identifier as id, c.class_type as class FROM {$database}.acl_object_identities as o
  INNER JOIN {$database}.acl_classes c ON c.id = o.class_id
  LEFT JOIN {$database}.acl_entries e ON (
    e.class_id = o.class_id AND (
      e.object_identity_id = o.id
      OR {$this->aclConnection->getDatabasePlatform()->getIsNullExpression('e.object_identity_id')}
    )
  )
  LEFT JOIN {$database}.acl_security_identities s ON (
    s.id = e.security_identity_id
  )
  WHERE
    c.class_type IN ({$classes})
    AND ( s.identifier IN ({$userRoles}) )
    AND e.mask >= {$mask}
  GROUP BY id, class
SQL;
    }

    /**
     * Escaping namespace
     *
     * @param $namespace
     * @return string
     */
    private function escapeNamespace($namespace) {
        return str_replace('\\', '\\\\', $namespace);
    }
}