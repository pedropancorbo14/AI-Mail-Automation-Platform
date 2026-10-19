#!/bin/sh
# Prepara la tienda WooCommerce de pruebas (idempotente): WordPress instalado,
# WooCommerce activo, enlaces permanentes, pedidos de los clientes de prueba
# (los mismos que usan los emails de ejemplo) y unas claves de API de solo
# lectura fijas (para poder repetir la prueba).
set -eu

CK="ck_aimailpruebas000000000000000000000000001"
CS="cs_aimailpruebas000000000000000000000000001"
WC_VERSION="9.9.5"

# Espera a que WordPress haya copiado sus ficheros y a la base de datos.
for i in $(seq 1 60); do
  [ -f /var/www/html/wp-config.php ] && wp db check >/dev/null 2>&1 && break
  sleep 2
done

if ! wp core is-installed 2>/dev/null; then
  wp core install --url=http://woocommerce --title="Tienda de pruebas" \
    --admin_user=admin --admin_password=admin --admin_email=admin@tienda.test --skip-email
fi
wp rewrite structure '/%postname%/' --hard >/dev/null
wp plugin is-active woocommerce 2>/dev/null \
  || wp plugin install woocommerce --version="$WC_VERSION" --activate
wp option update woocommerce_currency EUR >/dev/null
wp option update woocommerce_default_country ES >/dev/null

# Claves de API de solo lectura (WooCommerce guarda el hash de la consumer key).
wp eval "
global \$wpdb;
\$table = \$wpdb->prefix . 'woocommerce_api_keys';
\$wpdb->delete(\$table, ['description' => 'AI Mail (pruebas)']);
\$ok = \$wpdb->insert(\$table, [
  'user_id' => 1, 'description' => 'AI Mail (pruebas)', 'permissions' => 'read',
  'consumer_key' => wc_api_hash('$CK'), 'consumer_secret' => '$CS', 'truncated_key' => substr('$CK', -7),
]);
if (! \$ok) { WP_CLI::error('No se pudieron crear las claves de API: ' . \$wpdb->last_error); }
"

# Pedidos (solo la primera vez).
if [ "$(wp wc shop_order list --user=admin --format=count)" = "0" ]; then
  SOFA=$(wp wc product create --user=admin --name="Sofá Nórdico" --sku=SOF-1 --regular_price=199 --porcelain)
  COJIN=$(wp wc product create --user=admin --name="Cojín de lino" --sku=COJ-2 --regular_price=23 --porcelain)

  wp wc shop_order create --user=admin --status=completed \
    --billing='{"first_name":"Elena","last_name":"Ruiz","email":"elena@cliente.test","city":"Bilbao","country":"ES"}' \
    --shipping='{"first_name":"Elena","last_name":"Ruiz","city":"Getxo","country":"ES"}' \
    --line_items="[{\"product_id\":$SOFA,\"quantity\":1},{\"product_id\":$COJIN,\"quantity\":2}]" \
    --meta_data='[{"key":"_wc_shipment_tracking_items","value":[{"tracking_provider":"MRW","tracking_number":"MRW555666777","custom_tracking_link":"https://www.mrw.es/","date_shipped":"1760610000"}]}]' \
    --porcelain

  wp wc shop_order create --user=admin --status=processing \
    --billing='{"first_name":"Laura","last_name":"Gómez","email":"laura@cliente.test","city":"Valencia","country":"ES"}' \
    --line_items="[{\"product_id\":$COJIN,\"quantity\":4}]" --porcelain

  wp wc shop_order create --user=admin --status=on-hold \
    --billing='{"first_name":"Marcos","last_name":"Ruiz","email":"marcos@cliente.test","city":"Madrid","country":"ES"}' \
    --line_items="[{\"product_id\":$SOFA,\"quantity\":1}]" --porcelain
fi

echo
echo "Tienda lista: http://woocommerce (red de Docker)"
wp wc shop_order list --user=admin --fields=id,number,status,billing --format=table 2>/dev/null | cut -c1-120 || true
echo "Consumer key:    $CK"
echo "Consumer secret: $CS"
