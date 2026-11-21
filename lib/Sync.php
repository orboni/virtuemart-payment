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
use Joomla\CMS\Language\Text;

/**
 * An Orboni checkout session, read back, onto its VirtueMart order. The one
 * place an order changes for a payment: the webhook, the buyer's return and
 * the cron all come here with the session as Orboni shows it now, so the
 * order follows the payment whatever arrives first, twice or out of order.
 *
 * - complete + paid → the method's "paid" status (Confirmed by default), the
 *   buyer notified;
 * - on its way (processing), short of the amount (partially_paid) or held for
 *   the merchant's review (held) → a line in the order's history, the order
 *   left waiting;
 * - expired with nothing paid → the method's "cancelled" status, if it is
 *   still the order's own session and the order still waits for payment;
 * - a paid order goes back to waiting only when Orboni says money that paid
 *   it left the chain (`payment_reversed`); nothing else downgrades it.
 */
class Sync
{
    const REVERSED = 'checkout.session.payment_reversed';

    const EXTRA = 'checkout.session.extra_received';

    /** Seconds after a read before the cron reads the same order again. */
    const RECHECK_SECONDS = 3000;

    /** Webhook events remembered per order, so a retry or a replay adds nothing twice. */
    const EVENTS_KEPT = 30;

    /** Orboni's network keys as people write them; a network added later reads as its key. */
    const CHAIN_NAMES = [
        'tron' => 'TRON', 'ethereum' => 'Ethereum', 'bsc' => 'BNB Chain', 'solana' => 'Solana', 'ton' => 'TON',
        'polygon' => 'Polygon', 'base' => 'Base', 'arbitrum' => 'Arbitrum', 'optimism' => 'Optimism',
        'avalanche' => 'Avalanche', 'bitcoin' => 'Bitcoin', 'litecoin' => 'Litecoin', 'dogecoin' => 'Dogecoin',
        'bitcoincash' => 'Bitcoin Cash', 'xrp' => 'XRP Ledger', 'stellar' => 'Stellar',
    ];

    /** @var \plgVmPaymentOrboni */
    private $plugin;

    public function __construct(\plgVmPaymentOrboni $plugin)
    {
        $this->plugin = $plugin;
    }

    /**
     * {@see apply()} under a lock, with the order and its record read fresh:
     * the webhook and the buyer's return can land in the same second.
     *
     * @param  array<string, mixed>  $session
     * @return bool|null true applied, false an event already taken (or no such order), null the order is busy
     */
    public function applyLocked(int $orderId, array $session, string $event = '', string $eventId = '')
    {
        $db = Factory::getContainer()->get('DatabaseDriver');
        $lock = $db->quote('orboni_vm_'.$db->getPrefix().$orderId);
        if ((int) $db->setQuery('SELECT GET_LOCK('.$lock.', 0)')->loadResult() !== 1) {
            return null;
        }

        try {
            $record = Records::get($orderId);
            $order = \VmModel::getModel('orders')->getOrder($orderId);
            if ($record === null) {
                return false;
            }
            if (empty($order['details']['BT'])) {
                // Deleted, as VirtueMart's "reuse orders" does with a pending order confirmed again.
                $this->plugin->log(sprintf('Order %d is gone from VirtueMart; session %s left alone.', $orderId, isset($session['id']) ? $session['id'] : ''), true);

                return false;
            }

            if ($eventId !== '') {
                if (in_array($eventId, $record['events'], true)) {
                    return false;
                }
                $record['events'][] = $eventId;
                Records::save($orderId, ['events' => array_slice($record['events'], -self::EVENTS_KEPT)]);
            }

            $this->apply($orderId, $order['details']['BT'], $record, $session, $event);

            return true;
        } finally {
            $db->setQuery('SELECT RELEASE_LOCK('.$lock.')')->execute();
        }
    }

