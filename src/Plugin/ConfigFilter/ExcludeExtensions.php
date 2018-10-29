<?php

namespace Drupal\company_base_config_import\Plugin\ConfigFilter;

use Drupal\config_filter\Plugin\ConfigFilterBase;

/**
 * Excludes a set of extensions from exporting to core.extension.yml.
 *
 * @ConfigFilter(
 *   id = "company_base_config_import_exclude_extension",
 *   label = "Excludes the installed profile from core.extension.yml modules.",
 *   weight = 10
 * )
 */
class ExcludeExtensions extends ConfigFilterBase {

  /**
   * {@inheritdoc}
   */
  public function filterWrite($name, array $data) {
    if ($name === 'core.extension' && isset($data['module'])) {
      foreach ($this->getExtensionModules() as $module_name) {
        if (isset($data['module'][$module_name])) {
          unset($data['module'][$module_name]);
        }
      }
    }

    return parent::filterWrite($name, $data);
  }

  /**
   * Returns an array of modules to exclude.
   *
   * @return array
   *   An array of module names.
   */
  protected function getExtensionModules() {
    return [\Drupal::installProfile()];
  }

}
