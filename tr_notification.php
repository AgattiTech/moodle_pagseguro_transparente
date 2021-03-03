<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Listens for Instant Payment Notification from pagseguro
 *
 * This script waits for Payment notification from pagseguro,
 * then double checks that data by sending it back to pagseguro.
 * If pagseguro verifies this then it sets up the enrolment for that
 * user.
 *
 * @package    enrol_pagseguro
 * @copyright  2010 Eugene Venter
 * @copyright  2015 Daniel Neis Araujo <danielneis@gmail.com>
 * @author     Eugene Venter - based on code by others
 * @author     Daniel Neis Araujo based on code by Eugene Venter and others
 * @author     Igor Agatti Lima based on code by Eugene Venter, Daniel Neis Araujo and others
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

// @codingStandardsIgnoreLine
require('../../config.php');
require_once("lib.php");
require_once($CFG->libdir.'/enrollib.php');

header("access-control-allow-origin: https://sandbox.pagseguro.uol.com.br");

define('COMMERCE_PAGSEGURO_STATUS_AWAITING', 1);
define('COMMERCE_PAGSEGURO_STATUS_IN_ANALYSIS', 2);
define('COMMERCE_PAGSEGURO_STATUS_PAID', 3);
define('COMMERCE_PAGSEGURO_STATUS_AVAILABLE', 4);
define('COMMERCE_PAGSEGURO_STATUS_DISPUTED', 5);
define('COMMERCE_PAGSEGURO_STATUS_REFUNDED', 6);
define('COMMERCE_PAGSEGURO_STATUS_CANCELED', 7);
define('COMMERCE_PAGSEGURO_STATUS_DEBITED', 8); // Valor devolvido para o comprador.
define('COMMERCE_PAGSEGURO_STATUS_WITHHELD', 9); // Retenção temporária.
define('COMMERCE_PAYMENT_STATUS_SUCCESS', 'success');
define('COMMERCE_PAYMENT_STATUS_FAILURE', 'failure');
define('COMMERCE_PAYMENT_STATUS_PENDING', 'pending');

$plugin = enrol_get_plugin('pagseguro');
$email = $plugin->get_config('pagsegurobusiness');
$token = $plugin->get_config('pagsegurotoken');

if (get_config('enrol_pagseguro', 'usesandbox') == 1) {
    $baseurl = 'https://ws.sandbox.pagseguro.uol.com.br';
} else {
    $baseurl = 'https://ws.pagseguro.uol.com.br';
}

$notificationcode = optional_param('notificationCode', '', PARAM_RAW);
$notificationtype = optional_param('notificationType', '', PARAM_RAW);

if (!empty($notificationcode) && $notificationtype == 'transaction') {
    pagseguro_transparent_notificationrequest($notificationcode, $email, $token, $baseurl);
}

function pagseguro_transparent_notificationrequest($notificationcode, $email, $token, $baseurl) {

    $url = $baseurl."/v3/transactions/notifications/{$notificationcode}?email={$email}&token={$token}";

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_HEADER, 0);
    curl_setopt($ch, CURLOPT_HTTPHEADER, array("Content-Type: application/x-www-form-urlencoded; charset=ISO-8859-1"));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

    $data = curl_exec($ch);
    curl_close($ch);

    $transaction = simplexml_load_string($data);

    $rec = pagseguro_transparent_handletransactionresponse($transaction);

    pagseguro_transparent_handleenrolment($rec);

}

/**
 * Receives transaction data and updates the database.
 *
 * @param stdClass $data
 *
 * @return string $id the record id of the updated record in the database
 */
function pagseguro_transparent_handletransactionresponse($data) {

    global $DB;

    $rec = new stdClass();
    $rec->id = $data->reference->__toString();
    $rec->code = $data->code->__toString();
    $rec->type = $data->type->__toString();
    $rec->status = intval($data->status->__toString());
    $rec->paymentmethod_type = $data->paymentMethod->type->__toString();
    $rec->paymentmethod_code = $data->paymentMethod->code->__toString();
    $rec->grossamount = number_format($data->grossAmount->__toString(), 2);
    $rec->discountedamount = $data->discountAmount->__toString();

    switch($rec->status){
        case COMMERCE_PAGSEGURO_STATUS_AWAITING:
        case COMMERCE_PAGSEGURO_STATUS_IN_ANALYSIS:
            $rec->payment_status = COMMERCE_PAYMENT_STATUS_PENDING;
            break;
        case COMMERCE_PAGSEGURO_STATUS_PAID:
        case COMMERCE_PAGSEGURO_STATUS_AVAILABLE:
            $rec->payment_status = COMMERCE_PAYMENT_STATUS_SUCCESS;
            break;
        case COMMERCE_PAGSEGURO_STATUS_DISPUTED:
        case COMMERCE_PAGSEGURO_STATUS_REFUNDED:
        case COMMERCE_PAGSEGURO_STATUS_CANCELED:
        case COMMERCE_PAGSEGURO_STATUS_DEBITED:
        case COMMERCE_PAGSEGURO_STATUS_WITHHELD:
            $rec->payment_status = COMMERCE_PAYMENT_STATUS_FAILURE;
            break;

    }

    $DB->update_record("enrol_pagseguro", $rec);

    $record = $DB->get_record("enrol_pagseguro", ['id' => $rec->id]);
    if ($record->payment_status == COMMERCE_PAYMENT_STATUS_SUCCESS) {
        enrol_pagseguro_coursepaidevent($record);
    }

    return $record;

}

/**
 * Enrols or unenrols user depending on the database record.
 *
 * @param stdClass $rec the record in the database
 *
 * @return void
 */
function pagseguro_transparent_handleenrolment($rec) {
    global $DB;

    $plugin = enrol_get_plugin('pagseguro');
    $plugininstance = $DB->get_record('enrol', array('courseid' => $rec->courseid, 'enrol' => 'pagseguro'));

    if ($plugininstance->enrolperiod) {
        $timestart = time();
        $timeend = $timestart + $plugininstance->enrolperiod;
    } else {
        $timestart = 0;
        $timeend   = 0;
    }

    switch ($rec->payment_status) {
        case COMMERCE_PAYMENT_STATUS_SUCCESS:
            $plugin->enrol_user($plugininstance, $rec->userid, $plugininstance->roleid, $timestart, $timeend);
            break;
        case COMMERCE_PAYMENT_STATUS_FAILURE:
            $plugin->unenrol_user($plugininstance, $rec->userid);
            break;
    }

}

/**
 * Triggers payment received event.
 * 
 * @param stdClass $rec (the record in the database for which the payment was received)
 *
 * @return void
 */
function enrol_pagseguro_coursepaidevent($rec) {

    $context = context_course::instance($rec->courseid);

    $data = (array) $rec;

    $param = array(
        'context' => $context,
        'other' => $data,
    );

    $event = \enrol_pagseguro\event\payment_receive::create($param);
    $event->trigger();
}

