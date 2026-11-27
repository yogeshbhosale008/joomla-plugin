<?php

/**
 * @package     Joomla.Plugin
 * @subpackage  System.contactcc
 *
 * @copyright   (C) 2026 Yogesh B. All rights reserved.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

declare(strict_types=1);

\defined('_JEXEC') or die;

use Joomla\CMS\Extension\PluginInterface;
use Joomla\CMS\Factory;
use Joomla\CMS\Plugin\PluginHelper;
use Joomla\DI\Container;
use Joomla\DI\ServiceProviderInterface;
use Joomla\Event\DispatcherInterface;
use Joomla\Plugin\System\Contactcc\Extension\Contactcc;

return new class () implements ServiceProviderInterface {
    /**
     * Registers the plugin with the DI container.
     *
     * @param   Container  $container  The DI container.
     *
     * @return  void
     */
    public function register(Container $container): void
    {
        $container->set(
            PluginInterface::class,
            static function (Container $container): PluginInterface {
                // Joomla 4.0/4.1 take the dispatcher by reference, so it must be a variable.
                $dispatcher = $container->get(DispatcherInterface::class);

                $plugin = new Contactcc(
                    $dispatcher,
                    (array) PluginHelper::getPlugin('system', 'contactcc')
                );

                // setApplication() exists from Joomla 4.2 onwards.
                if (method_exists($plugin, 'setApplication')) {
                    $plugin->setApplication(Factory::getApplication());
                }

                return $plugin;
            }
        );
    }
};
