<?php

namespace Drupal\company_base_config_import\EventSubscriber;

use Drupal\Core\EventSubscriber\ConfigImportSubscriber;
use Drupal\Core\Config\ConfigImporter;
use Drupal\Core\Extension\ThemeHandlerInterface;
use Drupal\Core\Extension\ProfileHandlerInterface;

/**
 * Overrides ConfigImportSubscriber.
 */
class CompanyBaseConfigImportValidateSubscriber extends ConfigImportSubscriber {

  /**
   * The theme handler.
   *
   * @var \Drupal\Core\Extension\ThemeHandlerInterface
   */
  protected $themeHandler;

  /**
   * The profile handler used to find additional folders to scan for config.
   *
   * @var \Drupal\Core\Extension\ProfileHandlerInterface
   */
  protected $profileHandler;

  /**
   * {@inheritdoc}
   */
  public function __construct(ThemeHandlerInterface $theme_handler, ProfileHandlerInterface $profile_handler) {
    parent::__construct($theme_handler);
    $this->profileHandler = $profile_handler;
  }

  /**
   * Validates module installations and uninstallations.
   *
   * @param \Drupal\Core\Config\ConfigImporter $config_importer
   *   The configuration importer.
   */
  protected function validateModules(ConfigImporter $config_importer) {
    $current_core_extension = $config_importer->getStorageComparer()->getTargetStorage()->read('core.extension');
    $core_extension = $config_importer->getStorageComparer()->getSourceStorage()->read('core.extension');

    // Get a list of modules with dependency weights as values.
    $module_data = $this->getModuleData();
    $nonexistent_modules = array_keys(array_diff_key($core_extension['module'], $module_data));
    foreach ($nonexistent_modules as $module) {
      $config_importer->logError($this->t('Unable to install the %module module since it does not exist.', ['%module' => $module]));
    }

    // Get a list of parent profiles and the main profile.
    $profiles = $this->profileHandler->getProfileInheritance();
    $main_profile = end($profiles);

    // Ensure that all modules being installed have their dependencies met.
    $installs = $config_importer->getExtensionChangelist('module', 'install');
    foreach ($installs as $module) {
      $missing_dependencies = [];
      foreach (array_keys($module_data[$module]->requires) as $required_module) {
        if (!isset($core_extension['module'][$required_module]) && !array_key_exists($module, $profiles)) {
          $missing_dependencies[] = $module_data[$required_module]->info['name'];
        }
      }
      if (!empty($missing_dependencies)) {
        $module_name = $module_data[$module]->info['name'];
        $message = $this->formatPlural(count($missing_dependencies),
          'Unable to install the %module module since it requires the %required_module module.',
          'Unable to install the %module module since it requires the %required_module modules.',
          [
            '%module' => $module_name,
            '%required_module' => implode(', ', $missing_dependencies),
          ]
        );
        $config_importer->logError($message);
      }
    }

    // Check if profile is not empty in configuration.
    // ERROR #1.
    // The error was shown when a value under the key 'profile' was empty
    // so we got the error: "Cannot change the install profile from to '...'
    // once Drupal is installed."
    // Fix:added checking on empty profile in importing config 'core.extension'
    // ($core_extension['profile']) to avoid this error.
    if (!empty($current_core_extension['profile']) && $core_extension['profile']) {
      // Ensure the active profile is not changing.
      if ($current_core_extension['profile'] !== $core_extension['profile']) {
        $config_importer->logError($this->t('Cannot change the install profile from %new_profile to %profile once Drupal is installed.', ['%profile' => $current_core_extension['profile'], '%new_profile' => $core_extension['profile']]));
      }
    }

    // Ensure that all modules being uninstalled are not required by modules
    // that will be installed after the import.
    $uninstalls = $config_importer->getExtensionChangelist('module', 'uninstall');

    // If current profile found, remove it from the uninstalls.
    // ERROR #2.
    // The error was shown because the current profile was not found
    // in the importing config 'core.extension' so it has to be uninstalled.
    // We force to remove the current profile from the uninstalls modules list.
    if (!empty($current_core_extension['profile']) && $uninstalls_cur_profile_key = array_search($current_core_extension['profile'], $uninstalls)) {
      unset($uninstalls[$uninstalls_cur_profile_key]);
    }

    foreach ($uninstalls as $module) {
      foreach (array_keys($module_data[$module]->required_by) as $dependent_module) {
        if ($module_data[$dependent_module]->status && !in_array($dependent_module, $uninstalls, TRUE)) {
          if (!array_key_exists($dependent_module, $profiles)) {
            $module_name = $module_data[$module]->info['name'];
            $dependent_module_name = $module_data[$dependent_module]->info['name'];
            $config_importer->logError($this->t('Unable to uninstall the %module module since the %dependent_module module is installed.', [
              '%module' => $module_name,
              '%dependent_module' => $dependent_module_name,
            ]));
          }
        }
      }
    }

    if ($profile_uninstalls = array_intersect_key($profiles, array_flip($uninstalls))) {
      // Ensure that none of the parent profiles are being uninstalled.
      $profile_names = [];
      foreach ($profile_uninstalls as $profile) {
        if ($profile->getName() !== $main_profile->getName()) {
          $profile_names[] = $module_data[$profile->getName()]->info['name'];
        }
      }
      if (!empty($profile_names)) {
        $message = $this->formatPlural(count($profile_names),
          'Unable to uninstall the :profile profile since it is a parent of another installed profile.',
          'Unable to uninstall the :profile profiles since they are parents of another installed profile.',
          [':profile' => implode(', ', $profile_names)]
        );
        $config_importer->logError($message);
      }
    }
  }

}
