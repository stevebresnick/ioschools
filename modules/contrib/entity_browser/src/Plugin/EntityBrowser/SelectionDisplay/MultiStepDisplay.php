<?php

namespace Drupal\entity_browser\Plugin\EntityBrowser\SelectionDisplay;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\entity_browser\FieldWidgetDisplayManager;
use Drupal\entity_browser\SelectionDisplayBase;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

/**
 * Show current selection and delivers selected entities.
 *
 * @EntityBrowserSelectionDisplay(
 *   id = "multi_step_display",
 *   label = @Translation("Multi step selection display"),
 *   description = @Translation("Show current selection display and delivers selected entities."),
 *   acceptPreselection = TRUE
 * )
 */
class MultiStepDisplay extends SelectionDisplayBase {

  /**
   * Field widget display plugin manager.
   *
   * @var \Drupal\entity_browser\FieldWidgetDisplayManager
   */
  protected $fieldDisplayManager;

  /**
   * Constructs widget plugin.
   *
   * @param array $configuration
   *   A configuration array containing information about the plugin instance.
   * @param string $plugin_id
   *   The plugin_id for the plugin instance.
   * @param mixed $plugin_definition
   *   The plugin implementation definition.
   * @param \Symfony\Component\EventDispatcher\EventDispatcherInterface $event_dispatcher
   *   Event dispatcher service.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager service.
   * @param \Drupal\entity_browser\FieldWidgetDisplayManager $field_display_manager
   *   Field widget display plugin manager.
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, EventDispatcherInterface $event_dispatcher, EntityTypeManagerInterface $entity_type_manager, FieldWidgetDisplayManager $field_display_manager) {
    parent::__construct($configuration, $plugin_id, $plugin_definition, $event_dispatcher, $entity_type_manager);
    $this->fieldDisplayManager = $field_display_manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('event_dispatcher'),
      $container->get('entity_type.manager'),
      $container->get('plugin.manager.entity_browser.field_widget_display')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration() {
    return [
      'entity_type' => 'node',
      'display' => 'label',
      'display_settings' => [],
      'select_text' => 'Use selected',
      'selection_hidden' => 0,
    ] + parent::defaultConfiguration();
  }

  /**
   * {@inheritdoc}
   */
  public function getForm(array &$original_form, FormStateInterface $form_state) {
    $selected_entities = $form_state->get(['entity_browser', 'selected_entities']);

    $form = [];
    $form['#attached']['library'][] = 'entity_browser/multi_step_display';
    $form['selected'] = [
      '#theme_wrappers' => ['container'],
      '#attributes' => ['class' => ['entities-list']],
      '#tree' => TRUE,
    ];
    if ($this->configuration['selection_hidden']) {
      $form['selected']['#attributes']['class'][] = 'hidden';
    }
    foreach ($selected_entities as $id => $entity) {
      $display_plugin = $this->fieldDisplayManager->createInstance(
        $this->configuration['display'],
        $this->configuration['display_settings'] + ['entity_type' => $this->configuration['entity_type']]
      );
      $display = $display_plugin->view($entity);
      if (is_string($display)) {
        $display = ['#markup' => $display];
      }

      $form['selected']['items_' . $entity->id() . '_' . $id] = [
        '#theme_wrappers' => ['container'],
        '#attributes' => [
          'class' => ['item-container'],
          'data-entity-id' => $entity->id(),
        ],
        'display' => $display,
        'remove_button' => [
          '#type' => 'submit',
          '#value' => $this->t('Remove'),
          '#submit' => [[get_class($this), 'removeItemSubmit']],
          '#name' => 'remove_' . $entity->id() . '_' . $id,
          '#attributes' => [
            'data-row-id' => $id,
            'data-remove-entity' => 'items_' . $entity->id(),
          ],
        ],
        'weight' => [
          '#type' => 'hidden',
          '#default_value' => $id,
          '#attributes' => ['class' => ['weight']],
        ],
      ];
    }
    $form['use_selected'] = [
      '#type' => 'submit',
      '#value' => $this->t($this->configuration['select_text']),
      '#name' => 'use_selected',
      '#access' => empty($selected_entities) ? FALSE : TRUE,
    ];
    $form['show_selection'] = [
      '#type' => 'button',
      '#value' => $this->t('Show selected'),
      '#attributes' => [
        'class' => ['entity-browser-show-selection'],
      ],
      '#access' => empty($selected_entities) ? FALSE : TRUE,
    ];

    return $form;
  }