    /**
     * This order's session: made for it by this store. The webhook checks the
     * same before reading anything back, and a session read back is checked
     * again here.
     *
     * @param  array<string, mixed>  $record
     * @param  array<string, mixed>  $session
     */
    public function belongs(int $orderId, $bt, array $record, array $session): bool
    {
        $id = isset($session['id']) ? (string) $session['id'] : '';
        $metadata = isset($session['metadata']) && is_array($session['metadata']) ? $session['metadata'] : [];

        return $id !== ''
            && $this->plugin->isOrboniMethod((int) $bt->virtuemart_paymentmethod_id)
            && in_array($id, $record['sessions'], true)
            && (isset($session['client_reference_id']) ? (string) $session['client_reference_id'] : '') === (string) $orderId
            && (isset($metadata['virtuemart_site']) ? (string) $metadata['virtuemart_site'] : '') === $this->plugin->siteKey();
    }

    /**
     * @param  object  $bt  the order's details (VirtueMart's BT row)
     * @param  array<string, mixed>  $record
     * @param  array<string, mixed>  $session  Orboni's checkout session object
     * @param  string  $event  the webhook's type, when a webhook brought it
     */
    private function apply(int $orderId, $bt, array $record, array $session, string $event)
    {
        $id = isset($session['id']) ? (string) $session['id'] : '';
        if (! $this->belongs($orderId, $bt, $record, $session)) {
            $this->plugin->log(sprintf('Order %d: session %s is not this order\'s; left alone.', $orderId, $id), true);

            return;
        }

        $method = $this->plugin->method((int) $bt->virtuemart_paymentmethod_id);
        $status = isset($session['status']) ? (string) $session['status'] : '';
        $payment = isset($session['payment_status']) ? (string) $session['payment_status'] : '';
        $state = $id.':'.$status.':'.$payment;
        $paidHere = $status === 'complete' && $payment === 'paid';

        if ($record['paid_session'] !== '') {
            $this->afterPaid($orderId, $bt, $record, $method, $session, $event, $paidHere, $state);

            return;
        }

        if ($record['state'] === $state) {
            return;
        }
        Records::save($orderId, ['state' => $state]);

        if ($paidHere) {
            $this->complete($orderId, $bt, $method, $session);

            return;
        }

        switch ($payment) {
            case 'processing':
                $this->note($orderId, $bt, Text::_('PLG_VMPAYMENT_ORBONI_NOTE_PROCESSING'));

                return;

            case 'partially_paid':
                $this->note($orderId, $bt, Text::sprintf(
                    $status === 'expired' ? 'PLG_VMPAYMENT_ORBONI_NOTE_PARTLY_CLOSED' : 'PLG_VMPAYMENT_ORBONI_NOTE_PARTLY',
                    Currencies::money($session, 'amount_received'),
                    Currencies::money($session, 'amount_due')
                ));

                return;

            case 'held':
                $this->note($orderId, $bt, Text::sprintf(
                    'PLG_VMPAYMENT_ORBONI_NOTE_HELD',
                    isset($session['review_reason']) ? (string) $session['review_reason'] : '—'
                ));

                return;
        }

        // A newer session (the buyer came back and confirmed again) still counts.
        if ($status === 'expired' && $payment === 'unpaid' && $record['session_id'] === $id
            && (string) $bt->order_status === $this->plugin->statusPending($method)) {
            $this->setStatus($orderId, $this->plugin->statusCanceled($method), Text::_('PLG_VMPAYMENT_ORBONI_NOTE_EXPIRED'), false);
        }
    }

    /**
     * @param  object  $bt
     * @param  object  $method
     * @param  array<string, mixed>  $session
     */
    private function complete(int $orderId, $bt, $method, array $session)
    {
        $asked = isset($session['amount_total']) ? (int) $session['amount_total'] : 0;
        $askedIn = strtoupper(isset($session['currency']) ? (string) $session['currency'] : '');
        [$due, $currency] = $this->plugin->due($bt, $method);

        // The order was edited to cost more (or another currency) after the buyer was sent to pay.
        if ($askedIn !== $currency || $asked < $due) {
            $this->note($orderId, $bt, Text::sprintf(
                'PLG_VMPAYMENT_ORBONI_NOTE_TOTAL_CHANGED',
                Currencies::format($asked, $askedIn),
                Currencies::format($due, $currency)
            ));

            return;
        }

        Records::save($orderId, ['paid_session' => (string) $session['id']]);
        $this->setStatus($orderId, $this->plugin->statusSuccess($method), $this->paidNote($session), true);
        $this->plugin->log(sprintf('Order %d: paid by session %s.', $orderId, $session['id']));
    }

