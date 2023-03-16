<?php

namespace Drupal\drupal_utilities\Element;

use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Url;
use Drupal\filter\Element\ProcessedText;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides a processed text render element.
 *
 * Hyperlink automatically terms from the field description to other terms from
 * the entity type.
 *
 * @RenderElement("scan_text")
 */
class ScanText extends ProcessedText implements ContainerFactoryPluginInterface {

  /**
   * The database connection.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected $connection;

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The entity storage.
   *
   * @var \Drupal\Core\Entity\EntityStorageInterface
   */
  protected $entityStorage;

  /**
   * Constructs a ScanText element.
   *
   * @param array $configuration
   *   A configuration array containing information about the plugin instance.
   * @param string $plugin_id
   *   The plugin ID for the plugin instance.
   * @param mixed $plugin_definition
   *   The plugin implementation definition.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   * @param \Drupal\Core\Database\Connection $connection
   *   The database connection.
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, EntityTypeManagerInterface $entityTypeManager, Connection $connection) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->entityTypeManager = $entityTypeManager;
    $this->connection = $connection;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('entity_type.manager'),
      $container->get('database'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getInfo() {
    return [
      '#text' => '',
      '#format' => NULL,
      '#filter_types_to_skip' => [],
      '#langcode' => '',
      '#pre_render' => [
        [$this, 'preRenderScanText'],
      ],
    ];
  }

  /**
   * {@inheritdoc}
   *
   * @SuppressWarnings(PHPMD.ShortVariable)
   */
  public function preRenderScanText($element) {
    $element = parent::preRenderText($element);

    $entity = $element['#entity'];
    $entityTypeId = $entity->getEntityTypeId();
    $this->setEntityStorage($entityTypeId);
    $results = $this->getAllRelatedEntities($entity);
    $names = array_values($results);
    $names = array_map(function ($name) use ($names) {
      return str_replace('/', '\/', $name);
    }, $names);
    foreach ($names as $name) {
      if (($this->allowLowerMatch($name))) {
        $names[] = lcfirst($name);
      }
    }
    $pattern = '/\b(' . implode(')\b|\b(', $names) . ')\b/';
    preg_match_all($pattern, $element['#text'], $matches);
    if (empty($matches[0])) {
      return $element;
    }
    $matches = $matches[0];
    $matches = array_unique($matches);
    foreach ($matches as $match) {
      $name = ($this->allowLowerMatch($match)) ? ucfirst($match) : $match;
      $id = array_search($name, $results);
      if (!is_int($id)) {
        continue;
      }
      $url = Url::fromRoute("entity.{$entityTypeId}.canonical", [$entityTypeId => $id])->toString();
      $replace = sprintf(" <a href='%s'>%s</a>", $url, $match);
      $element['#markup'] = preg_replace('/\s\b(' . $match . ')\b/', $replace, $element['#markup']);
    }
    return $element;
  }

  /**
   * Check if the first character of a name can be lowercase.
   *
   * For example: Meeting & meeting.
   *
   * @param string $name
   *   A name.
   *
   * @return bool
   *   TRUE if the current name can be found with first character lowercase,
   *   and FALSE if not.
   */
  protected function allowLowerMatch(string $name) {
    preg_match('/(([A-Z]*)(\W*)(\d*))/', $name, $matches);
    // For abbreviations like Add., Corr. or names with less than 4 characters.
    if (substr($name, -1) == '.' || strlen($name) < 4) {
      return FALSE;
    }
    // For abbreviations like WTO or PFCs.
    $numberWords = str_word_count($name);
    if (strlen($name) - strlen($matches[0]) < 2 && $numberWords == 1) {
      return FALSE;
    }
    // For names in more words (e.g. "Global Biodiversity Forum") allows only
    // the exact mach.
    if ($numberWords != 1) {
      return FALSE;
    }
    // For names with special characters (e.g. UN-Habitat)
    if ($matches[3]) {
      return FALSE;
    }
    return TRUE;
  }

  /**
   * Get all relatives entities.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $entity
   *   The entity object.
   *
   * @return array
   *   An array with results where the key is the id of the entity and the value
   *   represents the name.
   */
  protected function getAllRelatedEntities(ContentEntityInterface $entity) {
    $bundle = $entity->bundle();
    $entityTypeId = $entity->getEntityTypeId();

    $query = $this->connection->select($entityTypeId . '_field_data', 'd');
    $query->addField('d', $this->getKey('label'), 'name');
    $query->addField('d', $this->getKey('id'), 'id');
    $query->condition($this->getKey('bundle'), $bundle);
    $query->condition('status', 1);
    $query->condition($this->getKey('id'), $entity->id(), 'NOT IN');
    $query->orderBy($this->getKey('label'));
    $results = $query->execute()->fetchAll();
    return array_combine(array_column($results, 'id'), array_column($results, 'name'));
  }

  /**
   * {@inheritdoc}
   */
  protected function setEntityStorage($entityTypeId) {
    $this->entityStorage = $this->entityTypeManager->getStorage($entityTypeId);
  }

  /**
   * Gets a specific entity key.
   *
   * @param string $key
   *   The name of the entity key to return.
   *
   * @return string|false
   *   The entity key, or FALSE if it does not exist.
   *
   * @see self::getKeys()
   */
  protected function getKey($key) {
    return $this->entityStorage->getEntityType()->getKey($key);
  }

}
