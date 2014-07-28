<?php

/**
 * @file
 * Contains \Drupal\elasticsearch\Form\IndexForm.
 */

namespace Drupal\elasticsearch\Form;

use Drupal\Core\Entity\EntityForm;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Component\Utility\String;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Url;
use Drupal\Core\Config\Entity\ConfigEntityBase;
use Drupal\Core\Entity\Entity;
use Drupal\Core\Entity\EntityManager;
use Drupal\elasticsearch\Entity\Cluster;
use Elasticsearch\Common\Exceptions\Curl\CouldNotResolveHostException;

/**
 * Form controller for node type forms.
 */
class IndexForm extends EntityForm {


  protected $entityManager;

  public function __construct(EntityManager $entity_manager) {
    // Setup object members.
    $this->entityManager = $entity_manager;
  }

  protected function getEntityManager() {
    return $this->entityManager;
  }

  protected function getClusterStorage() {
    return $this->getEntityManager()->getStorage('elasticsearch_cluster');
  }

  protected function getAllClusters() {
    $options = array();
    foreach ($this->getClusterStorage()->loadMultiple() as $cluster_machine_name) {
      $options[$cluster_machine_name->cluster_id] = $cluster_machine_name;
    }
    return $options;
  }

  protected function getClusterField($field) {
    $clusters = $this->getAllClusters();
    $options = array();
    foreach ($clusters as $cluster) {
      $options[$cluster->$field] = $cluster->$field;
    }
    return $options;
  }

  protected function getSelectedClusterUrl($id) {
    $clusters = $this->getAllClusters();
    foreach ($clusters as $cluster) {
      if ($cluster->cluster_id == $id) {
        $result = $cluster->url;
      }
    }
    return $result;
  }

  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('entity.manager')
    );
  }


  public function form(array $form, array &$form_state) {
    $form = parent::form($form, $form_state);

    //$index = $form_state['entity'] = $this->getEntity();

    if ($this->operation == 'edit') {
      $form['#title'] = $this->t('Edit Index @label', array('@label' => $index->label()));
    }
    else {
      $form['#title'] = $this->t('Index');
    } 
    
    $this->buildEntityForm($form, $form_state);
    return $form;
  }

  public function buildEntityForm(array &$form, array &$form_state) {
    $form['name'] = array(
      '#type' => 'textfield',
      '#title' => t('Index name'),
      '#required' => TRUE,
      '#default_value' => '',
      '#description' => t('Enter the index name.')
    );

    $form['index_id'] = array(
      '#type' => 'machine_name',
      '#title' => t('Index id'),
      '#default_value' => !empty($index->index_id) ? $index->index_id : '',
      '#maxlength' => 125,
      '#description' => t('Unique, machine-readable identifier for this Index'),
      '#machine_name' => array(
        'exists' => '\Drupal\elasticsearch\Entity\Index::load',
        'source' => array('name'),
        'replace_pattern' => '[^a-z0-9-]+',
        'replace' => '_',
      ),
      '#required' => TRUE,
      '#disabled' => !empty($index->index_id),
    );

    $form['server'] = array(
      '#type' => 'radios',
      '#title' => $this->t('Server'),
      '#description' => $this->t('Select the server this index should reside on. Index can not be enabled without connection to valid server.'),
      '#options' => $this->getClusterField('cluster_id'),
      '#weight' => 9,
      '#required' => TRUE,
    );

    $form['num_of_shards'] = array(
      '#type' => 'textfield',
      '#title' => t('Number of shards'),
      '#required' => TRUE,
      '#default_value' => '',
      '#description' => t('Enter the number of shards for the index.')
    );

    $form['num_of_replica'] = array(
      '#type' => 'textfield',
      '#title' => t('Number of replica'),
      '#default_value' => '',
      '#description' => t('Enter the number of shards replicas.')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function validate(array $form, array &$form_state) {
    parent::validate($form, $form_state);

    //$index = $this->entity;

    //$index_from_form = entity_create('elasticsearch_cluster_index', $form_state['values']);
    
    if (!preg_match('/^[a-z][a-z0-9_]*$/i', $form_state['values']['name'])) {
      form_set_error('name', t('Enter an index name that begins with a letter and contains only letters, numbers, and underscores.'));
    }

    if (!is_numeric($form_state['values']['num_of_shards']) || $form_state['values']['num_of_shards'] < 1) {
      form_set_error('num_of_shards', t('Invalid number of shards.'));
    }

    if (!is_numeric($form_state['values']['num_of_replica'])) {
      form_set_error('num_of_replica', t('Invalid number of replica.'));
    }
  }

public function submit(array $form, array &$form_state) {
  $values = $form_state['values'];

  //@TODO Temporary. To be removed by calling Cluster::getClusterById()
  $cluster_url = self::getSelectedClusterUrl($form_state['values']['server']);

  $client = Cluster::getClusterByUrls(array($cluster_url));
  if ($client) {
    try {
      $index_params['index'] = $values['name'];
      $index_params['body']['settings']['number_of_shards']   = $values['num_of_shards'];
      $index_params['body']['settings']['number_of_replicas'] = $values['num_of_replica'];
      $index_params['body']['settings']['cluster_machine_name'] = $values['server'];
      $response = $client->indices()->create($index_params);
      if (elasticsearch_check_response_ack($response)) {
        drupal_set_message(t('The index %index has been successfully created.', array('%index' => $values['name'])));
      }
      else {
        drupal_set_message(t('Fail to create the index %index', array('%index' => $values['name'])), 'error');
      }
    }
    catch (Exception $e) {
      drupal_set_message($e->getMessage(), 'error');
    }
  }
  return parent::submit($form, $form_state);
}

  // @TODO
  public function save(array $form, array &$form_state) {
    $index = $this->entity;
    
    $status = $index->save();

    if ($status == SAVED_UPDATED) {
      drupal_set_message(t('Index %label has been updated.', array('%label' => $index->label())));
    }
    else {
      drupal_set_message(t('Index %label has been added.', array('%label' => $index->label())));
    }

    $form_state['redirect_route'] = new Url('elasticsearch.clusters');
  }
}
