<?php

/**
 * Orboni for VirtueMart: crypto payments (USDT, USDC, BTC, ETH and more)
 * straight to the store's own wallets.
 *
 * @copyright 2026 Orboni
 * @license   GNU General Public License version 2 or later; see LICENSE
 */
defined('_JEXEC') or exit;

use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Log\Log;
use Joomla\CMS\Router\Route;
use Joomla\CMS\Uri\Uri;
use Orboni\VirtueMart\Api;
use Orboni\VirtueMart\ApiException;
use Orboni\VirtueMart\Currencies;
use Orboni\VirtueMart\Records;
use Orboni\VirtueMart\Signature;
use Orboni\VirtueMart\Sync;

if (! class_exists('vmPSPlugin')) {
    require VMPATH_PLUGINLIBS.'/vmpsplugin.php';
}

require_once __DIR__.'/lib/ApiException.php';
require_once __DIR__.'/lib/Api.php';
require_once __DIR__.'/lib/Currencies.php';
require_once __DIR__.'/lib/Records.php';
require_once __DIR__.'/lib/Signature.php';
require_once __DIR__.'/lib/Sync.php';

/**
 * "Crypto" at checkout: once the order is confirmed the buyer goes to
 * Orboni's payment page, picks a coin and pays to the store's own wallet.
 * The order is marked paid by the signed webhook, by reading the session back
 * when the buyer returns, or by the cron address, whichever comes first
 * ({@see Sync}). The cart is kept until then, so a buyer who comes back can
 * pay another way.
 */
class plgVmPaymentOrboni extends vmPSPlugin
{
    /**
     * Time a payment page must still have to be reused when the order is
     * confirmed again: less, and the buyer gets a new one. A page Orboni
     * still calls open may be past its time (held open while a network
     * cannot be read).
     */
    const REUSE_MIN_SECONDS = 300;

    /** Orders the cron reads per call. */
    const CRON_BATCH = 50;

    const LOG_CATEGORY = 'plg_vmpayment_orboni';

    /** @var bool */
    private $debug = false;

    /** @var bool */
    private static $logger = false;

    public function __construct(&$subject, $config)
    {
        parent::__construct($subject, $config);
        // VirtueMart loads the plugin's strings into its own language object; Text reads Joomla's.
        Factory::getLanguage()->load('plg_vmpayment_orboni', __DIR__);

        $this->_loggable = true;
        $this->tableFields = array_keys($this->getTableSQLFields());
        $this->_tablepkey = 'id';
        $this->_tableId = 'id';
        $varsToPush = $this->getVarsToPush();
        $this->addVarsToPushCore($varsToPush, 1);
        $this->setConfigParameterable($this->_configTableFieldName, $varsToPush);
        $this->setConvertable(['min_amount', 'max_amount', 'cost_per_transaction', 'cost_min_transaction']);
        $this->setCryptedFields(['secret_key', 'webhook_secret']);
    }

    /** The plugin's table, one row per order ({@see Records}). */
    public function getTableSQLFields()
    {
        return Records::FIELDS;
    }

    public function plgVmOnStoreInstallPaymentPluginTable($jplugin_id)
    {
        return $this->onStoreInstallPluginTable($jplugin_id);
    }

    public function plgVmDeclarePluginParamsPaymentVM3(&$data)
    {
        return $this->declarePluginParams('payment', $data);
    }

    public function plgVmSetOnTablePluginParamsPayment($name, $id, &$table)
    {
        return $this->setOnTablePluginParams($name, $id, $table);
    }

    public function plgVmOnSelectCheckPayment(VirtueMartCart $cart, &$msg)
    {
        return $this->OnSelectCheck($cart);
    }

    public function plgVmDisplayListFEPayment(VirtueMartCart $cart, $selected, &$htmlIn)
    {
        return $this->displayListFE($cart, $selected, $htmlIn);
    }

    public function plgVmOnSelectedCalculatePricePayment(VirtueMartCart $cart, array &$cart_prices, &$cart_prices_name)
    {
        return $this->onSelectedCalculatePrice($cart, $cart_prices, $cart_prices_name);
    }

