<?php

/**
 * Orboni for VirtueMart.
 *
 * @copyright 2026 Orboni
 * @license   GNU General Public License version 2 or later; see LICENSE
 */
defined('_JEXEC') or exit;

use Joomla\CMS\Factory;

/**
 * Switches the plugin on when it is first installed: Joomla installs
 * plugins switched off, and VirtueMart lists a payment plugin only once it
 * is on. An update leaves the setting as the merchant has it.
 */
class PlgVmpaymentOrboniInstallerScript
{
    public function postflight($type, $parent)
    {
        if ($type !== 'install') {
            return true;
        }

        $db = Factory::getContainer()->get('DatabaseDriver');
        $query = $db->getQuery(true)
            ->update($db->quoteName('#__extensions'))
            ->set($db->quoteName('enabled').' = 1')
            ->where($db->quoteName('type').' = '.$db->quote('plugin'))
            ->where($db->quoteName('folder').' = '.$db->quote('vmpayment'))
            ->where($db->quoteName('element').' = '.$db->quote('orboni'));
        $db->setQuery($query)->execute();

        return true;
    }
}
