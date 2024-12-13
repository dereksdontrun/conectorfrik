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

    public $error = 0;

    public function __construct() {

    }

    public function newConectorfrikOrder($params) {
        $order = $params['order'];
        $id_order = $order->id;

        $this->setLog('pedido_'.$id_order);

        $pretty_json_params = json_encode($params, JSON_PRETTY_PRINT);

        file_put_contents($this->log_file, date('Y-m-d H:i:s').' - Contenido $params : '.PHP_EOL, FILE_APPEND);  
        file_put_contents($this->log_file, date('Y-m-d H:i:s').$pretty_json_params.PHP_EOL, FILE_APPEND); 


        return;
    }

    public function setLog($proceso) {

        $this->log_file = $this->log_path.$proceso.'_'.date('Y-m-d H:i:s').'.txt';  

        file_put_contents($this->log_file, date('Y-m-d H:i:s').' - Comienzo proceso '.strtoupper($proceso).PHP_EOL, FILE_APPEND);  

        return;        
    }

}