    public function plgVmOnCheckAutomaticSelectedPayment(VirtueMartCart $cart, array $cart_prices, &$paymentCounter)
    {
        return $this->onCheckAutomaticSelected($cart, $cart_prices, $paymentCounter);
    }

    public function plgVmOnShowOrderFEPayment($virtuemart_order_id, $virtuemart_paymentmethod_id, &$payment_name)
    {
        $this->onShowOrderFE($virtuemart_order_id, $virtuemart_paymentmethod_id, $payment_name);
    }

    public function plgVmOnShowOrderPrintPayment($order_number, $method_id)
    {
        return $this->onShowOrderPrint($order_number, $method_id);
    }

    public function plgVmgetPaymentCurrency($virtuemart_paymentmethod_id, &$paymentCurrencyId)
    {
        if (! ($method = $this->getVmPluginMethod($virtuemart_paymentmethod_id))) {
            return null;
        }
        if (! $this->selectedThisElement($method->payment_element)) {
            return false;
        }
        $paymentCurrencyId = $this->paymentCurrencyId($method);
    }

    /**
     * Offered only with a secret key, in a currency Orboni takes and, for a
     * dollar price, from $1 up; then VirtueMart's own conditions (countries,
     * amounts, categories).
     */
    protected function checkConditions($cart, $method, $cart_prices)
    {
        if (trim((string) $method->secret_key) === '') {
            return false;
        }
        $currencyId = $this->paymentCurrencyId($method);
        $currency = $this->currencyCode($currencyId);
        if (! Currencies::supports($currency)) {
            return false;
        }
        if ($currency === 'USD') {
            $amount = vmPSPlugin::getAmountValueInCurrency($this->getCartAmount($cart_prices), $currencyId);
            if ($amount > 0 && Currencies::minor((float) $amount, $currency) < Currencies::MIN_USD_CENTS) {
                return false;
            }
        }

        return parent::checkConditions($cart, $method, $cart_prices);
    }

    /**
     * The order is made: off to its payment page, open or new. On a refusal
     * the buyer is back in the cart with a sentence, and the order cancelled.
     */
    public function plgVmConfirmedOrder($cart, $order)
    {
        if (! ($method = $this->getVmPluginMethod($order['details']['BT']->virtuemart_paymentmethod_id))) {
            return null;
        }
        if (! $this->selectedThisElement($method->payment_element)) {
            return false;
        }

        $app = Factory::getApplication();
        $result = $this->paymentPage($method, $order);

        // The cart stays: a buyer who comes back from the payment page can pay another way.
        $cart->_confirmDone = false;
        $cart->_dataValidated = false;
        $cart->setCartIntoSession();

        if (isset($result['error'])) {
            $orderId = (int) $order['details']['BT']->virtuemart_order_id;
            VmModel::getModel('orders')->updateStatusForOneOrder($orderId, [
                'order_status' => $this->statusCanceled($method),
                'customer_notified' => 0,
                'comments' => Text::sprintf('PLG_VMPAYMENT_ORBONI_NOTE_NO_PAGE', $result['error']),
            ], true);
            $app->enqueueMessage($result['error'], 'error');
            $app->redirect(Route::_('index.php?option=com_virtuemart&view=cart', false));
        }

        $app->redirect($result['url']);
    }

