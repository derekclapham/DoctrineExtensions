<?php

namespace Gedmo\Sluggable;

use Doctrine\Common\EventSubscriber,
    Doctrine\ORM\Events,
    Doctrine\ORM\Event\LifecycleEventArgs,
    Doctrine\ORM\Event\OnFlushEventArgs,
    Doctrine\ORM\Event\LoadClassMetadataEventArgs,
    Doctrine\ORM\EntityManager,
    Doctrine\ORM\Query,
    Doctrine\ORM\Mapping\ClassMetadata,
    Doctrine\ORM\Mapping\ClassMetadataInfo,
    Doctrine\Common\Annotations\AnnotationReader;

/**
 * The SluggableListener handles the generation of slugs
 * for entities which implements the Sluggable interface.
 * 
 * This behavior can inpact the performance of your application
 * since it does some additional calculations on persisted entities.
 * 
 * @author Gediminas Morkevicius <gediminas.morkevicius@gmail.com>
 * @subpackage SluggableListener
 * @package Gedmo.Sluggable
 * @link http://www.gediminasm.org
 * @license MIT License (http://www.opensource.org/licenses/mit-license.php)
 */
class SluggableListener implements EventSubscriber
{   
    /**
     * The namespace of annotations for this extension
     */
    const ANNOTATION_NAMESPACE = 'gedmo';
    
    /**
     * Annotation to mark field as sluggable and include it in slug building
     */
    const ANNOTATION_SLUGGABLE = 'Gedmo\Sluggable\Mapping\Sluggable';
    
    /**
     * Annotation to identify field as one which holds the slug
     * together with slug options
     */
    const ANNOTATION_SLUG = 'Gedmo\Sluggable\Mapping\Slug';
    
    /**
     * List of cached entity configurations
     *  
     * @var array
     */
    protected $_configurations = array();
    
    /**
     * List of entities which needs to be processed
     * after the insertion operations, because
     * query executions will be needed
     * 
     * @var array
     */
    protected $_pendingEntities = array();
    
    /**
     * List of types which are valid for slug and sluggable fields
     * 
     * @var array
     */
    private $_validTypes = array(
        'string'
    );
    
    /**
     * Specifies the list of events to listen
     * 
     * @return array
     */
    public function getSubscribedEvents()
    {
        return array(
            Events::prePersist,
            Events::postPersist,
            Events::onFlush,
            Events::loadClassMetadata
        );
    }

    /**
     * Get the configuration for specific entity class
     * if cache driver is present it scans it also
     * 
     * @param EntityManager $em
     * @param string $class
     * @return array
     */
    public function getConfiguration(EntityManager $em, $class) {
        $config = array();
        if (isset($this->_configurations[$class])) {
            $config = $this->_configurations[$class];
        } else {
            $cacheDriver = $em->getMetadataFactory()->getCacheDriver();
            if (($cached = $cacheDriver->fetch("{$class}\$GEDMO_SLUGGABLE_CLASSMETADATA")) !== false) {
                $this->_configurations[$class] = $cached;
                $config = $cached;
            }
        }
        return $config;
    }
    
    /**
     * Scans the entities for Sluggable annotations
     * 
     * @param LoadClassMetadataEventArgs $eventArgs
     * @throws Sluggable\Exception if any mapping data is invalid
     * @throws RuntimeException if ORM version is old
     * @return void
     */
    public function loadClassMetadata(LoadClassMetadataEventArgs $eventArgs)
    {
        if (!method_exists($eventArgs, 'getEntityManager')) {
            throw new \RuntimeException('Sluggable: update to latest ORM version, checkout latest ORM from master branch on github');
        }
        $meta = $eventArgs->getClassMetadata();
        if ($meta->isMappedSuperclass) {
            return; // ignore mappedSuperclasses for now
        }
        
        $em = $eventArgs->getEntityManager();
        $config = array();
        // collect metadata from inherited classes
        foreach (array_reverse(class_parents($meta->name)) as $parentClass) {
            // read only inherited mapped classes
            if ($em->getMetadataFactory()->hasMetadataFor($parentClass)) {
                $this->_readAnnotations($em->getClassMetadata($parentClass), $config);
            }
        }
        $this->_readAnnotations($meta, $config);
        if ($config && !isset($config['fields'])) {
            throw Exception::noFieldsToSlug($meta->name);
        }
        // cache the metadata
        if ($config) {
            $this->_configurations[$meta->name] = $config;
            // cache the metadata
            if ($cacheDriver = $em->getMetadataFactory()->getCacheDriver()) {
                $cacheDriver->save(
                    "{$meta->name}\$GEDMO_SLUGGABLE_CLASSMETADATA", 
                    $this->_configurations[$meta->name],
                    null
                );
            }
        }
    }
    
