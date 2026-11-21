# Orboni for VirtueMart

Crypto payments for VirtueMart through [Orboni](https://orboni.com): buyers pay with USDT, USDC, BTC, ETH and other coins on Orboni's payment page, and the money goes straight to your own wallets. Orboni never holds it.

- Joomla 4, 5 or 6 with VirtueMart 4; PHP 7.4 or later.
- Prices in USD, EUR, GBP and 34 other currencies; Orboni turns them into coins at the rate of the day.
- The order is marked paid by a signed webhook, when the buyer comes back to your store, or by a cron address, whichever comes first.

## Install

1. Download `plg_vmpayment_orboni.zip` from the [latest release](https://github.com/orboni/virtuemart-payment/releases/latest).
2. In Joomla, go to **System → Install → Extensions** and upload it. Joomla switches the plugin on by itself; if not, enable **Orboni for VirtueMart** under **System → Manage → Plugins**.
3. In **VirtueMart → Payment Methods**, add a method, name it (for example "Crypto (USDT, USDC, BTC and more)"), choose **Orboni for VirtueMart** as the payment method, set it published, and press **Save**.
4. On its **Configuration** tab, paste your **Secret key** from the Orboni dashboard (Developers → API keys): `sk_test_…` to try it, `sk_live_…` for real payments. Save.
5. In the Orboni dashboard, under **Developers → Webhooks**, add an endpoint with the **Webhook address** the Configuration tab shows, and paste its signing secret (`whsec_…`) into **Webhook signing secret**. Save.
6. Optional but recommended: add the **Cron address** the Configuration tab shows under **System → Scheduled Tasks → New → GET Request**, every 15 minutes. It reads back payments still on their way in case a webhook was lost, and cancels orders whose payment page ran out unpaid.

## How an order moves

- The buyer confirms the order: it is made **Pending** and the buyer goes to Orboni's payment page. The cart is kept until the payment is seen, so a buyer who comes back can pay another way.
- Paid in full and confirmed on the chain: **Confirmed**, and VirtueMart emails the buyer.
- A payment on its way, part paid or held for your review in the Orboni dashboard: a line in the order's history, the order still Pending.
- The payment page ran out with nothing paid: **Cancelled**. Confirming the order again makes a new order and a new payment page.
- Money that paid an order left the chain (a reorganisation): back to **Pending**, with a line saying not to ship.

The three statuses can be changed on the Configuration tab.

## Support

support@orboni.com · [orboni.com/docs/virtuemart](https://orboni.com/docs/virtuemart)

## License

GNU General Public License version 2 or later (see `LICENSE`).
