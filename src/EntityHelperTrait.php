<?php

/**
 * @file
 * Contains Drupal\entity_embed\EntityHelperTrait.
 */

namespace Drupal\entity_embed;

use Drupal\Component\Utility\SafeMarkup;
use Drupal\Core\Access\AccessResult;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityManagerInterface;
use Drupal\Core\Entity\EntityStorageException;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\entity_embed\EntityEmbedDisplay\EntityEmbedDisplayManager;

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
   * The module handler service.
   *
   * @var \Drupal\Core\Extension\ModuleHandlerInterface.
   */
  protected $moduleHandler;

  /**
   * The display plugin manager.
   *
   * @var \Drupal\entity_embed\EntityEmbedDisplay\EntityEmbedDisplayManager.
   */
  protected $displayPluginManager;

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
   *   Throws an exception if the entity type does not supports UUIDs.
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
    return $this->canRenderEntityType($entity_type);
  }

  /**
   * Determines if an entity type can be rendered.
   *
   * @param string $entity_type
   *   The entity type id.
   *
   * @return bool
   *   TRUE if the entitys type has a view builder controller, otherwise FALSE.
   */
  protected function canRenderEntityType($entity_type) {
    return $this->entityManager()->hasHandler($entity_type, 'view_builder');
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
   * Renders an entity using an EntityEmbedDisplay plugin.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity to be rendered.
   * @param string $plugin_id
   *   The EntityEmbedDisplay plugin ID.
   * @param array $plugin_configuration
   *   (optional) Array of plugin configuration values.
   * @param array $context
   *   (optional) Array of additional context values, usually the embed HTML
   *   tag's attributes.
   *
   * @return string
   *   The HTML of the entity rendered with the display plugin.
   *
   * @throws \Drupal\entity_embed\RecursiveRenderingException;
   *   Throws an exception when the post_render_cache callback goes into a
   *   potentially infinite loop.
   */
  protected function renderEntityEmbedDisplayPlugin(EntityInterface $entity, $plugin_id, array $plugin_configuration = array(), array $context = array()) {
    // Protect ourselves from recursive rendering.
    static $depth = 0;
    $depth++;
    if ($depth > 20) {
      throw new RecursiveRenderingException(SafeMarkup::format('Recursive rendering detected when rendering entity @entity_type(@entity_id). Aborting rendering.', array('@entity_type' => $entity->getEntityTypeId(), '@entity_id' => $entity->id())));
    }

    // Allow modules to alter the entity prior to display rendering.
    $this->moduleHandler()->invokeAll('entity_preembed', array($entity, $context));

    // Build the display plugin.
    $display = $this->displayPluginManager()->createInstance($plugin_id, $plugin_configuration);
    $display->setContextValue('entity', $entity);
    $display->setAttributes($context);

    // Check if the display plugin is accessible. This also checks entity
    // access, which is why we never call $entity->access() here.
    if (!$display->access()) {
      return '';
    }

    // Build and render the display plugin, allowing modules to alter the
    // result before rendering.
    $build = $display->build();
    $this->moduleHandler()->alter('entity_embed', $build, $display);
    $entity_output = \Drupal::service('renderer')->render($build);

    $depth--;
    return $entity_output;
  }

  /**
   * Check access to an entity.
   *
   * @todo Remove when https://www.drupal.org/node/2533978 is fixed in core.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity object.
   * @param string $op
   *   (optional) The operation to be performed. Defaults to view.
   * @param \Drupal\Core\Session\AccountInterface $account
   *   (optional) The user for which to check access, or NULL to check access
   *   for the current user. Defaults to NULL.
   * @param bool $return_as_object
   *   (optional) Defaults to FALSE.
   *
   * @return bool|\Drupal\Core\Access\AccessResultInterface
   *   The access result. Returns a boolean if $return_as_object is FALSE (this
   *   is the default) and otherwise an AccessResultInterface object.
   *   When a boolean is returned, the result of AccessInterface::isAllowed() is
   *   returned, i.e. TRUE means access is explicitly allowed, FALSE means
   *   access is either explicitly forbidden or "no opinion".
   */
  protected function accessEntity(EntityInterface $entity, $op = 'view', AccountInterface $account = NULL, $return_as_object = FALSE) {
    if ($entity->getEntityTypeId() === 'file') {
      /** @var \Drupal\file\Entity\File $entity */
      $uri = $entity->getFileUri();
      if (\Drupal::service('file_system')->uriScheme($uri) === 'public') {
        return $return_as_object ? AccessResult::allowed() : TRUE;
      }
    }

    return $entity->access($op, $account, $return_as_object);
  }

  /**
   * Returns the entity manager.
   *
   * @return \Drupal\Core\Entity\EntityManagerInterface
   *   The entity manager.
   */
  protected function entityManager() {
    if (!isset($this->entityManager)) {
      $this->entityManager = \Drupal::entityManager();
    }
    return $this->entityManager;
  }

  /**
   * Sets the entity manager service.
   *
   * @param \Drupal\Core\Entity\EntityManagerInterface $entity_manager
   *   The entity manager service.
   *
   * @return self
   */
  public function setEntityManager(EntityManagerInterface $entity_manager) {
    $this->entityManager = $entity_manager;
    return $this;
  }

  /**
   * Returns the module handler.
   *
   * @return \Drupal\Core\Extension\ModuleHandlerInterface
   *   The module handler.
   */
  protected function moduleHandler() {
    if (!isset($this->moduleHandler)) {
      $this->moduleHandler = \Drupal::moduleHandler();
    }
    return $this->moduleHandler;
  }

  /**
   * Sets the module handler service.
   *
   * @param \Drupal\Core\Extension\ModuleHandlerInterface $module_handler
   *   The module handler service.
   *
   * @return self
   */
  public function setModuleHandler(ModuleHandlerInterface $module_handler) {
    $this->moduleHandler = $module_handler;
    return $this;
  }

  /**
   * Returns the display plugin manager.
   *
   * @return \Drupal\entity_embed\EntityEmbedDisplay\EntityEmbedDisplayManager
   *   The display plugin manager.
   */
  protected function displayPluginManager() {
    if (!isset($this->displayPluginManager)) {
      $this->displayPluginManager = \Drupal::service('plugin.manager.entity_embed.display');
    }
    return $this->displayPluginManager;
  }

  /**
   * Sets the display plugin manager service.
   *
   * @param \Drupal\entity_embed\EntityEmbedDisplay\EntityEmbedDisplayManager $display_plugin_manager
   *   The display plugin manager service.
   *
   * @return self
   */
  public function setDisplayPluginManager(EntityEmbedDisplayManager $display_plugin_manager) {
    $this->displayPluginManager = $display_plugin_manager;
    return $this;
  }
}
