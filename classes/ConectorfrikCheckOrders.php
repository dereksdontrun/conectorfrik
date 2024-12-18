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

require_once(dirname(__FILE__).'/../../../config/config.inc.php');
require_once(dirname(__FILE__).'/../../../init.php');

//https://lafrikileria.pt/modules/conectorfrik/classes/ConectorfrikCheckOrders.php

//13/12/2024 Se llamará con tarea cron y debe sacar los pedidos en origen que están en estado Completando pedido, buscar su id en destino en la tabla ps_conectorfrik_orders y llamar a la api con dichos ids para comprobar su estado remoto. Cuando estén en enviado se recibirán los datos de shipping, se actualizarán en origen, cambiando a estado enviado y actualizando también la tabla de pedidos del conector.

$check_orders = new ConectorfrikCheckOrders();

Class ConectorfrikCheckOrders
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
    
    //id de pedido en origen
    public $origin_id_order;
    
    //id de pedido en remoto
    public $remote_id_order;

    public $id_conectorfrik_orders;

    //pedidos obtenidos en estado Completando 26, solo ids remotos, para petición api
    public $remote_orders = array();

    //pedidos obtenidos en estado Completando 26, ids locales y remotos
    public $orders = array();

    public $check_order_response;

    public function __construct() {
        //ponemos el timeline de España
        // date_default_timezone_set("Europe/Madrid"); 
        
        if ($this->getOrders() === false) {
            exit;
        }

        $this->setLog('check_orders');

        if (!$this->getWebserviceCredentials()) {
            $this->enviaEmail();            

            //problemas con las credenciales para conectar al webservice del monolito
            return false;
        } 

        if (!$this->checkOrders()) {
            $this->enviaEmail();    
            
            return false;
        } 

        if ($this->error) {
            file_put_contents($this->log_file, date('Y-m-d H:i:s')." - PROCESO FINALIZADO CON ERRORES".PHP_EOL, FILE_APPEND);   

            $this->enviaEmail();
        }

        return true;
    }

    public function checkOrders() {
        //primero llamamos a api con todos los pedidos para sacar la info en conjunto, después recorremos los pedidos procesando cada uno
        if (!$this->webserviceAPICheckorder()) {                
                
            return false;
        } 
        // echo '<br><br>';
        // echo '<pre>';
        // print_r($this->check_order_response);
        // echo '</pre>';

        foreach ($this->orders AS $order) {
            $this->id_conectorfrik_orders = $order['id_conectorfrik_orders'];
            $this->origin_id_order = $order['id_order'];
            $this->remote_id_order = $order['remote_id_order'];

            //buscamos la posición del pedido en el array de respuesta de la api
            $position = array_search($this->remote_id_order, array_column($this->check_order_response['data']['orders'], 'id_order'));

            // sacamos el subarray del pedido con la posición en el array principal
            $remote_order = $position !== false ? $this->check_order_response['data']['orders'][$position] : null;

            if (!$remote_order) {
                file_put_contents($this->log_file, date('Y-m-d H:i:s').' - Pedido origin_id_order '.$this->origin_id_order.' - remote_id_order '.$this->remote_id_order.' no encontrado en respuesta de API check_order'.PHP_EOL, FILE_APPEND); 

                $this->error = 1;
            
                $this->mensajes[] = ' - Pedido origin_id_order '.$this->origin_id_order.' - remote_id_order '.$this->remote_id_order.' no encontrado en respuesta de API check_order';     

                continue;
            }

            echo '<br><br>';
            echo '<pre>';
            print_r($remote_order);
            echo '</pre>';

            if (!$this->checkRemoteOrder($remote_order)) {
                continue;
            }            
        }

        return true;
    }

    //se comprueba la info recibida del pedido remoto, si está en estado cancelado se avisa, si está en enviado se procesa el shipping en origen y se cambia de estado, si está en otro estado, solo log
    public function checkRemoteOrder($remote_order) {
        if ($remote_order['order_status']['id_state'] == 6) {
            file_put_contents($this->log_file, date('Y-m-d H:i:s').' - Pedido origin_id_order '.$this->origin_id_order.' - remote_id_order '.$this->remote_id_order.' se encuentra CANCELADO en remoto'.PHP_EOL, FILE_APPEND); 

            $this->error = 1;
        
            $this->mensajes[] = ' - Pedido origin_id_order '.$this->origin_id_order.' - remote_id_order '.$this->remote_id_order.' se encuentra CANCELADO en remoto'; 

            $info = array($remote_order['order_status']['id_state'], $remote_order['order_status']['state']);

            // $this->updateOrder('error estado', $info);
            echo '<br>updateOrder(error estado';

            return false;
        } elseif ($remote_order['order_status']['id_state'] == 4) {
            file_put_contents($this->log_file, date('Y-m-d H:i:s').' - Pedido origin_id_order '.$this->origin_id_order.' - remote_id_order '.$this->remote_id_order.' se encuentra en estado ENVIADO id_status = '.$remote_order['order_status']['id_state'].PHP_EOL, FILE_APPEND);
            
            $date_sent = $remote_order['shipping']['date_sent'];
            $carrier = $remote_order['shipping']['carrier'];
            $tracking = $remote_order['shipping']['tracking'];
            // $url = $remote_order['shipping']['url']; 

            if (!$date_sent || !$carrier || !$tracking) {
                file_put_contents($this->log_file, date('Y-m-d H:i:s').' - Pedido origin_id_order '.$this->origin_id_order.' - remote_id_order '.$this->remote_id_order.' está enviado pero recibió datos de shipping incompletos'.PHP_EOL, FILE_APPEND); 
                file_put_contents($this->log_file, date('Y-m-d H:i:s').' - Order shipping : '.json_encode($remote_order['shipping'], JSON_PRETTY_PRINT).PHP_EOL, FILE_APPEND); 

                $this->error = 1;
            
                $this->mensajes[] = ' - Pedido origin_id_order '.$this->origin_id_order.' - remote_id_order '.$this->remote_id_order.' está enviado pero recibió datos de shipping incompletos';
                $this->mensajes[] = ' - Order shipping : '.json_encode($remote_order['shipping'], JSON_PRETTY_PRINT);

                // $this->updateOrder('error shipping');
                echo '<br>updateOrder(error shipping';

                return false;
            }

            //tenemos los datos de shipping, actualizamos dichos datos y pasamos el pedido a enviado
            //OJO si han cambiado el transportista en remoto no nos dará error, metermos el tracking a MRW           
            $sql_order_carrier = "SELECT id_order_carrier FROM ps_order_carrier WHERE id_order = ".$this->origin_id_order;
            $id_order_carrier = Db::getInstance()->getValue($sql_order_carrier);
            $order_carrier = new OrderCarrier($id_order_carrier);
            $order_carrier->tracking_number = $tracking;         
            $order_carrier->update();

            $order = new Order($this->origin_id_order);
            $order->setCurrentState(Configuration::get('PS_OS_SHIPPING'));

            unset($order_carrier);

            //generamos un mensaje en el pedido con el id remoto y datos de envío recibidos
            $this->orderMessage($order, $remote_order['shipping']);

            unset($order);

            $info = array($date_sent, $carrier, $tracking);            

            $this->updateOrder('enviado', $info);
            echo '<br>updateOrder(enviado';

            file_put_contents($this->log_file, date('Y-m-d H:i:s').' - Pedido origin_id_order '.$this->origin_id_order.' - remote_id_order '.$this->remote_id_order.' cambiado a estado Enviado'.PHP_EOL, FILE_APPEND); 

            return true;
            
        } else {
            file_put_contents($this->log_file, date('Y-m-d H:i:s').' - Pedido origin_id_order '.$this->origin_id_order.' - remote_id_order '.$this->remote_id_order.' se encuentra en estado '.strtoupper($remote_order['order_status']['state']).' id_status = '.$remote_order['order_status']['id_state'].PHP_EOL, FILE_APPEND); 

            // $info = array($remote_order['order_status']['id_state'], $remote_order['order_status']['state']);

            //no vamos a hacer updates si no ha cambiado de estado a enviado o cancelado
            // $this->updateOrder('otro estado', $info);
            echo '<br>otro estado';

            return true;
        }
    }

    public function updateOrder($proceso, $info = null) {
        if ($proceso == 'error estado') {
            //el pedido en remoto está cancelado
            $update = " error = 1,
                cancelado = 1,
                date_error = NOW(),
                error_message = CONCAT(error_message, ' - Estado de pedido remoto erroneo '".$info[1]."' - ', DATE_FORMAT(NOW(),'%d-%m-%Y %H:%i:%s')),
                remote_order_status = ".$info[0].", "; 

        } elseif ($proceso == 'error shipping') {
            //el pedido en remoto está enviado pero no hay datos de shipping completos
            $update = " error = 1,
                date_error = NOW(),
                error_message = CONCAT(error_message, ' - Estado de pedido remoto Enviado pero sin datos shipping - ', DATE_FORMAT(NOW(),'%d-%m-%Y %H:%i:%s')), "; 

        // } elseif ($proceso == 'otro estado') {
        //     //el pedido en remoto no está enviado ni cancelado             
        //     $update = " 
        //         error_message = CONCAT(error_message, ' - Estado de pedido remoto '".$info[1]."' - ', DATE_FORMAT(NOW(),'%d-%m-%Y %H:%i:%s')),
        //         remote_order_status = ".$info[0].", ";     

        } elseif ($proceso == 'enviado') {
            //pedido está enviado y hemos actualizado tracking y estado en origen             
            $update = " error_message = CONCAT(error_message, ' - Pedido remoto enviado - ', DATE_FORMAT(NOW(),'%d-%m-%Y %H:%i:%s')),
                remote_shipped = 1,
                date_remote_shipped = '".$info[0]."', 
                date_shipping_confirmed = NOW(),
                tracking_number = '".$info[2]."', 
                carrier_name = '".$info[1]."',
                remote_order_status = 4,  ";     

        } 

        $update_conectorfrik_orders = "UPDATE ps_conectorfrik_orders 
        SET 
        $update        
        date_upd = NOW()        
        WHERE id_conectorfrik_orders = ".$this->id_conectorfrik_orders;

        if (!Db::getInstance()->execute($update_conectorfrik_orders)) {
            file_put_contents($this->log_file, date('Y-m-d H:i:s').' - ERROR haciendo update - "'.$proceso.'" - para pedido origen '.$this->origin_id_order.' en tabla ps_conectorfrik_orders'.PHP_EOL, FILE_APPEND); 
    
            $this->error = 1;
        
            $this->mensajes[] = ' - ERROR haciendo update - "'.$proceso.'" - para pedido origen '.$this->origin_id_order.' en tabla ps_conectorfrik_orders';
        }

        return;
    }

    //función para añadir un mensaje al pedido. Tenemos employee automatizador, id 4
    public function orderMessage($order, $shipping) {    
        $id_employee = 4;
        $id_lang = 4; //portugues¿?

        $url = str_replace("@", $shipping['tracking'], $shipping['url']);
        
        $mensaje_pedido = 'Pedido Enviado en lafrikileria.com '.$shipping['date_sent'].'
            Id pedido remoto: '.$this->remote_id_order.'
            Transporte: '.$shipping['carrier'].'
            Tracking: '.$shipping['tracking'].'
            Url: '.$url.'
            '.date("d-m-Y H:i:s");

        $customer = new Customer($order->id_customer);

        //si existe ya un customer_thread para este pedido lo sacamos
        $id_customer_thread = CustomerThread::getIdCustomerThreadByEmailAndIdOrder($customer->email, $order->id);            

        if ($id_customer_thread) {
            //si ya existiera lo instanciamos para tener los datos para el mensaje y el envío de email
            $ct = new CustomerThread($id_customer_thread);
        } else {
            //si no existe lo creamos
            $ct = new CustomerThread();
            $ct->id_shop = 1; 
            $ct->id_lang = $id_lang; 
            $ct->id_contact = 0; 
            $ct->id_customer = $order->id_customer;
            $ct->id_order = $order->id;
            //$ct->id_product = 0;
            $ct->status = 'open';
            $ct->email = $customer->email;
            $ct->token = Tools::passwdGen(12);  // hay que generar un token para el hilo
            $ct->add();
        }           
        
        //si hay id de customer_thread continuamos
        if ($ct->id){            
            $cm_interno = new CustomerMessage();
            $cm_interno->id_customer_thread = $ct->id;
            $cm_interno->id_employee = $id_employee; 
            $cm_interno->message = $mensaje_pedido;
            $cm_interno->private = 1;                
            $cm_interno->add();
        } 

        //metemos también el mensaje interno resaltado si se recibió como true, que va asignado al carrito también.
        // if ($resaltado) {
            // $mensaje_carro = new Message();
            // $mensaje_carro->id_cart = $order->id_cart;
            // $mensaje_carro->id_customer = $order->id_customer;
            // $mensaje_carro->id_order = $order->id;
            // $mensaje_carro->id_employee = $id_employee; 
            // $mensaje_carro->message = $mensaje_pedido;
            // $mensaje_carro->private = 1;                
            // $mensaje_carro->add();
        // }        

        return;
    }

    public function webserviceAPICheckorder() {        

        file_put_contents($this->log_file, date('Y-m-d H:i:s').' - Preparando llamada a API Webservice para estado y shipping de pedidos, check_order json:'.PHP_EOL, FILE_APPEND); 
        file_put_contents($this->log_file, date('Y-m-d H:i:s').' - '.json_encode($this->remote_orders).PHP_EOL, FILE_APPEND);          

        $this->check_order_info = array();

        //preparamos POSTFIELDS
        $parameters = array(
            "user" => $this->webservice_credentials['user'],
            "user_pwd" => $this->webservice_credentials['user_pwd'],
            "function" => "check_order",
            "orders" => json_encode(
                array(
                    "order_ids" => $this->remote_orders
                )
            ) 
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

            $error_message = 'ERROR API Webservice Prestashop para petición check_order - Excepción:'.$exception.' - Exception thrown in '.$file.' on line '.$line.': [Code '.$code.']';

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
                file_put_contents($this->log_file, date('Y-m-d H:i:s').' - Atención, la respuesta de la API Webservice Prestashop para petición check_order NO ES CORRECTA'.PHP_EOL, FILE_APPEND);
                file_put_contents($this->log_file, date('Y-m-d H:i:s').' - Http Response Code = '.$http_code.PHP_EOL, FILE_APPEND); 

                $this->error = 1;
                
                $this->mensajes[] = 'Atención, la respuesta de la API Webservice Prestashop para petición check_order NO ES CORRECTA'; 
                $this->mensajes[] = ' - check_order json: '.json_encode($this->remote_orders); 
                $this->mensajes[] = ' - Http Response Code = '.$http_code;

                return false;
            }

            if ($response_decode['success'] == false) {
                file_put_contents($this->log_file, date('Y-m-d H:i:s').' - Atención, la respuesta de la API Webservice Prestashop para petición check_order no tuvo éxito'.PHP_EOL, FILE_APPEND);
                file_put_contents($this->log_file, date('Y-m-d H:i:s').' - Http Response Code = '.$http_code.PHP_EOL, FILE_APPEND); 
                file_put_contents($this->log_file, date('Y-m-d H:i:s').' - API Response Code = '.$response_decode['code'].PHP_EOL, FILE_APPEND);
                file_put_contents($this->log_file, date('Y-m-d H:i:s').' - response json = '.$response.PHP_EOL, FILE_APPEND); 

                $this->error = 1;
                
                $this->mensajes[] = 'Atención, la respuesta de la API Webservice Prestashop para petición check_order no tuvo éxito'; 
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
                //la petición ha sido un éxito, recogemos la información devuelta 
                file_put_contents($this->log_file, date('Y-m-d H:i:s').' - Respuesta CORRECTA de la API Webservice Prestashop para petición check_order'.PHP_EOL, FILE_APPEND);
                file_put_contents($this->log_file, date('Y-m-d H:i:s').' - Http Response Code = '.$http_code.PHP_EOL, FILE_APPEND);
                file_put_contents($this->log_file, date('Y-m-d H:i:s').' - API Response Code = '.$response_decode['code'].PHP_EOL, FILE_APPEND);
                if ($response_decode['messages']) {
                    foreach ($response_decode['messages'] AS $message) {
                        file_put_contents($this->log_file, date('Y-m-d H:i:s').' - Message: '.$message.PHP_EOL, FILE_APPEND);                         
                    }                    
                }
                file_put_contents($this->log_file, date('Y-m-d H:i:s').' - Recibida información de '.$response_decode['data']['count'].' pedidos'.PHP_EOL, FILE_APPEND);

                $this->check_order_response = $response_decode;
                
                return true;   
            }  

        } else {
            //la API parece que no devuelve nada
            $http_code = (int)curl_getinfo($curl, CURLINFO_HTTP_CODE); 

            curl_close($curl);

            file_put_contents($this->log_file, date('Y-m-d H:i:s').' - Atención, respuesta sin response de la API Webservice Prestashop para petición check_order'.PHP_EOL, FILE_APPEND);
            file_put_contents($this->log_file, date('Y-m-d H:i:s').' - Http Response Code = '.$http_code.PHP_EOL, FILE_APPEND);

            $this->error = 1;
                
            $this->mensajes[] = 'Atención, respuesta sin response de la API Webservice Prestashop para petición check_order'; 
            $this->mensajes[] = 'Http Response Code = '.$http_code;                           

            return false;
        }
    }

    public function getOrders() {       

        $sql_select_orders = "SELECT coo.id_conectorfrik_orders, ord.id_order, coo.remote_id_order
            FROM ps_orders ord
            JOIN ps_conectorfrik_orders coo ON coo.origin_id_order = ord.id_order
            WHERE ord.current_state = 26 
            AND error = 0";
        $this->orders = Db::getInstance()->executeS($sql_select_orders);       
        
        if (count($this->orders) < 1) {
            return false;
        }

        foreach ($this->orders AS $order) {
            $this->remote_orders[] = $order['remote_id_order'];
        }

        return true;
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
        file_put_contents($this->log_file, date('Y-m-d H:i:s').' - Pedidos en origen en estado Completando Pedido = '.count($this->orders).PHP_EOL, FILE_APPEND);  

        return;        
    }





    public function enviaEmail() {
        $mensaje_email = array();

        if (!empty($this->mensajes)) {
            $mensaje_email = $this->mensajes;
        }

        $asunto = 'ERROR proceso CHECK_ORDER de PEDIDOS de '.strtoupper($this->origen).' '.date("Y-m-d H:i:s");
        $cuentas = 'sergio@lafrikileria.com';        

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