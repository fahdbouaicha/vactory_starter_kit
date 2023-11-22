<?php

namespace Drupal\vactory_content_package\Services;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\Routing\TrustedRedirectResponse;
use Drupal\Core\Url;
use Drupal\node\Entity\Node;

/**
 * Content package import manager service.
 */
class ContentPackageImportManager implements ContentPackageImportManagerInterface {

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The messenger service.
   *
   * @var \Drupal\Core\Messenger\MessengerInterface
   */
  protected $messenger;

  /**
   * Batch size.
   */
  const BATCH_SIZE = 15;

  /**
   * Vactory Content Package Service constructor.
   */
  public function __construct(MessengerInterface $messenger, EntityTypeManagerInterface $entityTypeManager) {
    $this->messenger = $messenger;
    $this->entityTypeManager = $entityTypeManager;
  }

  /**
   * Delete all nodes of given content types.
   */
  public function rollback(array $content_types, string $file_to_import = '') {

    $nodes = $this->entityTypeManager->getStorage('node')
      ->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', $content_types, 'IN')
      ->execute();
    $nodes = array_values($nodes);

    if (empty($nodes)) {
      return [];
    }

    $chunk = array_chunk($nodes, self::BATCH_SIZE);
    $operations = [];
    $num_operations = 0;
    foreach ($chunk as $ids) {
      $operations[] = [
        [self::class, 'rollbackCallback'],
        [$ids, $file_to_import],
      ];
      $num_operations++;
    }

    if (!empty($operations)) {
      $batch = [
        'title' => 'Process of deleting nodes',
        'operations' => $operations,
        'finished' => [self::class, 'rollbackFinished'],
      ];
      batch_set($batch);
    }

  }

  /**
   * Rollback batch callback.
   */
  public static function rollbackCallback($nids, $file_to_import, &$context) {
    $entityFieldManager = \Drupal::service('entity_field.manager');
    $storage = \Drupal::entityTypeManager()->getStorage('node');
    $paragraphStroage = \Drupal::entityTypeManager()->getStorage('paragraph');
    $nodes = $storage->loadMultiple($nids);
    $skipped_nodes = 0;
    foreach ($nodes as $node) {
      $entity_values = $node->toArray();

      // Skip if node_content_package_exclude is checked.
      if ($node->hasField('node_content_package_exclude') && $node->get('node_content_package_exclude')->value == 1) {
        $skipped_nodes++;
        continue;
      }

      $entity_values = array_diff_key($entity_values, array_flip(ContentPackageManagerInterface::UNWANTED_KEYS));
      $fields = $entityFieldManager->getFieldDefinitions('node', $node->bundle());
      foreach ($entity_values as $field_name => &$field_value) {
        $field_definition = $fields[$field_name] ?? NULL;
        if ($field_definition) {
          $field_type = $field_definition->getType();
          $cardinality = $field_definition->getFieldStorageDefinition()
            ->getCardinality();
          $is_multiple = $cardinality > 1 || $cardinality <= -1;
          $field_settings = $field_definition->getSettings();

          if ($field_type === 'entity_reference_revisions' && isset($field_settings['target_type']) && $field_settings['target_type'] === 'paragraph') {
            $field_value = empty($field_value) ? [] : $field_value;
            if (!empty($field_value)) {
              if (!$is_multiple) {
                $target_id = $field_value[0]['target_id'];
                $paragraph = $paragraphStroage->load($target_id);
                if (isset($paragraph)) {
                  $paragraph->delete();
                }
              }
              else {
                $target_ids = array_map(fn($value) => $value['target_id'], $field_value);
                foreach ($target_ids as $pid) {
                  $paragraph = $paragraphStroage->load($pid);
                  if (isset($paragraph)) {
                    $paragraph->delete();
                  }
                }
              }
            }
            break;
          }
        }
      }
      $node->delete();
    }

    if (!isset($context['results']['count'])) {
      $context['results']['count'] = 0;
    }
    $context['results']['count'] += (count($nodes) - $skipped_nodes);
    $context['results']['file_to_import'] = $file_to_import;
  }

  /**
   * Rollback batch finished.
   */
  public static function rollbackFinished($success, $results, $operations) {
    if ($success) {
      $message = "Deleting finished: {$results['count']} nodes.";
      \Drupal::messenger()->addStatus($message);
      $url = Url::fromRoute('vactory_content_package.importing_exported_nodes')
        ->setRouteParameters([
          'url' => $results['file_to_import'],
        ]);

      $redirect_response = new TrustedRedirectResponse($url->toString(TRUE)
        ->getGeneratedUrl());
      $redirect_response->send();
      return $redirect_response;
    }
  }

  /**
   * Import nodes.
   */
  public function importNodes(string $file_to_import) {
    if (!file_exists($file_to_import)) {

    }

    $json_contents = file_get_contents($file_to_import);
    $json_data = json_decode($json_contents, TRUE);

    $chunk = array_chunk($json_data, self::BATCH_SIZE);
    $operations = [];
    $num_operations = 0;
    foreach ($chunk as $nodes) {
      $operations[] = [
        [self::class, 'importingCallback'],
        [$nodes, $file_to_import],
      ];
      $num_operations++;
    }

    if (!empty($operations)) {
      $batch = [
        'title' => 'Process of importing nodes',
        'operations' => $operations,
        'finished' => [self::class, 'importingFinished'],
      ];
      batch_set($batch);
    }
  }

  /**
   * Importing batch callback.
   */
  public static function importingCallback($nodes, $file_to_import, &$context) {

    $logger = \Drupal::logger('vactory_content_package');

    foreach ($nodes as $key => $value) {
      $node = NULL;
      if (isset($value['original'])) {
        try {
          $node = Node::create($value['original']);
          $node->enforceIsNew();
          $node->save();

          if (isset($node) && isset($value['translations'])) {
            foreach ($value['translations'] as $lang => $trans) {
              try {
                $node->addTranslation($lang, $trans)
                  ->save();
              } catch (\Exception $exception) {
                $logger->error(t('Enable to attach translation %lang to node %label, error message %error', [
                  '%lang' => $lang,
                  '%label' => $key,
                  '%error' => $exception->getMessage(),
                ]));
              }
            }
          }

        } catch (\Exception $exception) {
          $logger->error(t('Enable to create node %label, error message %error', [
            '%label' => $key,
            '%error' => $exception->getMessage(),
          ]));
        }

      }
    }

    if (!isset($context['results']['count'])) {
      $context['results']['count'] = 0;
    }
    $context['results']['count'] += count($nodes);
    $context['results']['file_to_import'] = $file_to_import;
  }

  /**
   * Importing batch finished.
   */
  public static function importingFinished($success, $results, $operations) {
    if ($success) {
      $message = "Importing process finished: {$results['count']} nodes.";
      \Drupal::messenger()->addStatus($message);
      if (file_exists($results['file_to_import'])) {
        unlink($results['file_to_import']);
      }

      $url = Url::fromRoute('vactory_content_package.import');

      $redirect_response = new TrustedRedirectResponse($url->toString(TRUE)
        ->getGeneratedUrl());
      $redirect_response->send();
      return $redirect_response;
    }
  }

}
