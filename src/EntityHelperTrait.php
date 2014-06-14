<?php

/**
 * @file
 * Contains Drupal\entity_embed\EntityHelperTrait.
 */

namespace Drupal\entity_embed;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityManagerInterface;
use Drupal\Core\Entity\EntityStorageException;
use Drupal\Core\Session\AccountInterface;

/**
 * Wrapper methods for entity loading and rendering.
 *
 * This utility trait should only be used in application-level code, such as
 * classes that would implement ContainerInjectionInterface. Services registered
 * in the Container should not use this trait but inject the appropriate service
 * directly for easier testing.
 */
trait EntityHelperTrait {

  /**
   * The entity manager service.
   *
   * @var \Drupal\Core\Entity\EntityManagerInterface
   */
  protected $entityManager;

  /**
   * Loads an entity from the database.
   *
   * @param string $entity_type
   *   The entity type to load, e.g. node or user.
   * @param mixed $id
   *   The id or UUID of the entity to load.
   *
   * @return \Drupal\Core\Entity\EntityInterface
   *   The entity object, or NULL if there is no entity with the given id or
   *   UUID.
   */
  protected function loadEntity($entity_type, $id) {
    $entities = $this->loadMultipleEntities($entity_type, array($id));
    return !empty($entities) ? reset($entities) : NULL;
  }

  /**
   * Loads multiple entities from the database.
   *
   * @param string $entity_type
   *   The entity type to load, e.g. node or user.
   * @param array $ids
   *   An array of entity IDs or UUIDs.
   *
   * @return array
   *   An array of entity objects indexed by their ids.
   *
   * @throws \Drupal\Core\Entity\EntityStorageException
   */
  protected function loadMultipleEntities($entity_type, array $ids) {
    $entities = array();
    $storage = $this->entityManager()->getStorage($entity_type);

    $uuids = array_filter($ids, 'Drupal\Component\Uuid\Uuid::isValid');
    if (!empty($uuids)) {
      $definition = $this->entityManager()->getDefinition($entity_type);
      if (!$uuid_key = $definition->getKey('uuid')) {
        throw new EntityStorageException("Entity type $entity_type does not support UUIDs.");
      }
      $entities += $storage->loadByProperties(array($uuid_key => $uuids));
    }

    if ($remaining_ids = array_diff($ids, $uuids)) {
      $entities += $storage->loadMultiple($remaining_ids);
    }

    return $entities;
  }

  /**
   * Determines if an entity can be rendered.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity object.
   *
   * @return bool
   *   TRUE if the entity's type has a view builder controller, otherwise FALSE.
   */
  protected function canRenderEntity(EntityInterface $entity) {
    $entity_type = $entity->getEntityTypeId();
    return $this->entityManager()->hasController($entity_type, 'view_builder');
  }

  /**
   * Returns the render array for an entity.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity to be rendered.
   * @param string $view_mode
   *   The view mode that should be used to display the entity.
   * @param string $langcode
   *   (optional) For which language the entity should be rendered, defaults to
   *   the current content language.
   *
   * @return array
   *   A render array for the entity.
   */
  protected function renderEntity(EntityInterface $entity, $view_mode, $langcode = NULL) {
    $render_controller = $this->entityManager()->getViewBuilder($entity->getEntityTypeId());
    return $render_controller->view($entity, $view_mode, $langcode);
  }

  /**
   * Check access to an entity.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity object.
   * @param string $operation
   *   (optional) The operation to be performed. Defaults to view.
   * @param \Drupal\Core\Session\AccountInterface $account
   *   (optional) The user for which to check access, or NULL to check access
   *   for the current user. Defaults to NULL.
   *
   * @return bool|null
   *   self::ALLOW, self::DENY, or self::KILL.
   */
  protected function accessEntity(EntityInterface $entity, $operation = 'view', AccountInterface $account = NULL) {
    switch ($entity->getEntityTypeId()) {
      case 'file':
        // Due to issues with access checking with file entities in core,
        // we cannot actually use Entity::access() which would have been called
        // by parent::access().
        //
        // @see https://drupal.org/node/2128791
        // @see https://drupal.org/node/2148353
        // @see https://drupal.org/node/2078473
        switch (file_uri_scheme($entity->getFileUri())) {
          case 'public':
            return TRUE;

          case 'private':
          case 'temporary':
            $headers = \Drupal::moduleHandler()->invokeAll('file_download', array($uri));
            foreach ($headers as $result) {
              if ($result == -1) {
                return FALSE;
              }
            }

            if (count($headers)) {
              return TRUE;
            }
            break;
        }

      default:
        return $entity->access('view', $account);
    }
  }

  /**
   * Returns the entity manager.
   *
   * @return \Drupal\Core\Entity\EntityManagerInterface
   *   The entity manager.
   */
  protected function entityManager() {
    if (!isset($this->entityManager)) {
      $this->entityManager = \Drupal::service('entity.manager');
    }
    return $this->entityManager;
  }

  /**
   * Sets the entity manager service.
   *
   * @param \Drupal\Core\Entity\EntityManagerInterface $entityManager
   *   The entity manager service.
   *
   * @return self
   */
  public function setEntityManager(EntityManagerInterface $entityManager) {
    $this->entityManager = $entityManager;
    return $this;
  }
}