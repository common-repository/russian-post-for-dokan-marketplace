function postRf_index(index) {
    post_rf_handle_map_response_client(index);
}

function post_rf_handle_map_response_client(index) {
    jQuery('.shipping_method').each(function () {
        if (jQuery(this).val().indexOf("russian_post") >= 0) {
            set_data(index, jQuery(this).data("index"));
        }
    });
    setTimeout(function () {
        jQuery('body').trigger('update_checkout');
    }, 2000);

}

function set_data(index, id) {
    const address = index.addressTo;
    const city = index.cityTo;
    const region = index.regionTo;
    const zip = index.indexTo;

    var link = jQuery('.opened');
    link.html('Изменить');
    var address_view = jQuery('#address_postrf_' + id);


    var index_back = jQuery('#russian_post_index_' + id);
    var address_back = jQuery('#russian_post_address_' + id);
    var address_city = jQuery('#russian_post_city_' + id);
    var address_region = jQuery('#russian_post_region_' + id);
    var price_field = jQuery('#russian_post_price_' + id);
    var delivery_field = jQuery('#russian_post_delivery_' + id);

    index_back.val(zip);
    address_back.val(address);
    address_city.val(city);
    address_region.val(region);

    var data = {
        action: 'get_russian_post_price',
        zip_to: zip,
        vendor_id: id
    };

    jQuery.post(russian_post_ajax_url.url, data, function (response) {
        price_field.val(response.data.price);
        delivery_field.val(response.data.delivery_time);
        console.log(response);
    });
}

jQuery(document).ready(function ($) {
    init_map();

    $("#rf-point-selector").select2({});

    $('form.checkout').on('change', 'select.shipping_method, input[name^="shipping_method"], #ship-to-different-address input, .update_totals_on_change select, .update_totals_on_change input[type="radio"], .update_totals_on_change input[type="checkbox"]', function () {
        checkExistPostRf();
    });

    $('form.checkout').on('change', '#billing_postcode', function () {
        $('body').trigger('update_checkout');
    });

    $('form.checkout').on('change', '[name=shipping_type]', function () {
        set_methods();
    });

    $('form.checkout').on('change', '#hd-1', function () {
        if (this.checked) {
            $('.woocommerce-shipping-totals').css('display', 'table-row')
        } else {
            $('.woocommerce-shipping-totals').css('display', 'none')
        }
    });

    $('form.checkout').on('change', '#hd-2', function () {
        if (this.checked) {
            $('tr.cart_item').css('display', 'table-row')
        } else {
            $('tr.cart_item').css('display', 'none')
        }
    });
    $('body').on('updated_checkout', function () {
        $('#hd-1').trigger('change');
        document.querySelector('#hd-2').checked = false;
    });


    $(document).on('change', '.cart_totals #hd-1', function () {
        if (this.checked) {
            $('.woocommerce-shipping-totals').css('display', 'table-row')
        } else {
            $('.woocommerce-shipping-totals').css('display', 'none')
        }
    });

    $(document).on('change', '.cart_totals [name=shipping_type]', function () {
        set_methods();
    });

    $(document).on('change', '.cart_totals :input[name^=shipping_method]', function () {
        var options = {
            expires: 365,
            path: '/'
        };
        var type = $('.cart_totals [name=shipping_type]:checked').val();
        $.cookie('type', type, options);
        var shower = $('.cart_totals #hd-1').is(':checked');
        $.cookie('shower', shower, options);
    });

    jQuery('body').on('updated_shipping_method', function () {
        var type = $.cookie('type');
        var $radios = $('input:radio[name=shipping_type]');
        $radios.filter('[value=' + type + ']').prop('checked', true);

        var shower = $.cookie('shower');
        if (shower) {
            $('.cart_totals #hd-1').prop('checked', true);
        }
        $('.cart_totals #hd-1').trigger('change');
    });
    $('[name=shipping_type]').trigger('change');
});

function init_map() {
    if (typeof russian_post_widget != "undefined") {
        let widget_id = russian_post_widget.id;
        let startZip = document.getElementById("billing_postcode").value;
        if (startZip.length < 1) {
            startZip = '125009';
        }
        let widget = "<script>ecomStartWidget({" +
            "id: " + widget_id + "," +
            "callbackFunction: postRf_index," +
            "startZip: " + startZip + "," +
            "containerId: 'ecom-widget'});" +
            "</script>";

        jQuery("#ecom-widget").html(widget);
        checkExistPostRf();
    }
}


function set_methods() {
    var type = jQuery('input[name=shipping_type]:checked').val();
    if (type == 'door') {
        jQuery('.shipping_method').each(function () {
            if (jQuery(this).val() == '3pl_shipping_1') {
                jQuery(this).prop('checked', true)
            }
            if (jQuery(this).val().indexOf("russian_post_courier") >= 0) {
                jQuery(this).prop('checked', true)
            }
        });
    } else if (type == 'point') {
        jQuery('.shipping_method').each(function () {
            if (jQuery(this).val() == '3pl_shipping_2') {
                jQuery(this).prop('checked', true)
            }
            if (jQuery(this).val().indexOf("russian_post") >= 0 && jQuery(this).val().indexOf("russian_post_courier") < 0) {
                jQuery(this).prop('checked', true)
            }
        });
    }
    else{
        jQuery('.shipping_method').each(function () {
            if (jQuery(this).val().indexOf(type) !== -1) {
                jQuery(this).prop('checked', true)
            }
        });
    }
    checkExistPostRf();
    jQuery('body').trigger('update_checkout');
    jQuery(':input[name^=shipping_method]').trigger('change');
}

function checkExistPostRf() {
    var is_allowed = false;
    jQuery('.shipping_method').each(function () {
        if (jQuery(this).is(':checked') || jQuery(this).closest("ul").find("li").length === 1) {
            if (jQuery(this).val().indexOf("russian_post") >= 0 && jQuery(this).val().indexOf("russian_post_courier") < 0) {
                is_allowed = true;
            }
        }
    });

    if (is_allowed) {
        jQuery('#ecom-block').css('display', 'block');
    } else {
        jQuery('#ecom-block').css('display', 'none');
    }
}