    /**
     * Where the buyer goes: `url` (the payment page, or the return page of an
     * order already paid), or `error`, a sentence for them.
     *
     * @param  array<string, mixed>  $order
     * @return array{url?: string, error?: string}
     */
    private function paymentPage($method, array $order): array
    {
        $bt = $order['details']['BT'];
        $orderId = (int) $bt->virtuemart_order_id;
        $unavailable = Text::_('PLG_VMPAYMENT_ORBONI_ERROR_UNAVAILABLE');

        [$amount, $currency] = $this->due($bt, $method);
        if (! Currencies::supports($currency)) {
            return ['error' => Text::sprintf('PLG_VMPAYMENT_ORBONI_ERROR_CURRENCY', $currency)];
        }
        if ($currency === 'USD' && $amount < Currencies::MIN_USD_CENTS) {
            return ['error' => Text::_('PLG_VMPAYMENT_ORBONI_ERROR_MINIMUM_USD')];
        }
        $secret = trim((string) $method->secret_key);
        if ($secret === '') {
            return ['error' => $unavailable];
        }

        $record = Records::get($orderId);
        if ($record === null) {
            $record = Records::DEFAULTS;
        }
        if ($record['paid_session'] !== '' && $record['token'] !== '') {
            return ['url' => $this->returnUrl($method, $bt, (string) $record['token'])];
        }

        $api = new Api($secret);

        // Confirmed again for the same amount: the page still open is reused,
        // unless it has run out or is about to.
        if ($record['session_id'] !== '' && (int) $record['amount'] === $amount && $record['payment_currency'] === $currency) {
            try {
                $session = $api->getSession((string) $record['session_id']);
                if (self::reusable($session)) {
                    return ['url' => (string) $session['url']];
                }
            } catch (ApiException $e) {
                $this->log(sprintf('Order %d: could not read session %s: %s', $orderId, $record['session_id'], $e->getMessage()), true);
            }
        }

        // Saved first: the return address carries it, and a retry must send the same body.
        $token = $record['token'] !== '' ? (string) $record['token'] : bin2hex(random_bytes(16));
        Records::save($orderId, [
            'order_number' => (string) $bt->order_number,
            'virtuemart_paymentmethod_id' => (int) $bt->virtuemart_paymentmethod_id,
            'payment_name' => $this->renderPluginName($method),
            'token' => $token,
        ]);

        // The key is kept while a try fails, so a session Orboni made for a try
        // whose answer was lost is the one the next try gets.
        $attempt = (int) $record['attempt'] + 1;
        $body = $this->sessionBody($method, $order, $amount, $currency, $token);

        try {
            try {
                $session = $api->createSession($body, $this->idempotencyKey($bt, $amount, $currency, $attempt));
            } catch (ApiException $e) {
                // That lost try was sent with other details (the buyer's language, an
                // edited item): Orboni refuses the key for them, and a new one is taken.
                if ($e->type !== 'idempotency_error' || $e->status !== 400) {
                    throw $e;
                }
                $attempt++;
                $session = $api->createSession($body, $this->idempotencyKey($bt, $amount, $currency, $attempt));
            }
        } catch (ApiException $e) {
            $this->log(sprintf('Order %d: Orboni refused a session (%s): %s', $orderId, $e->type, $e->getMessage()), true);

            // Below the least Orboni takes: under a network's minimum, or worth under $1 in another currency.
            if ($e->type === 'amount_too_small' || ($e->type === 'invalid_request_error' && $e->param === 'amount')) {
                return ['error' => Text::_('PLG_VMPAYMENT_ORBONI_ERROR_MINIMUM')];
            }

            return ['error' => $unavailable];
        }

        if (! isset($session['id'], $session['url']) || ! is_string($session['id']) || ! is_string($session['url'])) {
            $this->log(sprintf('Order %d: Orboni answered without a session id or url.', $orderId), true);

            return ['error' => $unavailable];
        }

        $expires = isset($session['expires_at']) && (int) $session['expires_at'] > 0 ? (int) $session['expires_at'] : time() + 3600;
        $sessions = $record['sessions'];
        $sessions[] = $session['id'];
        Records::save($orderId, [
            'payment_order_total' => $amount / (10 ** Currencies::decimals($currency)),
            'payment_currency' => $currency,
            'session_id' => $session['id'],
            'sessions' => $sessions,
            'amount' => $amount,
            'attempt' => $attempt,
            'expires_at' => $expires,
            'checked_at' => time() - Sync::RECHECK_SECONDS + 600,
        ]);
        VmModel::getModel('orders')->_updateOrderHist($orderId, (string) $bt->order_status, 0, Text::sprintf('PLG_VMPAYMENT_ORBONI_NOTE_SENT', $session['id']));
        $this->log(sprintf('Order %d: session %s for %s.', $orderId, $session['id'], Currencies::format($amount, $currency)));

        return ['url' => $session['url']];
    }

