<?php

/**
 * Orboni for VirtueMart.
 *
 * @copyright 2026 Orboni
 * @license   GNU General Public License version 2 or later; see LICENSE
 */
defined('_JEXEC') or exit;

use Joomla\CMS\Form\FormField;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Uri\Uri;
use Orboni\VirtueMart\Signature;

require_once dirname(__DIR__).'/lib/Signature.php';

/**
 * The two addresses a merchant copies out of the payment method's settings:
 * the webhook for Orboni's dashboard, and the cron address for Joomla's
 * Scheduled Tasks (or any cron), made from the saved secret key.
 */
class JFormFieldOrboniaddresses extends FormField
{
    protected $type = 'Orboniaddresses';

    protected function getInput()
    {
        $base = Uri::root().'index.php?option=com_virtuemart&view=vmplg&task=notify&tmpl=component';
        $secret = trim((string) $this->form->getValue('secret_key', 'params', ''));
        if ($secret === '') {
            $secret = trim((string) $this->form->getValue('secret_key', null, ''));
        }
        $key = Signature::cronKey($secret);

        $html = '<div class="orboni-addresses">';
        $html .= '<p><strong>'.htmlspecialchars(Text::_('PLG_VMPAYMENT_ORBONI_WEBHOOK_URL'), ENT_QUOTES, 'UTF-8').'</strong><br>';
        $html .= '<code style="user-select:all">'.htmlspecialchars($base.'&orboni=webhook', ENT_QUOTES, 'UTF-8').'</code><br>';
        $html .= '<small>'.htmlspecialchars(Text::_('PLG_VMPAYMENT_ORBONI_WEBHOOK_URL_TIP'), ENT_QUOTES, 'UTF-8').'</small></p>';
        $html .= '<p><strong>'.htmlspecialchars(Text::_('PLG_VMPAYMENT_ORBONI_CRON_URL'), ENT_QUOTES, 'UTF-8').'</strong><br>';
        if ($key === '') {
            $html .= '<small>'.htmlspecialchars(Text::_('PLG_VMPAYMENT_ORBONI_CRON_URL_SAVE_FIRST'), ENT_QUOTES, 'UTF-8').'</small></p>';
        } else {
            $html .= '<code style="user-select:all">'.htmlspecialchars($base.'&orboni=cron&key='.$key, ENT_QUOTES, 'UTF-8').'</code><br>';
            $html .= '<small>'.htmlspecialchars(Text::_('PLG_VMPAYMENT_ORBONI_CRON_URL_TIP'), ENT_QUOTES, 'UTF-8').'</small></p>';
        }

        return $html.'</div>';
    }
}