    /**
     * Checks for persisted entity to specify slug
     * 
     * @param LifecycleEventArgs $args
     * @return void
     */
    public function prePersist(LifecycleEventArgs $args)
    {
        $em = $args->getEntityManager();
        $entity = $args->getEntity();
        
        if ($config = $this->getConfiguration($em, get_class($entity))) {
            $this->_generateSlug($em, $entity, false);
        }
    }
    
    /**
     * Checks for inserted entities to update their slugs
     * 
     * @param LifecycleEventArgs $args
     * @return void
     */
    public function postPersist(LifecycleEventArgs $args)
    {
        $em = $args->getEntityManager();
        $uow = $em->getUnitOfWork();

        // there can be other entities being inserted because
        // unitofwork does inserts by class ordered chunks
        if (!$uow->hasPendingInsertions()) {
            while ($entity = array_shift($this->_pendingEntities)) {
                // we know that this slug must be unique and
                // it was preprocessed allready
                $config = $this->getConfiguration($em, get_class($entity));
                $slug = $this->_makeUniqueSlug($em, $entity);
                $uow->scheduleExtraUpdate($entity, array(
                    $config['slug'] => array(null, $slug)
                ));
            }
        }
    }
    
    /**
     * Generate slug on entities being updated during flush
     * if they require changing
     * 
     * @param OnFlushEventArgs $args
     * @return void
     */
    public function onFlush(OnFlushEventArgs $args)
    {
        $em = $args->getEntityManager();
        $uow = $em->getUnitOfWork();
        
        // we use onFlush and not preUpdate event to let other
        // event listeners be nested together
        foreach ($uow->getScheduledEntityUpdates() as $entity) {
            if ($config = $this->getConfiguration($em, get_class($entity))) {
                if ($config['updatable']) {
                    $this->_generateSlug($em, $entity, $uow->getEntityChangeSet($entity));
                }
            }
        }
    }
    
    /**
     * Creates the slug for entity being flushed
     * 
     * @param EntityManager $em
     * @param object $entity
     * @param mixed $changeSet
     *      case array: the change set array
     *      case boolean(false): entity is not managed
     * @throws Sluggable\Exception if parameters are missing
     *      or invalid
     * @return void
     */
    protected function _generateSlug(EntityManager $em, $entity, $changeSet)
    {
        $entityClass = get_class($entity);
        $uow = $em->getUnitOfWork();
        $meta = $em->getClassMetadata($entityClass);
        $config = $this->getConfiguration($em, $entityClass);
        
        // collect the slug from fields
        $slug = '';
        $needToChangeSlug = false;
        foreach ($config['fields'] as $sluggableField) {
            if ($changeSet === false || isset($changeSet[$sluggableField])) {
                $needToChangeSlug = true;
            }
            $slug .= $meta->getReflectionProperty($sluggableField)->getValue($entity) . ' ';
        }
        // if slug is not changed, no need further processing
        if (!$needToChangeSlug) {
            return; // nothing to do
        }
        
        if (!strlen(trim($slug))) {
            throw Exception::slugIsEmpty();
        }
        
        // build the slug
        $slug = call_user_func_array(
            array('Gedmo\Sluggable\Util\Urlizer', 'urlize'), 
            array($slug, $config['separator'], $entity)
        );

        // stylize the slug
        switch ($config['style']) {
            case 'camel':
                $slug = preg_replace_callback(
                    '@^[a-z]|' . $config['separator'] . '[a-z]@smi', 
                    create_function('$m', 'return strtoupper($m[0]);'), 
                    $slug
                );
                break;
                
            default:
                // leave it as is
                break;
        }
        
        // cut slug if exceeded in length
        $mapping = $meta->getFieldMapping($config['slug']);
        if (strlen($slug) > $mapping['length']) {
            $slug = substr($slug, 0, $mapping['length']);
        }

        // make unique slug if requested
        if ($config['unique'] && !$uow->hasPendingInsertions()) {
            // set the slug for further processing
            $meta->getReflectionProperty($config['slug'])->setValue($entity, $slug);
            $slug = $this->_makeUniqueSlug($em, $entity);
        }
        // set the final slug
        $meta->getReflectionProperty($config['slug'])->setValue($entity, $slug);
        // recompute changeset if entity is managed
        if ($changeSet !== false) {
            $uow->recomputeSingleEntityChangeSet($meta, $entity);
        } elseif ($config['unique'] && $uow->hasPendingInsertions()) {
            // @todo: make support for unique field metadata on concurrent operations
            if ($meta->isUniqueField($config['slug'])) {
                throw Exception::slugFieldIsUnique($config['slug']);
            }
            $this->_pendingEntities[] = $entity;
        }
    }
    