    /**
     * @param  array<string, mixed>  $order
     * @return array<string, mixed>
     */
    private function sessionBody($method, array $order, int $amount, string $currency, string $token): array
    {
        $bt = $order['details']['BT'];
        $orderId = (int) $bt->virtuemart_order_id;
        $email = isset($bt->email) ? trim((string) $bt->email) : '';

        $body = [
            'amount' => $amount,
            'currency' => strtolower($currency),
            'description' => self::fit(self::items($order), 250),
            'details' => self::fit(Text::sprintf('PLG_VMPAYMENT_ORBONI_ORDER_AT', (string) $bt->order_number, $this->storeName($bt)), 500),
            'client_reference_id' => (string) $orderId,
            'customer_email' => filter_var($email, FILTER_VALIDATE_EMAIL) !== false ? $email : null,
            'locale' => Factory::getLanguage()->getTag(),
            'success_url' => $this->returnUrl($method, $bt, $token),
            // Back to the cart, still full: the buyer may pay another way.
            'cancel_url' => Uri::root().'index.php?option=com_virtuemart&view=cart'.$this->linkTail(),
            'metadata' => [
                'virtuemart_order_id' => (string) $orderId,
                'virtuemart_order_number' => (string) $bt->order_number,
                'virtuemart_site' => $this->siteKey(),
            ],
        ];

        return array_filter($body, static function ($value) {
            return $value !== null && $value !== '';
        });
    }

    /**
     * The buyer is back from the payment page: the session is read once, so
     * the order is right even before the webhook comes (or without one), and
     * the page says where the payment stands.
     */
    public function plgVmOnPaymentResponseReceived(&$html, &$paymentResponse = null)
    {
        $methodId = vRequest::getInt('pm', 0);
        if (! ($method = $this->getVmPluginMethod($methodId))) {
            return null;
        }
        if (! $this->selectedThisElement($method->payment_element)) {
            return null;
        }

        $orderId = (int) VirtueMartModelOrders::getOrderIdByOrderNumber(vRequest::getString('on', ''));
        $record = $orderId > 0 ? Records::get($orderId) : null;
        $key = vRequest::getString('key', '');
        if ($record === null || $record['token'] === '' || ! hash_equals((string) $record['token'], $key)) {
            $paymentResponse = Text::_('PLG_VMPAYMENT_ORBONI_RETURN_TITLE_WAITING');
            $html = '<p>'.htmlspecialchars(Text::_('PLG_VMPAYMENT_ORBONI_RETURN_UNKNOWN'), ENT_QUOTES, 'UTF-8').'</p>';

            return true;
        }

        $session = [];
        $secret = trim((string) $method->secret_key);
        if ($secret !== '' && $record['session_id'] !== '') {
            try {
                $session = (new Api($secret))->getSession((string) $record['session_id']);
                if ($record['paid_session'] === '') {
                    (new Sync($this))->applyLocked($orderId, $session);
                    $record = Records::get($orderId);
                }
            } catch (ApiException $e) {
                $this->log(sprintf('Order %d: could not read session %s: %s', $orderId, $record['session_id'], $e->getMessage()), true);
            }
        }

        $order = VmModel::getModel('orders')->getOrder($orderId);
        $bt = $order['details']['BT'];
        $payment = isset($session['payment_status']) ? (string) $session['payment_status'] : '';
        $open = isset($session['status'], $session['url']) && $session['status'] === 'open' && is_string($session['url']);
        $orderLink = Route::_('index.php?option=com_virtuemart&view=orders&layout=details&order_number='.rawurlencode((string) $bt->order_number)
            .'&order_pass='.rawurlencode((string) $bt->order_pass), false);

        $links = [];
        if ($record['paid_session'] !== '' || in_array($payment, ['processing', 'held'], true)) {
            // Paid, or the money on its way: the cart has done its job.
            $this->emptyCart();
            $paymentResponse = Text::_($record['paid_session'] !== '' ? 'PLG_VMPAYMENT_ORBONI_RETURN_TITLE_PAID' : 'PLG_VMPAYMENT_ORBONI_RETURN_TITLE_WAITING');
            $message = Text::_($record['paid_session'] !== '' ? 'PLG_VMPAYMENT_ORBONI_RETURN_PAID'
                : ($payment === 'held' ? 'PLG_VMPAYMENT_ORBONI_RETURN_HELD' : 'PLG_VMPAYMENT_ORBONI_RETURN_PROCESSING'));
            $links[] = [$orderLink, vmText::_('COM_VIRTUEMART_ORDER_VIEW_ORDER')];
        } elseif ($open) {
            $paymentResponse = Text::_('PLG_VMPAYMENT_ORBONI_RETURN_TITLE_WAITING');
            $message = Text::_('PLG_VMPAYMENT_ORBONI_RETURN_OPEN');
            $links[] = [(string) $session['url'], Text::_('PLG_VMPAYMENT_ORBONI_RETURN_TO_PAYMENT')];
        } else {
            $paymentResponse = Text::_('PLG_VMPAYMENT_ORBONI_RETURN_TITLE_CLOSED');
            $message = Text::_($session === [] ? 'PLG_VMPAYMENT_ORBONI_RETURN_UNREAD' : 'PLG_VMPAYMENT_ORBONI_RETURN_CLOSED');
            $links[] = [Route::_('index.php?option=com_virtuemart&view=cart', false), Text::_('PLG_VMPAYMENT_ORBONI_RETURN_TO_CART')];
        }

        $html = '<div class="orboni-return"><p>'.htmlspecialchars($message, ENT_QUOTES, 'UTF-8').'</p>';
        foreach ($links as $link) {
            $html .= '<p><a class="vm-button-correct" href="'.htmlspecialchars($link[0], ENT_QUOTES, 'UTF-8').'">'
                .htmlspecialchars($link[1], ENT_QUOTES, 'UTF-8').'</a></p>';
        }
        $html .= '</div>';

        return true;
    }

