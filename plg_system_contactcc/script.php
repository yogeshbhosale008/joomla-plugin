<?php

/**
 * @package     Joomla.Plugin
 * @subpackage  System.contactcc
 *
 * @copyright   (C) 2026 Yogesh B. All rights reserved.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 *
 * Installer script. Joomla does not read <phpminimum> from plugin manifests, so this is what
 * actually stops the plugin from being installed on PHP < 8.1 (where its classes would not parse
 * and, as a system plugin, would take the whole site down).
 *
 * Keep this file free of PHP 8 syntax so the check can still run and show its message on old PHP.
 */

defined('_JEXEC') or die;

use Joomla\CMS\Installer\InstallerScript;

class PlgSystemContactccInstallerScript extends InstallerScript
{
    /**
     * @var string
     */
    protected $minimumPhp = '8.1.0';

    /**
     * @var string
     */
    protected $minimumJoomla = '4.0.0';
}