  /**
   * Submit callback for remove buttons.
   *
   * @param array $form
   *   Form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   Form state.
   */
  public static function removeItemSubmit(array &$form, FormStateInterface $form_state) {
    $triggering_element = $form_state->getTriggeringElement();

    // Remove weight of entity being removed.
    $form_state->unsetValue([
      'selected',
      $triggering_element['#attributes']['data-remove-entity'] . '_' . $triggering_element['#attributes']['data-row-id'],
    ]);

    // Remove entity itself.
    $selected_entities = &$form_state->get(['entity_browser', 'selected_entities']);
    unset($selected_entities[$triggering_element['#attributes']['data-row-id']]);

    static::saveNewOrder($form_state);
    $form_state->setRebuild();
  }

  /**
   * {@inheritdoc}
   */
  public function submit(array &$form, FormStateInterface $form_state) {
    $this->saveNewOrder($form_state);
    if ($form_state->getTriggeringElement()['#name'] == 'use_selected') {
      $this->selectionDone($form_state);
    }
  }

  /**
   * Saves new ordering of entities based on weight.
   *
   * @param FormStateInterface $form_state
   *   Form state.
   */
  public static function saveNewOrder(FormStateInterface $form_state) {
    $selected = $form_state->getValue('selected');
    if (!empty($selected)) {
      $weights = array_column($selected, 'weight');
      $selected_entities = $form_state->get(['entity_browser', 'selected_entities']);

      // If we added new entities to the selection at this step we won't have
      // weights for them so we have to fake them.
      $diff_selected_size = count($selected_entities) - count($weights);
      if ($diff_selected_size > 0) {
        $max_weight = (max($weights) + 1);
        for ($new_weight = $max_weight; $new_weight < ($max_weight + $diff_selected_size); $new_weight++) {
          $weights[] = $new_weight;
        }
      }

      $ordered = array_combine($weights, $selected_entities);
      ksort($ordered);
      $form_state->set(['entity_browser', 'selected_entities'], $ordered);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state) {
    $default_entity_type = $form_state->getValue('entity_type', $this->configuration['entity_type']);
    $default_display = $form_state->getValue('display', $this->configuration['display']);
    $default_display_settings = $form_state->getValue('display_settings', $this->configuration['display_settings']);
    $default_display_settings += ['entity_type' => $default_entity_type];

    if ($form_state->isRebuilding()) {
      $form['#prefix'] = '<div id="multi-step-form-wrapper">';
    } else {
      $form['#prefix'] .= '<div id="multi-step-form-wrapper">';
    }
    $form['#suffix'] = '</div>';

    $entity_types = [];
    foreach ($this->entityTypeManager->getDefinitions() as $entity_type_id => $entity_type) {
      /** @var \Drupal\Core\Entity\EntityTypeInterface $entity_type */
      $entity_types[$entity_type_id] = $entity_type->getLabel();
    }
    $form['entity_type'] = [
      '#type' => 'select',
      '#title' => $this->t('Entity type'),
      '#description' => $this->t("Entity browser itself does not need information about entity type being selected. It can actually select entities of different type. However, some of the display plugins need to know which entity type they are operating with. Display plugins that do not need this info will ignore this configuration value."),
      '#default_value' => $default_entity_type,
      '#options' => $entity_types,
      '#ajax' => [
        'callback' => [$this, 'updateSettingsAjax'],
        'wrapper' => 'multi-step-form-wrapper',
      ],
    ];

    $displays = [];
    foreach ($this->fieldDisplayManager->getDefinitions() as $display_plugin_id => $definition) {
      $entity_type = $this->entityTypeManager->getDefinition($default_entity_type);
      if ($this->fieldDisplayManager->createInstance($display_plugin_id)->isApplicable($entity_type)) {
        $displays[$display_plugin_id] = $definition['label'];
      }
    }
    $form['display'] = [
      '#title' => $this->t('Entity display plugin'),
      '#type' => 'select',
      '#default_value' => $default_display,
      '#options' => $displays,
      '#ajax' => [
        'callback' => [$this, 'updateSettingsAjax'],
        'wrapper' => 'multi-step-form-wrapper',
      ],
    ];

    $form['display_settings'] = [
      '#type' => 'container',
      '#title' => $this->t('Entity display plugin configuration'),
      '#tree' => TRUE,
    ];
    if ($default_display_settings) {
      $display_plugin = $this->fieldDisplayManager
        ->createInstance($default_display, $default_display_settings);

      $form['display_settings'] += $display_plugin->settingsForm($form, $form_state);
    }
    $form['select_text'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Select button text'),
      '#default_value' => $this->configuration['select_text'],
      '#description' => $this->t('Text to display on the entity browser select button.'),
    ];

    $form['selection_hidden'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Selection hidden by default'),
      '#default_value' => $this->configuration['selection_hidden'],
      '#description' => $this->t('Whether or not the selection should be hidden by default.'),
    ];

    return $form;
  }

  /**
   * Ajax callback that updates multi-step plugin configuration form on AJAX updates.
   */
  public function updateSettingsAjax(array $form, FormStateInterface $form_state) {
    return $form;
  }

}