    /**
     * `index.php?option=com_virtuemart&view=vmplg&task=notify&orboni=…`:
     * Orboni's webhook (`orboni=webhook`) and the cron (`orboni=cron&key=…`).
     * Anything else is another payment plugin's.
     */
    public function plgVmOnPaymentNotification()
    {
        $what = vRequest::getCmd('orboni', '');
        if ($what === 'webhook') {
            $this->webhook();
        } elseif ($what === 'cron') {
            $this->cron();
        }

        return null;
    }

    /**
     * The signature is checked against each Orboni method's signing secret,
     * the event matched to its order, and the session read back from Orboni
     * before anything changes — the order follows the session as it is now,
     * never an old event's copy of it.
     *
     * Answers: 200 for an event taken or one that is not this store's (Orboni
     * sends every event of a project to each of its endpoints, and a non-2xx
     * would be sent again for days); 401 for a bad signature; 400 for a body
     * that is no event; 503 while the store cannot decide yet (no signing
     * secret saved, Orboni not answering, the order busy) — Orboni retries.
     */
    private function webhook()
    {
        $methods = array_filter($this->orboniMethods(), static function ($method) {
            return trim((string) $method->webhook_secret) !== '';
        });
        if ($methods === []) {
            $this->answer(503, 'not_configured');
        }

        $body = (string) file_get_contents('php://input');
        $header = Factory::getApplication()->getInput()->server->getString('HTTP_WEBHOOK_SIGNATURE', '');
        $signed = null;
        foreach ($methods as $method) {
            if (Signature::verify(trim((string) $method->webhook_secret), $body, $header)) {
                $signed = $method;

                break;
            }
        }
        if ($signed === null) {
            $this->log('Webhook refused: the signature does not match any Orboni payment method\'s signing secret.', true);
            $this->answer(401, 'bad_signature');
        }

        $event = json_decode($body, true);
        if (! is_array($event) || ! isset($event['type']) || ! is_string($event['type'])
            || ! isset($event['data']['object']) || ! is_array($event['data']['object'])) {
            $this->answer(400, 'bad_event');
        }

        $type = $event['type'];
        $object = $event['data']['object'];
        if (strpos($type, 'checkout.session.') !== 0) {
            $this->answer(200, 'ignored');
        }

        $id = isset($object['id']) ? (string) $object['id'] : '';
        $reference = isset($object['client_reference_id']) ? (string) $object['client_reference_id'] : '';
        $metadata = isset($object['metadata']) && is_array($object['metadata']) ? $object['metadata'] : [];
        $site = isset($metadata['virtuemart_site']) ? (string) $metadata['virtuemart_site'] : '';
        if (preg_match(Api::SESSION_ID, $id) !== 1 || ! ctype_digit($reference) || $site !== $this->siteKey()) {
            // Another store's, or a payment link's: not ours to act on.
            $this->answer(200, 'ignored');
        }

        $orderId = (int) $reference;
        $record = Records::get($orderId);
        $method = $record !== null ? $this->method((int) $record['virtuemart_paymentmethod_id']) : null;
        if ($record === null || ! in_array($id, $record['sessions'], true) || $method === null) {
            $this->log(sprintf('Webhook %s for session %s: no order of this store has it.', $type, $id));
            $this->answer(200, 'ignored');
        }

        try {
            $session = (new Api(trim((string) $method->secret_key)))->getSession($id);
        } catch (ApiException $e) {
            $this->log(sprintf('Webhook %s, order %d: could not read session %s back: %s', $type, $orderId, $id, $e->getMessage()), true);
            $this->answer(503, 'retry_later');
        }

        $taken = (new Sync($this))->applyLocked($orderId, $session, $type, isset($event['id']) ? (string) $event['id'] : '');
        if ($taken === null) {
            $this->answer(503, 'busy');
        }

        $this->log(sprintf('Webhook %s, order %d: %s.', $type, $orderId, $taken ? 'applied' : 'already taken'));
        $this->answer(200, $taken ? 'received' : 'duplicate');
    }

