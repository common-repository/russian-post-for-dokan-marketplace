<?php
/**
 * New Withdraw request Email.
 *
 * An email sent to the admin when a new withdraw request is created by vendor.
 *
 * @class       Dokan_Vendor_Withdraw_Request
 * @version     2.6.8
 *
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

do_action( 'woocommerce_email_header', $email_heading, $email ); ?>
<p>
    Здравствуйте,
</p>
<p>
    Новый запрос на отмену по заказу - №<?= $data['order_id']?>
</p>
<p>
    Для изменения статуса перейдите в панель управления заказом по <a href="<?=$data['url']?>">ссылке</a>
</p>

<?php

do_action( 'woocommerce_email_footer', $email );
