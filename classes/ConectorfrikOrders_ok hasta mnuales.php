<?php
/**
* 2007-2024 PrestaShop
*
* NOTICE OF LICENSE
*
* This source file is subject to the Academic Free License (AFL 3.0)
* that is bundled with this package in the file LICENSE.txt.
* It is also available through the world-wide-web at this URL:
* http://opensource.org/licenses/afl-3.0.php
* If you did not receive a copy of the license and are unable to
* obtain it through the world-wide-web, please send an email
* to license@prestashop.com so we can send you a copy immediately.
*
* DISCLAIMER
*
* Do not edit or add to this file if you wish to upgrade PrestaShop to newer
* versions in the future. If you wish to customize PrestaShop for your
* needs please refer to http://www.prestashop.com for more information.
*
*  @author    PrestaShop SA <contact@prestashop.com>
*  @copyright 2007-2024 PrestaShop SA
*  @license   http://opensource.org/licenses/afl-3.0.php  Academic Free License (AFL 3.0)
*  International Registered Trademark & Property of PrestaShop SA
*/

Class ConectorfrikOrders
{
    public $log_path = _PS_ROOT_DIR_.'/modules/conectorfrik/log/';

    public $log_file;

    public $test = false;

    public $error = 0;

    public $mensajes = array();

    public $webservice_credentials;

    public $webservice_order_info;

    public $prestashop_destino_order_info;

    public $origen = 'FrikileriaPT';

    //parámetros recogidos por el HOOK actionValidateOrderFrik en origen
    public $params_order;    
    
    //id de pedido en origen
    public $order_id;    

    //por si la moneda no fuera EUR lo tendremos que calcular, aunque en principio no se permitirá otra moneda
    public $cambio = 1;

    //no debería darse el caso, pero si no obtenemos el iso del idioma de la dirección de entrega, poenmos por defecto el de origen, en este caso Portugal
    public $default_language_iso_code = 'pt';

    public function __construct() {
        //ponemos el timeline de España
        date_default_timezone_set("Europe/Madrid");     
    }

    public function newConectorfrikOrder($params) {
        //los parámetros enviados en el hook que hemos creado solo inlcuyen order instanciado        
        $this->params_order = $params['order'];          

        $this->setLog('pedido_'.$this->params_order->id);

        $pretty_json_params = json_encode($params, JSON_PRETTY_PRINT);

        file_put_contents($this->log_file, date('Y-m-d H:i:s').' - Contenido $params : '.PHP_EOL, FILE_APPEND);  
        file_put_contents($this->log_file, date('Y-m-d H:i:s').$pretty_json_params.PHP_EOL, FILE_APPEND); 

        if (!$this->setOrder()) {
            $this->enviaEmail();

            return false;
        }

        if (!$this->getWebserviceCredentials()) {
            $this->enviaEmail();

            //problemas con las credenciales para conectar al webservice del monolito
            return false;
        } 

        if (!$this->webserviceAPIOrder()) {
            $this->enviaEmail();

            //no se ha podido crear.
            return false;
        } 

        if ($this->error) {
            file_put_contents($this->log_file, date('Y-m-d H:i:s')." - PROCESO FINALIZADO CON ERRORES".PHP_EOL, FILE_APPEND);   

            $this->enviaEmail();
        }

        return true;
    }

    public function setOrder() {   
        
        $this->webservice_order_info = array();

        //origen del pedido
        $this->webservice_order_info['origin'] = $this->origen;              

        //no lleva marketplace ni canal        

        //order
        //fecha de entrada de pedido en origen. Por si viene en UTC (no es el caso en PT) la formateamos a Madrid/Europe. Se trata de coger la fecha en formato "2024-07-03T02:26:51Z", crear un objeto DateTime especificando zona UTC y esa fecha, y después cambiar la time zone a Europe/Madrid, y formatear a 'Y-m-d H:i:s'
        $order_date_origen = $this->params_order->date_add;
        $date = new DateTime($order_date_origen, new DateTimeZone('UTC'));
        $date->setTimezone(new DateTimeZone('Europe/Madrid'));
        $order_date = $date->format('Y-m-d H:i:s');

        $this->webservice_order_info['order'] = array();

        $this->webservice_order_info['order']['order_date'] = $order_date;

        //id_order en origen
        $this->webservice_order_info['order']['external_order_id'] = $this->params_order->id;

        //fecha máxima de envío, no necesita        

        //si el pedido es urgente o no
        //por ahora hasta decidir qué métodos de envío habrá lo ponemos false. Debe se string, no boolean porque se codifica a json
        $this->webservice_order_info['order']['urgent_delivery'] = 'false';

        //language_iso. Habría que poner el iso del país de destino. Aunque al ser nuestra propia tienda a ambos lados lo tendremos controlado, para poder utilizar el mismo código si fuera otra frikileríade otro pais, sacaremos la dirección de entrega con el iso de destino, id_lang, etc. Utilizamos una función para averiguarlo, tanto idioma como country. $isos es un array tipo array($language_iso, $country_iso)
        //sacamos la dirección de entrega   
        $address_delivery = new Address($this->params_order->id_address_delivery);

        $country = new Country($address_delivery->id_country);
        $country_iso_code = $country->iso_code;

        //buscamos el iso en ps_lang, si no  está estableceremos language_iso como default
        if (!Db::getInstance()->getValue('SELECT id_lang FROM ps_lang WHERE iso_code = "'.$country_iso_code.'"')) {
            //no está en tabla idiomas, metemos default
            $language_iso = $this->default_language_iso_code;
        } else {
            //el código es de algún idioma configurado en Prestashop, devolvemos el propio código como idioma
            $language_iso = $country_iso_code;
        }       

        $this->webservice_order_info['order']['language_iso'] = $language_iso;

        //la moneda la sacamos del id_currency del pedido
        $currency = new Currency($this->params_order->id_currency);

        $this->webservice_order_info['order']['currency_iso'] = $currency->iso_code; 
        
        //obtenemos el cambio si la moneda no fuera EUR, deberá estar configurado en la tabla currency y en principio no habrá otra moneda, pero queremos que los precios se envien en euros. Sacamos el valor de order->conversion_rate
        if ($currency->iso_code != 'eur' && $currency->iso_code != 'EUR') {
            $this->cambio = $this->params_order->conversion_rate;
        }

        //Como los productos pueden tener diferente iva hay que comprobar primero las lineas de pedido, sacar el iva de cada producto si hay más de uno, buscándolo en Prestashop y teniendo en cuenta el destino, y así se calcula el precio total. Recorremos las líneas de pedido preparando la info y vamos guardando el coste de los productos con y sin iva. Enviaremos el iva de origen, que junto al iso de país nos permitirá sacar el iva de destino en la api
        
        //para sacar las líneas de pedido lo haremos directamente de OrderDetail y no de product_list en params-order. Usamos getList() static function de OrderDetail        
        $order_lines = OrderDetail::getList($this->params_order->id);

        // echo '<pre>';
        // print_r($order_lines);
        // echo '</pre><br><br>';

        $this->webservice_order_info['order_details'] = array();        
        $total_productos_con_iva = 0;
        $total_productos_sin_iva = 0;
        foreach ($order_lines AS $order_line) {
            $order_detail = array();
            
            //por ahora no metemos, sería el id_order_detail pero no viene en $params
            $order_detail['external_detail_id'] = $order_line['id_order_detail'];
            //enviamos los ids externos de producto
            $order_detail['external_id_product'] = $order_line['product_id'];
            $order_detail['external_id_product_attribute'] = $order_line['product_attribute_id'];
            //sacamos el sku que nos permitirá averiguar los ids de producto en destino
            $order_detail['sku'] = $order_line['product_reference'];           

            //los ids de  producto de destino en este caso no podemos saberlos, no los enviamos
            // $order_detail['id_product'] = $ids_producto[0];
            // $order_detail['id_product_attribute'] = $ids_producto[1];

            $order_detail['quantity'] = $order_line['product_quantity'];

            //por ahora no hay customized ni message
            $order_detail['customized'] = 'false';
            $order_detail['customization_message'] = "";

            //ahora tenemos que obtener el iva del producto que ya tenemos en orderdetail. Ya que a veces no está en su campo tax_rate, si no sacamos el id_tax_rules_group y de ahí el porcentaje. Si ambos son 0, consideramos que el producto se vendió sin impuestos y así lo pasamos
            if ($order_line['tax_rate']) {
                $order_detail['tax_rate'] = $order_line['tax_rate'];
            } elseif ($order_line['id_tax_rules_group']) {
                //no tenemos el valor del impuesto pero si su id_tax_rules_group, sacamos el impuesto para el pais y la regla
                $sql_tax = "SELECT IFNULL(tax.rate, 0)
                FROM ps_tax tax
                JOIN ps_tax_rule tar ON tar.id_tax = tax.id_tax
                JOIN ps_tax_rules_group trg ON trg.id_tax_rules_group = tar.id_tax_rules_group
                WHERE trg.active = 1
                AND tax.active = 1
                AND tar.id_country = ".$address_delivery->id_country."
                AND trg.id_tax_rules_group = ".$order_line['id_tax_rules_group'];

                $order_detail['tax_rate'] = Db::getInstance()->getValue($sql_tax);
            } else {
                //no hay impuesto
                $order_detail['tax_rate'] = 0;
            }            

            //importe de venta con y sin impuestos            
            $order_detail['unit_price_tax_incl'] = $order_line['unit_price_tax_incl']*$this->cambio;
            $order_detail['unit_price_tax_excl'] = $order_line['unit_price_tax_excl']*$this->cambio;
          
            $total_productos_con_iva += $order_detail['unit_price_tax_incl']*$order_detail['quantity'];
            $total_productos_sin_iva += $order_detail['unit_price_tax_excl']*$order_detail['quantity'];

            //el resto de campos de momento no aplican, si se vendió con descuento simplemente tiene otro coste
            $order_detail['discount'] = 'false';
            $order_detail['reduction_type'] = "";
            $order_detail['reduction_percentage'] = "";
            $order_detail['reduction_amount'] = "";
            $order_detail['unit_price_with_reduction_tax_excl'] = "";
            $order_detail['unit_price_with_reduction_tax_incl'] = "";    

            //finalmente metemos el array del order_detail en order_details para webservice
            $this->webservice_order_info['order_details'][] = $order_detail; 
        }        

        //tenemos preparadas las líneas de pedido, seguimos con los datos generales del pedido. Tenemso el total con y sin iva de productos en $total_productos_con_iva y $total_productos_sin_iva
        $this->webservice_order_info['order']['total_products_tax_excl'] = $total_productos_sin_iva;
        $this->webservice_order_info['order']['total_products_tax_incl'] = $total_productos_con_iva;
        //de momento sin descuentos
        $this->webservice_order_info['order']['total_discounts_tax_excl'] = 0;
        $this->webservice_order_info['order']['total_discounts_tax_incl'] = 0;

        //comprobamos si hay costes de shipping. Para el shipping sabemos que aplica el equivalente al 21% de España en el país de destino
        $this->webservice_order_info['order']['total_shipping_tax_incl'] = $this->params_order->total_shipping_tax_incl*$this->cambio;
        $this->webservice_order_info['order']['total_shipping_tax_excl'] = $this->params_order->total_shipping_tax_excl*$this->cambio;  
        
        //en el caso de tener un descuento de transporte gratis, prestashop mete los gastos en el pedido y luego los descuenta, con lo que hay que comprobar si existe dicho descuento sacando los CartRules del pedido y comprobando si incluye free shipping. De haber descuentos generaremos un mensaje con ellos para mostrarlo en el pedido destino        
        $order_cart_rules = $this->params_order->getCartRules();
        if ($order_cart_rules) {            
            $cart_rules_message = "Reglas Descuento:
            ";
            foreach ($order_cart_rules AS $order_cart_rule) {
                $cart_rules_message .= " - ".$order_cart_rule['name'].": ".$order_cart_rule['value']." tax incl
                ";

                if ($order_cart_rule['free_shipping']) {
                    //el pedido tenía envío gratis, ponemos a 0
                    $this->webservice_order_info['order']['total_shipping_tax_incl'] = 0;
                    $this->webservice_order_info['order']['total_shipping_tax_excl'] = 0;
                }
            }

            $this->webservice_order_info['internal_message'] = $cart_rules_message;
        }

        //envoltorio regalo y mensajes
        if ($this->params_order->gift) {
            $this->webservice_order_info['order']['gift_wrapping'] = 'true';
            $this->webservice_order_info['order']['gift_message'] = $this->params_order->gift_message;
            $this->webservice_order_info['order']['total_wrapping_tax_excl'] = $this->params_order->total_wrapping_tax_excl*$this->cambio;
            $this->webservice_order_info['order']['total_wrapping_tax_incl'] = $this->params_order->total_wrapping_tax_incl*$this->cambio;
        } else {
            $this->webservice_order_info['order']['gift_wrapping'] = 'false';
            $this->webservice_order_info['order']['gift_message'] = "";
            $this->webservice_order_info['order']['total_wrapping_tax_excl'] = 0;
            $this->webservice_order_info['order']['total_wrapping_tax_incl'] = 0;
        }        
        
        //para el coste total habría que sumar el coste de productos más el shipping y envoltorio regalo, con y sin impuestos, dado que el total_price que puede incluir diferentes impuestos dependiendo de los productos que contenga el pedido. Comprobamos si las sumas coinciden con los datos recibidos, con un pequeño margen por los cálculos. Es decir, tenemos que sumar $this->webservice_order_info['total_shipping_tax_incl'] + $this->webservice_order_info['total_products_tax_incl'] + $this->webservice_order_info['total_wrapping_tax_incl'] y tendrá que coincidir con $this->params_order['total_paid_tax_incl']*$this->cambio
        $total_paid_tax_excl = $this->webservice_order_info['order']['total_shipping_tax_excl'] + $this->webservice_order_info['order']['total_products_tax_excl'] + $this->webservice_order_info['order']['total_wrapping_tax_excl'];                      
        $total_paid_tax_incl = $this->webservice_order_info['order']['total_shipping_tax_incl'] + $this->webservice_order_info['order']['total_products_tax_incl'] + $this->webservice_order_info['order']['total_wrapping_tax_incl'];

        //ahora comparamos
        //tenemos que multiplicar total_paid_tax_incl de mirakl por el cambio para hacer la operación
        $error_calculos = 0;
        $diferencia = $total_paid_tax_incl - $this->params_order->total_paid_tax_incl*$this->cambio;
        //si la diferencia absoluta entre ambos valores es superior a 50 centimos ¿? consideramos error, si no , metemos lo que hemos calculado nosotros multiplicando por el cambio
        if (ABS($diferencia) > 0.5) {
            file_put_contents($this->log_file, date('Y-m-d H:i:s').' - ERROR detectado en los cálculos de costes del pedido. La diferencia entre el coste total recibido en $params y el coste calculado es superior a 0.50€ ('.ROUND($diferencia, 3).' €) - Interrumpida creación de pedido'.PHP_EOL, FILE_APPEND); 
            file_put_contents($this->log_file, date('Y-m-d H:i:s').' - Volcado de $this->webservice_order_info["order"]: '.print_r($this->webservice_order_info["order"], true).PHP_EOL, FILE_APPEND);
            file_put_contents($this->log_file, date('Y-m-d H:i:s').' - Volcado de $this->webservice_order_info["order_details"]: '.print_r($this->webservice_order_info["order_details"], true).PHP_EOL, FILE_APPEND);

            $this->error = 1;
                
            $this->mensajes[] = '- ERROR detectado en los cálculos de costes del pedido. La diferencia entre el coste total recibido en $params y el coste calculado es superior a 0.50€ ('.ROUND($diferencia, 3).' €) - Interrumpida creación de pedido'; 
            $this->mensajes[] = ' - Volcado de $this->webservice_order_info["order"]: '.print_r($this->webservice_order_info["order"], true);
            $this->mensajes[] = ' - Volcado de $this->webservice_order_info["order_details"]: '.print_r($this->webservice_order_info["order_details"], true);
            
            return false;
        }           

        $this->webservice_order_info['order']['total_paid_tax_excl'] = $this->params_order->total_paid_tax_excl*$this->cambio;                      
        $this->webservice_order_info['order']['total_paid_tax_incl'] = $this->params_order->total_paid_tax_incl*$this->cambio;

        //empezamos con la info de cliente y dirección de destino, que utilizaremos de momento para facturación también, pero esto quizás haya que modificarlo, lo que supondría modificar el webservice también, ya que ahora no admite las dos direcciones
        $this->webservice_order_info['customer'] = array();

        $customer = new Customer($this->params_order->id_customer);

        $this->webservice_order_info['customer']['external_customer_id'] = $customer->id;
        $this->webservice_order_info['customer']['email'] = $customer->email;
        $this->webservice_order_info['customer']['firstname'] = $customer->firstname;
        $this->webservice_order_info['customer']['lastname'] = $customer->lastname;

        
        $this->webservice_order_info['delivery_address'] = array();

        $this->webservice_order_info['delivery_address']['firstname'] = $address_delivery->firstname;
        $this->webservice_order_info['delivery_address']['lastname'] = $address_delivery->lastname;

        //company, puede no venir, y debe tener máximo 64 char para destino, pero en origen admite hasta 256
        $this->webservice_order_info['delivery_address']['company'] = substr($address_delivery->company,0,63); 

        $this->webservice_order_info['delivery_address']['phone'] = $address_delivery->phone;

        $this->webservice_order_info['delivery_address']['phone_mobile'] = $address_delivery->phone_mobile;

        $this->webservice_order_info['delivery_address']['address1'] = $address_delivery->address1;
        $this->webservice_order_info['delivery_address']['address2'] = $address_delivery->address2;

        $this->webservice_order_info['delivery_address']['city'] = $address_delivery->city;

        //la provincia la sacamos por su id en order
        $state = State::getNameById($address_delivery->id_state);
        $this->webservice_order_info['delivery_address']['state'] = $state ? $state : "";

        $this->webservice_order_info['delivery_address']['postcode'] = $address_delivery->postcode;

        $this->webservice_order_info['delivery_address']['country_iso'] = $country_iso_code;

        //meto el contenido si hay de additional_info, auqnue no usaremos de momento
        $this->webservice_order_info['delivery_address']['other'] = $address_delivery->other;

        //tenemos la info que usamos para webservice.   
        return true;
    }

    //función que hace la llamada a la API del webservice de Prestashop para crear el pedido en Prestashop. Tenemos la info en $this->webservice_order_info y las credenciales del webservice de Prestashop en $this->webservice_credentials
    public function webserviceAPIOrder() {
        echo '<br><br>';
        echo '<pre>';
        print_r($this->webservice_order_info);
        echo '</pre>';

        echo '<br>json<br>';
        echo '<pre>';
        print_r(json_encode($this->webservice_order_info) );
        echo '</pre>';

        file_put_contents($this->log_file, date('Y-m-d H:i:s').' - Preparando llamada a API Webservice para creación de pedido origen '.ucfirst($this->webservice_order_info['origin']).' y order_id '.$this->webservice_order_info['order']['external_order_id'].', order_data json:'.PHP_EOL, FILE_APPEND); 
        file_put_contents($this->log_file, date('Y-m-d H:i:s').' - '.json_encode($this->webservice_order_info).PHP_EOL, FILE_APPEND);          

        $this->prestashop_destino_order_info = array();

        //preparamos POSTFIELDS
        $parameters = array(
            "user" => $this->webservice_credentials['user'],
            "user_pwd" => $this->webservice_credentials['user_pwd'],
            "function" => "add_order",
            "order_data" => json_encode($this->webservice_order_info) 
        );
        
        $postfields = http_build_query($parameters);

        if ($this->test) {
            $endpoint = "https://lafrikileria.com/test/api/order?output_format=JSON";
        } else {
            $endpoint = "https://lafrikileria.com/api/order?output_format=JSON";
        }       
       
        $curl = curl_init();

        curl_setopt_array($curl, array(
        CURLOPT_URL => $endpoint,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_ENCODING => '',
        CURLOPT_MAXREDIRS => 10,
        CURLOPT_TIMEOUT => 0,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
        CURLOPT_CUSTOMREQUEST => 'POST',
        CURLOPT_POSTFIELDS => $postfields,
        CURLOPT_HTTPHEADER => array(
            'Content-Type: application/x-www-form-urlencoded',
            'Authorization: Basic '.base64_encode($this->webservice_credentials['webservice_pwd'])           
            ),
        ));

        try {
            //ejecutamos cURL
            $response = curl_exec($curl);
        
            //si ha ocurrido algún error, lo capturamos
            if(curl_errno($curl)){
                throw new Exception(curl_error($curl));
            }
        }
        catch (Exception $e) {    
            $exception = $e->getMessage();
            $file = $e->getFile();
            $line = $e->getLine(); 
            $code = $e->getCode();

            $error_message = 'ERROR API Webservice Prestashop para petición add_order de origen '.ucfirst($this->webservice_order_info['origin']).' y order_id '.$this->webservice_order_info['order']['external_order_id'].' - Excepción:'.$exception.' - Exception thrown in '.$file.' on line '.$line.': [Code '.$code.']';

            file_put_contents($this->log_file, date('Y-m-d H:i:s').' - '.$error_message.PHP_EOL, FILE_APPEND);   

            $this->error = 1;
            
            $this->mensajes[] = ' - '.$error_message;            
            
            return false;            
        }
        
        if ($response) { 
            
            $http_code = (int)curl_getinfo($curl, CURLINFO_HTTP_CODE); 

            curl_close($curl);

            //pasamos el JSON de respuesta a un array PHP. 
            $response_decode = json_decode($response, true);        
            
            // echo '<br><br>';
            // echo '<pre>';
            // print_r($response_decode);
            // echo '</pre>';

            if ($http_code != 200) {
                file_put_contents($this->log_file, date('Y-m-d H:i:s').' - Atención, la respuesta de la API Webservice Prestashop para petición add_order de origen '.ucfirst($this->webservice_order_info['origin']).' y order_id '.$this->webservice_order_info['order']['external_order_id'].' NO ES CORRECTA'.PHP_EOL, FILE_APPEND);
                file_put_contents($this->log_file, date('Y-m-d H:i:s').' - Http Response Code = '.$http_code.PHP_EOL, FILE_APPEND); 

                $this->error = 1;
                
                $this->mensajes[] = 'Atención, la respuesta de la API Webservice Prestashop para petición add_order de origen '.ucfirst($this->webservice_order_info['origin']).' y order_id '.$this->webservice_order_info['order']['external_order_id'].' NO ES CORRECTA'; 
                $this->mensajes[] = ' - order_data json: '.json_encode($this->webservice_order_info); 
                $this->mensajes[] = ' - Http Response Code = '.$http_code;

                return false;
            }

            if ($response_decode['success'] == false) {
                file_put_contents($this->log_file, date('Y-m-d H:i:s').' - Atención, la respuesta de la API Webservice Prestashop para petición add_order de origen '.ucfirst($this->webservice_order_info['origin']).' y order_id '.$this->webservice_order_info['order']['external_order_id'].' no tuvo éxito'.PHP_EOL, FILE_APPEND);
                file_put_contents($this->log_file, date('Y-m-d H:i:s').' - Http Response Code = '.$http_code.PHP_EOL, FILE_APPEND); 
                file_put_contents($this->log_file, date('Y-m-d H:i:s').' - API Response Code = '.$response_decode['code'].PHP_EOL, FILE_APPEND);
                file_put_contents($this->log_file, date('Y-m-d H:i:s').' - response json = '.$response.PHP_EOL, FILE_APPEND); 

                $this->error = 1;
                
                $this->mensajes[] = 'Atención, la respuesta de la API Webservice Prestashop para petición add_order de origen '.ucfirst($this->webservice_order_info['origin']).' y order_id '.$this->webservice_order_info['order']['external_order_id'].' no tuvo éxito'; 
                $this->mensajes[] = 'Http Response Code = '.$http_code;
                $this->mensajes[] = 'API Response Code = '.$response_decode['code'];
                $this->mensajes[] = ' - order_data json: '.json_encode($this->webservice_order_info); 
                $this->mensajes[] = ' - response json = '.$response;

                if ($response_decode['messages']) {
                    foreach ($response_decode['messages'] AS $message) {
                        file_put_contents($this->log_file, date('Y-m-d H:i:s').' - Message: '.$message.PHP_EOL, FILE_APPEND); 

                        $this->mensajes[] = ' - Message: '.$message;
                    }                    
                } else {
                    file_put_contents($this->log_file, date('Y-m-d H:i:s').' - Mensaje error no definido'.PHP_EOL, FILE_APPEND); 

                    $this->mensajes[] = ' - Mensaje error no definido';                    
                }

                return false;
            }

            if ($response_decode['success'] == true) {
                //la creación del pedido ha sido un éxito, recogemos la información devuelta necesaria para actualizar lafrips_mirakl_orders
                file_put_contents($this->log_file, date('Y-m-d H:i:s').' - Respuesta CORRECTA de la API Webservice Prestashop para petición add_order de origen '.ucfirst($this->webservice_order_info['origin']).' y order_id '.$this->webservice_order_info['order']['external_order_id'].PHP_EOL, FILE_APPEND);
                file_put_contents($this->log_file, date('Y-m-d H:i:s').' - Http Response Code = '.$http_code.PHP_EOL, FILE_APPEND);
                file_put_contents($this->log_file, date('Y-m-d H:i:s').' - API Response Code = '.$response_decode['code'].PHP_EOL, FILE_APPEND);
                file_put_contents($this->log_file, date('Y-m-d H:i:s').' - ID_ORDER = '.$response_decode['data']['frikileria_order_id'].PHP_EOL, FILE_APPEND);
                file_put_contents($this->log_file, date('Y-m-d H:i:s').' - ID_WEBSERVICE_ORDER = '.$response_decode['data']['id_webservice_order'].PHP_EOL, FILE_APPEND);
                file_put_contents($this->log_file, date('Y-m-d H:i:s').' - ID_CUSTOMER = '.$response_decode['data']['frikileria_customer_id'].PHP_EOL, FILE_APPEND);
                file_put_contents($this->log_file, date('Y-m-d H:i:s').' - ID_ADDRESS = '.$response_decode['data']['frikileria_address_delivery_id'].PHP_EOL, FILE_APPEND);


                $this->prestashop_destino_order_info['id_order'] = $response_decode['data']['frikileria_order_id'];
                $this->prestashop_destino_order_info['id_webservice_order'] = $response_decode['data']['id_webservice_order'];
                $this->prestashop_destino_order_info['id_customer'] = $response_decode['data']['frikileria_customer_id'];
                $this->prestashop_destino_order_info['id_address_delivery'] = $response_decode['data']['frikileria_address_delivery_id'];

                return true;   
            }  

        } else {
            //la API parece que no devuelve nada
            $http_code = (int)curl_getinfo($curl, CURLINFO_HTTP_CODE); 

            curl_close($curl);

            file_put_contents($this->log_file, date('Y-m-d H:i:s').' - Atención, respuesta sin response de la API Webservice Prestashop para petición add_order de origen '.ucfirst($this->webservice_order_info['origin']).' y order_id '.$this->webservice_order_info['order']['external_order_id'].PHP_EOL, FILE_APPEND);
            file_put_contents($this->log_file, date('Y-m-d H:i:s').' - Http Response Code = '.$http_code.PHP_EOL, FILE_APPEND);

            $this->error = 1;
                
            $this->mensajes[] = 'Atención, respuesta sin response de la API Webservice Prestashop para petición add_order de origen '.ucfirst($this->webservice_order_info['origin']).' y order_id '.$this->webservice_order_info['order']['external_order_id']; 
            $this->mensajes[] = 'Http Response Code = '.$http_code;                           

            return false;
        }
    }

    public function getWebserviceCredentials() {
        //Obtenemos la key leyendo el archivo frikipt_prestashop_webservice_credentials.json donde hemos almacenado user, user_pwd y webservice_pwd
        $secrets_json = file_get_contents(dirname(__FILE__).'/../secrets/frikipt_prestashop_webservice_credentials.json');

        if ($secrets_json == false) {
            file_put_contents($this->log_file, date('Y-m-d H:i:s').' - ERROR obteniendo credenciales para el webservice de Prestashop Monolito, abortando proceso'.PHP_EOL, FILE_APPEND); 

            $this->error = 1;
            
            $this->mensajes[] = ' - ERROR obteniendo credenciales para el webservice de Prestashop, abortando proceso'; 

            return false;
        }        
        
        //almacenamos decodificado como array asociativo (segundo parámetro true, si no sería un objeto)
        $this->webservice_credentials = json_decode($secrets_json, true); 

        file_put_contents($this->log_file, date('Y-m-d H:i:s').' - Credenciales de Webservice obtenidas correctamente'.PHP_EOL, FILE_APPEND); 

        return true;        
    }

    public function setLog($proceso) {

        $this->log_file = $this->log_path.$proceso.'_'.date('Y-m-d H:i:s').'.txt';  

        file_put_contents($this->log_file, date('Y-m-d H:i:s').' - Comienzo proceso '.strtoupper($proceso).PHP_EOL, FILE_APPEND);  

        return;        
    }

    public function enviaEmail($productos_sin_stock = null) {
        $mensaje_email = array();

        if (!empty($this->mensajes)) {
            $mensaje_email = $this->mensajes;
        }

        $asunto = 'ERROR proceso de PEDIDOS de '.strtoupper($this->origen).' '.date("Y-m-d H:i:s");
        $cuentas = 'sergio@lafrikileria.com';

        if ($productos_sin_stock !== null) {
            $cuentas = array('sergio@lafrikileria.com', 'alberto@lafrikileria.com','beatriz@lafrikileria.com');
            $asunto = 'ERROR producto/s sin stock en pedido '.$this->params_order->id.' de '.$this->origen.' - '.date("Y-m-d H:i:s");
            $mensaje_email[] = 'Pedido no confirmado';
            $mensaje_email[] = 'Producto/s sin suficiente stock en el almacén online';
            $mensaje_email[] = $productos_sin_stock;
        }

        if ($productos_sin_stock == null) {
            file_put_contents($this->log_file, date('Y-m-d H:i:s').' - Fin del proceso, dentro de enviaEmail '.PHP_EOL, FILE_APPEND);  
        }

        $info = [];                
        $info['{employee_name}'] = 'Usuario';
        $info['{order_date}'] = date("Y-m-d H:i:s");
        $info['{seller}'] = "";
        $info['{order_data}'] = "";
        $info['{messages}'] = '<pre>'.print_r($mensaje_email, true).'</pre>';
        
        @Mail::Send(
            1,
            'aviso_pedido_webservice', //plantilla
            Mail::l($asunto, 1),
            $info,
            $cuentas,
            'Usuario',
            null,
            null,
            null,
            null,
            _PS_MAIL_DIR_,
            true,
            1
        );
        
        return;
    }

}