    /**
     * The cron address (the method's settings show it): reads back the
     * method's payment pages still on their way, in case a webhook was lost.
     * Its key is made from the secret key, so nobody without it can make the
     * store call Orboni.
     */
    private function cron()
    {
        $key = vRequest::getString('key', '');
        $method = null;
        foreach ($this->orboniMethods() as $candidate) {
            $expected = Signature::cronKey(trim((string) $candidate->secret_key));
            if ($expected !== '' && hash_equals($expected, $key)) {
                $method = $candidate;

                break;
            }
        }
        if ($method === null) {
            $this->answer(403, 'bad_key');
        }

        $api = new Api(trim((string) $method->secret_key));
        $sync = new Sync($this);
        $read = 0;
        foreach (Records::open((int) $method->virtuemart_paymentmethod_id, self::CRON_BATCH) as $orderId) {
            $record = Records::get($orderId);
            if ($record === null) {
                continue;
            }
            Records::save($orderId, ['checked_at' => time()]);
            $read++;

            try {
                $session = $api->getSession((string) $record['session_id']);
            } catch (ApiException $e) {
                $this->log(sprintf('Order %d: the cron could not read session %s: %s', $orderId, $record['session_id'], $e->getMessage()), true);

                continue;
            }

            $sync->applyLocked($orderId, $session);
        }

        $this->answer(200, 'read '.$read);
    }