    /**
     * Generates the unique slug
     * 
     * @param EntityManager $em
     * @param object $entity
     * @throws Sluggable\Exception if unit of work has pending inserts
     *      to avoid infinite loop
     * @return string - unique slug
     */
    protected function _makeUniqueSlug(EntityManager $em, $entity)
    {
        if ($em->getUnitOfWork()->hasPendingInsertions()) {
            throw Exception::pendingInserts();
        }
        
        $entityClass = get_class($entity);
        $meta = $em->getClassMetadata($entityClass);
        $config = $this->getConfiguration($em, $entityClass);
        $preferedSlug = $meta->getReflectionProperty($config['slug'])->getValue($entity);
        
        // @todo: optimize
        // search for similar slug
        $qb = $em->createQueryBuilder();
        $qb->select('rec.' . $config['slug'])
            ->from($entityClass, 'rec')
            ->add('where', $qb->expr()->like(
                'rec.' . $config['slug'], 
                $qb->expr()->literal($preferedSlug . '%'))
            );
        // include identifiers
        $entityIdentifiers = $meta->getIdentifierValues($entity);
        foreach ($entityIdentifiers as $field => $value) {
            if (strlen($value)) {
                $qb->add('where', 'rec.' . $field . ' <> ' . $value);
            }
        }
        $q = $qb->getQuery();
        $q->setHydrationMode(Query::HYDRATE_ARRAY);
        $result = $q->execute();
        
        if (is_array($result) && count($result)) {
            $generatedSlug = $preferedSlug;
            $sameSlugs = array();
            foreach ($result as $list) {
                $sameSlugs[] = $list['slug'];
            }

            $i = 0;
            if (preg_match("@{$config['separator']}\d+$@sm", $generatedSlug, $m)) {
                $i = abs(intval($m[0]));
            }
            while (in_array($generatedSlug, $sameSlugs)) {
                $generatedSlug = $preferedSlug . $config['separator'] . ++$i;
            }
            
            $mapping = $meta->getFieldMapping($config['slug']);
            $needRecursion = false;
            if (strlen($generatedSlug) > $mapping['length']) {
                $needRecursion = true;
                $generatedSlug = substr(
                    $generatedSlug, 
                    0, 
                    $mapping['length'] - (strlen($i) + strlen($config['separator']))
                );
                $generatedSlug .= $config['separator'] . $i;
            }
            
            $meta->getReflectionProperty($config['slug'])->setValue($entity, $generatedSlug);
            if ($needRecursion) {
                $generatedSlug = $this->_makeUniqueSlug($em, $entity);
            }
            $preferedSlug = $generatedSlug;
        }
        return $preferedSlug;
    }
    
    /**
     * Reads the Sluggable annotations from the given class
     * And collects or ovverides the configuration
     * Returns configuration options or empty array if none found
     * 
     * @param ClassMetadataInfo $meta
     * @param array $config
     * @throws Sluggable\Exception if any mapping data is invalid
     * @return void
     */
    protected function _readAnnotations(ClassMetadataInfo $meta, array &$config)
    {        
        require_once __DIR__ . '/Mapping/Annotations.php';
        $reader = new AnnotationReader();
        $reader->setAnnotationNamespaceAlias(
            'Gedmo\Sluggable\Mapping\\',
            self::ANNOTATION_NAMESPACE
        );
    
        $class = $meta->getReflectionClass();
        // property annotations
        foreach ($class->getProperties() as $property) {
            // sluggable property
            if ($sluggable = $reader->getPropertyAnnotation($property, self::ANNOTATION_SLUGGABLE)) {
                $field = $property->getName();
                if (!$meta->hasField($field)) {
                    throw Exception::fieldMustBeMapped($field, $meta->name);
                }
                if (!$this->_isValidField($meta, $field)) {
                    throw Exception::notValidFieldType($field, $meta->name);
                }
                $config['fields'][] = $field;
            }
            // slug property
            if ($slug = $reader->getPropertyAnnotation($property, self::ANNOTATION_SLUG)) {
                $field = $property->getName();
                if (!$meta->hasField($field)) {
                    throw Exception::slugFieldMustBeMapped($field, $meta->name);
                }
                if (!$this->_isValidField($meta, $field)) {
                    throw Exception::notValidFieldType($field, $meta->name);
                } 
                if (isset($config['slug'])) {
                    throw Exception::slugFieldIsDuplicate($field, $meta->name);
                }
                
                $config['slug'] = $field;
                $config['style'] = $slug->style;
                $config['updatable'] = $slug->updatable;
                $config['unique'] = $slug->unique;
                $config['separator'] = $slug->separator;
            }
        }
    }
    
    /**
     * Checks if $field type is valid as Sluggable or Slug field
     * 
     * @param ClassMetadata $meta
     * @param string $field
     * @return boolean
     */
    protected function _isValidField(ClassMetadata $meta, $field)
    {
        return in_array($meta->getTypeOfField($field), $this->_validTypes);
    }
}