    /**
     * @param  object  $bt
     * @param  array<string, mixed>  $record
     * @param  object  $method
     * @param  array<string, mixed>  $session
     */
    private function afterPaid(int $orderId, $bt, array $record, $method, array $session, string $event, bool $paidHere, string $state)
    {
        $id = (string) $session['id'];

        if ($record['paid_session'] === $id) {
            if ($event === self::REVERSED && ! $paidHere) {
                // Not paid any more: the session paid in full again completes the order again.
                Records::save($orderId, ['paid_session' => '', 'state' => $state]);
                $this->setStatus($orderId, $this->plugin->statusPending($method), Text::sprintf(
                    'PLG_VMPAYMENT_ORBONI_NOTE_REVERSED',
                    Currencies::money($session, 'amount_received')
                ), false);
                $this->plugin->log(sprintf('Order %d: payment reversed on session %s.', $orderId, $id), true);
            } elseif ($event === self::EXTRA) {
                $this->note($orderId, $bt, Text::sprintf('PLG_VMPAYMENT_ORBONI_NOTE_EXTRA', Currencies::money($session, 'amount_received')));
            }

            return;
        }

        // Another of the order's sessions paid too: the buyer paid twice.
        if ($paidHere && $record['state'] !== $state) {
            Records::save($orderId, ['state' => $state]);
            $this->note($orderId, $bt, Text::sprintf('PLG_VMPAYMENT_ORBONI_NOTE_PAID_TWICE', $id, Currencies::money($session, 'amount_received')));
            $this->plugin->log(sprintf('Order %d: paid twice, again by session %s.', $orderId, $id), true);
        }
    }

    /** A new status, with what happened in the order's history. */
    private function setStatus(int $orderId, string $status, string $comment, bool $notify)
    {
        $data = ['order_status' => $status, 'customer_notified' => $notify ? 1 : 0, 'comments' => $comment];
        \VmModel::getModel('orders')->updateStatusForOneOrder($orderId, $data, true);
    }

    /**
     * A line in the order's history under its current status, for the store
     * only.
     *
     * @param  object  $bt
     */
    private function note(int $orderId, $bt, string $comment)
    {
        \VmModel::getModel('orders')->_updateOrderHist($orderId, (string) $bt->order_status, 0, $comment);
    }

    /**
     * "Paid with 25.00 USDT on TRON (25.00 USD) through Orboni, session cs_live_…."
     *
     * @param  array<string, mixed>  $session
     */
    private function paidNote(array $session): string
    {
        $payment = isset($session['payment']) && is_array($session['payment']) ? $session['payment'] : [];
        $test = empty($session['livemode']) ? ' '.Text::_('PLG_VMPAYMENT_ORBONI_NOTE_TEST') : '';
        $late = ! empty($session['paid_late']) ? ' '.Text::_('PLG_VMPAYMENT_ORBONI_NOTE_LATE') : '';

        if (isset($payment['coin'], $payment['amount'])) {
            $chain = isset($payment['chain']) ? (string) $payment['chain'] : '';

            return Text::sprintf(
                'PLG_VMPAYMENT_ORBONI_NOTE_PAID_WITH',
                (string) $payment['amount'],
                (string) $payment['coin'],
                isset(self::CHAIN_NAMES[$chain]) ? self::CHAIN_NAMES[$chain] : ucfirst($chain),
                Currencies::money($session, 'amount_received'),
                (string) $session['id']
            ).$test.$late;
        }

        return Text::sprintf('PLG_VMPAYMENT_ORBONI_NOTE_PAID', Currencies::money($session, 'amount_received'), (string) $session['id']).$test.$late;
    }
}
