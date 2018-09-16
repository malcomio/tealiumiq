<?php

namespace Drupal\tealiumiq\Service;

use Drupal\Component\Serialization\Json;
use Drupal\Core\Config\ConfigFactory;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\tealiumiq\Event\AlterUdoPropertiesEvent;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Class Tealiumiq.
 *
 * @package Drupal\tealiumiq\Service
 */
class Tealiumiq {

  /**
   * Config.
   *
   * @var \Drupal\Core\Config\Config
   */
  protected $config;

  /**
   * UDO.
   *
   * @var \Drupal\tealiumiq\Service\Udo
   */
  public $udo;

  /**
   * Tag Plugin Manager.
   *
   * @var \Drupal\tealiumiq\Service\TagPluginManager
   */
  protected $tagPluginManager;

  /**
   * Token Service.
   *
   * @var \Drupal\tealiumiq\Service\TealiumiqToken
   */
  private $tokenService;

  /**
   * Request Stack.
   *
   * @var \Symfony\Component\HttpFoundation\RequestStack
   */
  private $requestStack;

  /**
   * Language Manager.
   *
   * @var \Drupal\Core\Language\LanguageManagerInterface
   */
  private $languageManager;

  /**
   * Group Plugin Manager.
   *
   * @var \Drupal\tealiumiq\Service\GroupPluginManager
   */
  private $groupPluginManager;

  /**
   * LoggerChannelFactoryInterface.
   *
   * @var \Drupal\Core\Logger\LoggerChannelFactoryInterface
   */
  private $logger;

  /**
   * Tealium Helper.
   *
   * @var \Drupal\tealiumiq\Service\Helper
   */
  public $helper;

  /**
   * EventDispatcherInterface.
   *
   * @var \Symfony\Component\EventDispatcher\EventDispatcherInterface
   */
  private $eventDispatcher;

  /**
   * Tealiumiq constructor.
   *
   * @param \Drupal\Core\Config\ConfigFactory $config
   *   Config Factory.
   * @param \Drupal\tealiumiq\Service\Udo $udo
   *   UDO Service.
   * @param \Drupal\tealiumiq\Service\TealiumiqToken $token
   *   Tealiumiq Token.
   * @param \Drupal\tealiumiq\Service\GroupPluginManager $groupPluginManager
   *   Group Plugin Manager.
   * @param \Drupal\tealiumiq\Service\TagPluginManager $tagPluginManager
   *   Tealiumiq Tag Plugin Manager.
   * @param \Symfony\Component\HttpFoundation\RequestStack $requestStack
   *   Request Stack.
   * @param \Drupal\Core\Language\LanguageManagerInterface $languageManager
   *   Language Manager Interface.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $channelFactory
   *   Logger Channel Factory Interface.
   * @param \Drupal\tealiumiq\Service\Helper $helper
   *   Tealium Helper.
   * @param \Symfony\Component\EventDispatcher\EventDispatcherInterface $eventDispatcher
   *   EventDispatcherInterface.
   */
  public function __construct(ConfigFactory $config,
                              Udo $udo,
                              TealiumiqToken $token,
                              GroupPluginManager $groupPluginManager,
                              TagPluginManager $tagPluginManager,
                              RequestStack $requestStack,
                              LanguageManagerInterface $languageManager,
                              LoggerChannelFactoryInterface $channelFactory,
                              Helper $helper,
                              EventDispatcherInterface $eventDispatcher) {
    // Get Tealium iQ Settings.
    $this->config = $config->get('tealiumiq.settings');

    // Tealium iQ Settings.
    $this->account = $this->config->get('account');
    $this->profile = $this->config->get('profile');
    $this->environment = $this->config->get('environment');
    $this->async = $this->config->get('async');

    $this->udo = $udo;
    $this->tagPluginManager = $tagPluginManager;
    $this->tokenService = $token;
    $this->requestStack = $requestStack;
    $this->languageManager = $languageManager;
    $this->groupPluginManager = $groupPluginManager;
    $this->logger = $channelFactory->get('tealiumiq');
    $this->helper = $helper;
    $this->eventDispatcher = $eventDispatcher;
  }

  /**
   * Get Account Value.
   *
   * @return string
   *   Account value.
   */
  public function getAccount() {
    return $this->account;
  }

  /**
   * Get profile Value.
   *
   * @return string
   *   profile value.
   */
  public function getProfile() {
    return $this->profile;
  }