    /**
     * The order in the admin: its payment pages and where the payment stands,
     * read back first while the order still waits, so what is shown is now.
     */
    public function plgVmOnShowOrderBEPayment($virtuemart_order_id, $virtuemart_paymentmethod_id)
    {
        if (! $this->selectedThisByMethodId($virtuemart_paymentmethod_id)) {
            return null;
        }
        $orderId = (int) $virtuemart_order_id;
        $record = Records::get($orderId);
        if ($record === null) {
            return null;
        }

        $method = $this->method((int) $record['virtuemart_paymentmethod_id']);
        $seen = '';
        if ($method !== null && $record['paid_session'] === '' && $record['session_id'] !== '' && trim((string) $method->secret_key) !== '') {
            try {
                $session = (new Api(trim((string) $method->secret_key)))->getSession((string) $record['session_id']);
                (new Sync($this))->applyLocked($orderId, $session);
                $record = Records::get($orderId);
                $seen = (isset($session['status']) ? (string) $session['status'] : '').' / '.(isset($session['payment_status']) ? (string) $session['payment_status'] : '');
            } catch (ApiException $e) {
                $seen = $e->getMessage();
            }
        }

        $e = static function ($text) {
            return htmlspecialchars((string) $text, ENT_QUOTES, 'UTF-8');
        };
        $html = '<table class="adminlist table">'.$this->getHtmlHeaderBE();
        $html .= $this->getHtmlRowBE('COM_VIRTUEMART_PAYMENT_NAME', $record['payment_name']);
        $html .= $this->getHtmlRowBE('PLG_VMPAYMENT_ORBONI_BE_AMOUNT', $e(Currencies::format((int) $record['amount'], (string) $record['payment_currency'])));
        $html .= $this->getHtmlRowBE('PLG_VMPAYMENT_ORBONI_BE_SESSION', $e($record['session_id']));
        if (count($record['sessions']) > 1) {
            $html .= $this->getHtmlRowBE('PLG_VMPAYMENT_ORBONI_BE_SESSIONS', $e(implode(', ', $record['sessions'])));
        }
        $html .= $this->getHtmlRowBE('PLG_VMPAYMENT_ORBONI_BE_PAID_BY', $record['paid_session'] !== '' ? $e($record['paid_session']) : '—');
        if ($seen !== '') {
            $html .= $this->getHtmlRowBE('PLG_VMPAYMENT_ORBONI_BE_SEEN', $e($seen));
        }

        return $html.'</table>';
    }

    /** The published Orboni payment methods, settings decrypted. */
    private function orboniMethods(): array
    {
        $db = Factory::getContainer()->get('DatabaseDriver');
        $query = $db->getQuery(true)
            ->select($db->quoteName('virtuemart_paymentmethod_id'))
            ->from($db->quoteName('#__virtuemart_paymentmethods'))
            ->where($db->quoteName('payment_element').' = '.$db->quote($this->_name))
            ->where($db->quoteName('published').' = 1');
        $methods = [];
        foreach ((array) $db->setQuery($query)->loadColumn() as $id) {
            $method = $this->method((int) $id);
            if ($method !== null) {
                $methods[] = $method;
            }
        }

        return $methods;
    }

    /** One of this plugin's payment methods, settings decrypted, or null. */
    public function method(int $id)
    {
        $method = $id > 0 ? $this->getVmPluginMethod($id) : null;
        if (! $method || ! isset($method->payment_element) || $method->payment_element !== $this->_name) {
            return null;
        }
        if (! empty($method->debug)) {
            $this->debug = true;
        }

        return $method;
    }

    public function isOrboniMethod(int $id): bool
    {
        return $this->method($id) !== null;
    }

    public function statusPending($method): string
    {
        return ! empty($method->status_pending) ? (string) $method->status_pending : 'P';
    }

    public function statusSuccess($method): string
    {
        return ! empty($method->status_success) ? (string) $method->status_success : 'C';
    }

    public function statusCanceled($method): string
    {
        return ! empty($method->status_canceled) ? (string) $method->status_canceled : 'X';
    }

    /**
     * What the order costs now, in the currency the buyer pays in: the
     * smallest unit and the code, `[1250, 'EUR']`.
     *
     * @return array{0: int, 1: string}
     */
    public function due($bt, $method): array
    {
        $currencyId = ! empty($bt->payment_currency_id) ? (int) $bt->payment_currency_id : $this->paymentCurrencyId($method);
        $currency = $this->currencyCode($currencyId);
        $value = vmPSPlugin::getAmountValueInCurrency($bt->order_total, $currencyId);

        return [Currencies::minor((float) $value, $currency), $currency];
    }

    /**
     * This store among others on the same Orboni project: in the idempotency
     * key and the session's metadata, so two stores' order 42 never meet.
     * Made from the site's own secret, the same whatever address it is
     * reached at.
     */
    public function siteKey(): string
    {
        return substr(hash_hmac('sha256', 'orboni-virtuemart-site', (string) Factory::getApplication()->get('secret')), 0, 12);
    }

