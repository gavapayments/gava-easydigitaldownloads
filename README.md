# Gava Easy Digital Downloads

[Easy Digital Downloads](https://easydigitaldownloads.com) plugin for Gava.

[Download](https://github.com/gavapayments/gava-easydigitaldownloads/releases) a copy, and install + activate as you would any other WordPress plugin. Activate the gateway from within Easy Digital Downloads' *Setting* - *Payment Gateways* menu.

On the Gava end, a very important step is to then set your `CALLBACK_URL` (in your .env file) to your site's domain with `?gava_callback` appended. So if your site's address is `http://example.com`, you would set CALLBACK_URL to `http://example.com/?gava_callback`.
