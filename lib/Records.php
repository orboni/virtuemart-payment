<?php

/**
 * Orboni for VirtueMart.
 *
 * @copyright 2026 Orboni
 * @license   GNU General Public License version 2 or later; see LICENSE
 */

namespace Orboni\VirtueMart;

defined('_JEXEC') or exit;

use Joomla\CMS\Factory;

/**
 * One row per order in the plugin's table (VirtueMart makes it from
 * {@see FIELDS} when the payment method is saved): the current payment page
 * and every earlier one (a buyer may still pay an older page), what was last
 * applied, the webhook events taken, the session that paid the order, and
 * the buyer's key back to the store. Read and written fresh every time:
 * VirtueMart's own getDataByOrderId() keeps a copy for the whole request.
 */
class Records
{
    const TABLE = '#__virtuemart_payment_plg_orboni';

    const FIELDS = [
        'id' => 'int(1) UNSIGNED NOT NULL AUTO_INCREMENT',
        'virtuemart_order_id' => 'int(1) UNSIGNED',
        'order_number' => 'char(64)',
        'virtuemart_paymentmethod_id' => 'mediumint(1) UNSIGNED',
        'payment_name' => 'varchar(5000)',
        'payment_order_total' => 'decimal(15,5) NOT NULL DEFAULT \'0.00000\'',
        'payment_currency' => 'char(3)',
        'session_id' => 'varchar(64) NOT NULL DEFAULT \'\'',
        'sessions' => 'text',
        'amount' => 'bigint NOT NULL DEFAULT \'0\'',
        'attempt' => 'int(1) NOT NULL DEFAULT \'0\'',
        'state' => 'varchar(128) NOT NULL DEFAULT \'\'',
        'paid_session' => 'varchar(64) NOT NULL DEFAULT \'\'',
        'events' => 'text',
        'token' => 'char(32) NOT NULL DEFAULT \'\'',
        'expires_at' => 'int(1) NOT NULL DEFAULT \'0\'',
        'checked_at' => 'int(1) NOT NULL DEFAULT \'0\'',
    ];

    const DEFAULTS = [
        'order_number' => '', 'virtuemart_paymentmethod_id' => 0, 'payment_name' => '', 'payment_order_total' => 0,
        'payment_currency' => '', 'session_id' => '', 'sessions' => [], 'amount' => 0, 'attempt' => 0, 'state' => '',
        'paid_session' => '', 'events' => [], 'token' => '', 'expires_at' => 0, 'checked_at' => 0,
    ];

    /**
     * @return array<string, mixed>|null
     */
    public static function get(int $orderId)
    {
        $db = Factory::getContainer()->get('DatabaseDriver');
        $query = $db->getQuery(true)
            ->select('*')
            ->from($db->quoteName(self::TABLE))
            ->where($db->quoteName('virtuemart_order_id').' = '.(int) $orderId)
            ->order($db->quoteName('id').' DESC');

        try {
            $row = $db->setQuery($query, 0, 1)->loadAssoc();
        } catch (\Throwable $e) {
            return null; // No table yet: the method was never saved.
        }
        if (! is_array($row)) {
            return null;
        }

        $row['sessions'] = json_decode((string) $row['sessions'], true) ?: [];
        $row['events'] = json_decode((string) $row['events'], true) ?: [];

        return $row;
    }

    /**
     * @param  array<string, mixed>  $fields
     */
    public static function save(int $orderId, array $fields)
    {
        $db = Factory::getContainer()->get('DatabaseDriver');
        $row = self::get($orderId);
        $values = array_merge(self::DEFAULTS, $row === null ? [] : $row, $fields);
        $now = Factory::getDate()->toSql();

        $data = (object) [
            'virtuemart_order_id' => $orderId,
            'order_number' => (string) $values['order_number'],
            'virtuemart_paymentmethod_id' => (int) $values['virtuemart_paymentmethod_id'],
            'payment_name' => (string) $values['payment_name'],
            'payment_order_total' => (float) $values['payment_order_total'],
            'payment_currency' => (string) $values['payment_currency'],
            'session_id' => (string) $values['session_id'],
            'sessions' => (string) json_encode(array_values(array_unique($values['sessions']))),
            'amount' => (int) $values['amount'],
            'attempt' => (int) $values['attempt'],
            'state' => (string) $values['state'],
            'paid_session' => (string) $values['paid_session'],
            'events' => (string) json_encode(array_values($values['events'])),
            'token' => (string) $values['token'],
            'expires_at' => (int) $values['expires_at'],
            'checked_at' => (int) $values['checked_at'],
            'modified_on' => $now,
        ];

        if ($row === null) {
            $data->created_on = $now;
            $db->insertObject(self::TABLE, $data);
        } else {
            $data->id = (int) $row['id'];
            $db->updateObject(self::TABLE, $data, 'id');
        }
    }

    /**
     * Orders of this payment method whose payment may still move, whose page
     * closed less than two days ago and that were not read in the last 50
     * minutes: what the cron reads back. A new page counts as read 40
     * minutes before it was made, so its first check comes ten minutes after.
     *
     * @return list<int>
     */
    public static function open(int $methodId, int $limit = 50): array
    {
        $db = Factory::getContainer()->get('DatabaseDriver');
        $query = $db->getQuery(true)
            ->select($db->quoteName('virtuemart_order_id'))
            ->from($db->quoteName(self::TABLE))
            ->where($db->quoteName('virtuemart_paymentmethod_id').' = '.(int) $methodId)
            ->where($db->quoteName('paid_session').' = '.$db->quote(''))
            ->where($db->quoteName('session_id').' <> '.$db->quote(''))
            ->where($db->quoteName('expires_at').' > '.(time() - 2 * 86400))
            ->where($db->quoteName('checked_at').' < '.(time() - Sync::RECHECK_SECONDS))
            ->order($db->quoteName('checked_at').' ASC');

        try {
            $ids = $db->setQuery($query, 0, $limit)->loadColumn();
        } catch (\Throwable $e) {
            return [];
        }

        return array_values(array_unique(array_map('intval', is_array($ids) ? $ids : [])));
    }
}