    public function log(string $message, bool $error = false)
    {
        if (! $error && ! $this->debug) {
            return;
        }
        if (! self::$logger) {
            Log::addLogger(['text_file' => 'plg_vmpayment_orboni.php'], Log::ALL, [self::LOG_CATEGORY]);
            self::$logger = true;
        }
        Log::add($message, $error ? Log::ERROR : Log::INFO, self::LOG_CATEGORY);
    }

    private function paymentCurrencyId($method): int
    {
        $copy = clone $method;
        static::getPaymentCurrency($copy);

        return (int) $copy->payment_currency;
    }

    private function currencyCode(int $currencyId): string
    {
        return strtoupper((string) shopFunctions::getCurrencyByID($currencyId, 'currency_code_3'));
    }

    private function returnUrl($method, $bt, string $token): string
    {
        return Uri::root().'index.php?option=com_virtuemart&view=vmplg&task=pluginresponsereceived'
            .'&on='.rawurlencode((string) $bt->order_number)
            .'&pm='.(int) $method->virtuemart_paymentmethod_id
            .'&key='.rawurlencode($token)
            .$this->linkTail();
    }

    /** The buyer's menu item and language, so they come back to the store as they left it. */
    private function linkTail(): string
    {
        $tail = '';
        $itemId = vRequest::getInt('Itemid', 0);
        if ($itemId > 0) {
            $tail .= '&Itemid='.$itemId;
        }
        $lang = vRequest::getCmd('lang', '');
        if ($lang !== '') {
            $tail .= '&lang='.$lang;
        }

        return $tail;
    }

    private function storeName($bt): string
    {
        $vendor = VmModel::getModel('vendor')->getVendor((int) $bt->virtuemart_vendor_id);
        $name = is_object($vendor) && ! empty($vendor->vendor_store_name) ? (string) $vendor->vendor_store_name : '';

        return $name !== '' ? $name : (string) Factory::getApplication()->get('sitename');
    }

    /**
     * The store, the order, the amount and the try. The order's own password
     * is in it too: a store restored from a backup numbers its orders again,
     * and Orboni keeps a key a day.
     */
    private function idempotencyKey($bt, int $amount, string $currency, int $attempt): string
    {
        $instance = substr(hash('sha256', (string) $bt->order_pass), 0, 8);

        return sprintf('vm-%s-%d-%s-%d%s-%d', $this->siteKey(), (int) $bt->virtuemart_order_id, $instance, $amount, strtolower($currency), $attempt);
    }

    /**
     * A session the buyer can still pay on: open, with its page, and more
     * than REUSE_MIN_SECONDS before it runs out.
     *
     * @param  array<string, mixed>  $session
     */
    private static function reusable(array $session): bool
    {
        return isset($session['status'], $session['url']) && $session['status'] === 'open' && is_string($session['url'])
            && (isset($session['expires_at']) ? (int) $session['expires_at'] : 0) > time() + self::REUSE_MIN_SECONDS;
    }

    /**
     * "Blue mug × 2, Tea" — what the payment page shows as the purchase.
     *
     * @param  array<string, mixed>  $order
     */
    private static function items(array $order): string
    {
        $names = [];
        foreach (isset($order['items']) ? (array) $order['items'] : [] as $item) {
            $name = trim(strip_tags((string) $item->order_item_name));
            $quantity = (int) $item->product_quantity;
            if ($name !== '') {
                $names[] = $quantity > 1 ? $name.' × '.$quantity : $name;
            }
        }

        return implode(', ', $names);
    }

    /** At most `$max` characters, cut at a word with an ellipsis. */
    private static function fit(string $text, int $max): string
    {
        $text = trim((string) preg_replace('/\s+/u', ' ', $text));
        if (mb_strlen($text) <= $max) {
            return $text;
        }
        $cut = mb_substr($text, 0, $max - 1);
        $space = mb_strrpos($cut, ' ');
        if ($space !== false && $space > $max / 2) {
            $cut = mb_substr($cut, 0, $space);
        }

        return rtrim($cut, ' ,.;:').'…';
    }

    private function answer(int $code, string $result)
    {
        http_response_code($code);
        header('Content-Type: application/json');
        header('Cache-Control: no-store');
        echo json_encode(['result' => $result]);
        Factory::getApplication()->close();
    }
}