  /**
   * Get environment Value.
   *
   * @return string
   *   environment value.
   */
  public function getEnvironment() {
    return $this->environment;
  }

  /**
   * Get UTAG embed URL.
   *
   * @return string
   *   UTAG embed value.
   */
  public function getUtagUrl() {
    $url = "//tags.tiqcdn.com/utag/" .
      $this->account .
      '/' . $this->profile .
      '/' . $this->environment .
      '/utag.js';

    return $url;
  }

  /**
   * Get async Value.
   *
   * @return string
   *   async value.
   */
  public function getAsync() {
    return $this->async;
  }

  /**
   * Gets all data values.
   *
   * @return array
   *   All variables.
   */
  public function getProperties() {
    return $this->udo->getProperties();
  }

  /**
   * Export the UDO as JSON.
   *
   * @return string
   *   Json encoded output.
   */
  public function getPropertiesJson() {
    return Json::encode($this->getProperties());
  }

  /**
   * Set all data values.
   */
  public function setUdoPropertiesFromRoute() {
    // Get the tags from Route.
    // Set the tags in UDO.
    $this->setProperties($this->helper->tagsFromRoute());
  }

  /**
   * Set Tealium Tags.
   *
   * @param array $properties
   *   Tags array.
   */
  public function setProperties(array $properties = []) {
    // Allow other modules to property variables before we send it.
    $alterUDOPropertiesEvent = new AlterUdoPropertiesEvent(
      $this->udo->getNamespace(),
      $properties
    );

    $event = $this->eventDispatcher->dispatch(
      AlterUdoPropertiesEvent::UDO_ALTER_PROPERTIES,
      $alterUDOPropertiesEvent
    );

    // Merge existing and altered properties.
    $tealiumiqTags = array_merge($properties, $event->getProperties());

    // Process tokens.
    $entity = $this->helper->getEnityFromRoute();
    if (!empty($entity) && $entity instanceof ContentEntityInterface) {
      if ($entity->id() && !empty($tealiumiqTags)) {
        $tealiumiqTagsTokenised = $this->helper->generateRawElements($tealiumiqTags, $entity);
      }
    }
    else {
      $tealiumiqTagsTokenised = $this->helper->generateRawElements($tealiumiqTags);
    }

    // Cleanup the tags to key value.
    $tealiumiqTagsTokenised = $this->helper->tokenisedTags($tealiumiqTagsTokenised);

    // Set the tags in UDO.
    $this->udo->setProperties($tealiumiqTagsTokenised);
  }

  /**
   * {@inheritdoc}
   */
  public function form(array $values,
                       array $element,
                       array $tokenTypes = [],
                       array $includedGroups = NULL,
                       array $includedTags = NULL) {
    // Add the outer fieldset.
    $element += [
      '#type' => 'details',
    ];

    $element += $this->tokenService->tokenBrowser($tokenTypes);

    $groupsAndTags = $this->helper->sortedGroupsWithTags();

    foreach ($groupsAndTags as $groupName => $group) {
      // Only act on groups that have tags and are in the list of included
      // groups (unless that list is null).
      if (isset($group['tags']) && (is_null($includedGroups) ||
          in_array($groupName, $includedGroups) ||
          in_array($group['id'], $includedGroups))) {
        // Create the fieldset.
        $element[$groupName]['#type'] = 'details';
        $element[$groupName]['#title'] = $group['label'];
        $element[$groupName]['#description'] = $group['description'];
        $element[$groupName]['#open'] = TRUE;

        foreach ($group['tags'] as $tagName => $tag) {
          // Only act on tags in the included tags list, unless that is null.
          if (is_null($includedTags) ||
              in_array($tagName, $includedTags) ||
              in_array($tag['id'], $includedTags)) {
            // Make an instance of the tag.
            $tag = $this->tagPluginManager->createInstance($tagName);

            // Set the value to the stored value, if any.
            $tag_value = isset($values[$tagName]) ? $values[$tagName] : NULL;
            $tag->setValue($tag_value);

            // Open any groups that have non-empty values.
            if (!empty($tag_value)) {
              $element[$groupName]['#open'] = TRUE;
            }

            // Create the bit of form for this tag.
            $element[$groupName][$tagName] = $tag->form($element);
          }
        }
      }
    }

    return $element;
  }

}
