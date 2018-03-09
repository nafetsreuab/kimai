<?php
/**
 * This file is part of
 * Kimai - Open Source Time Tracking // https://www.kimai.org
 * (c) 2006-2009 Kimai-Development-Team
 *
 * Kimai is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; Version 3, 29 June 2007
 *
 * Kimai is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with Kimai; If not, see <http://www.gnu.org/licenses/>.
 */

include_once '../../includes/basics.php';
require_once 'private_func.php';

$database = Kimai_Registry::getDatabase();

$isCoreProcessor = 0;
$user = checkUser();

if (!isset($_REQUEST['projectID']) || count($_REQUEST['projectID']) == 0) {
    die($kga['lang']['ext_invoice']['noProject']);
}

if (!isset($_REQUEST['invoice_start_day']) || !isset($_REQUEST['invoice_end_day'])) {
    die($kga['lang']['ext_invoice']['noDateSelected']);
}

$dateIn = DateTime::createFromFormat($kga->getDateFormat(3), $_REQUEST['invoice_start_day']);
$dateOut = DateTime::createFromFormat($kga->getDateFormat(3), $_REQUEST['invoice_end_day']);

if ($dateIn === false || $dateOut === false) {
    die($kga['lang']['ext_invoice']['noDateSelected']);
}

$dateIn->setTime(0, 0, 0);
$dateOut->setTime(23, 59, 59);

$in = $dateIn->getTimestamp();
$out = $dateOut->getTimestamp();

$invoiceArray = invoice_get_data($in, $out, $_REQUEST['projectID'], $_REQUEST['filter_cleared'], isset($_REQUEST['short']));

if (count($invoiceArray) == 0) {
    die($kga['lang']['ext_invoice']['noData']);
}

// FETCH ALL KIND OF DATA WE NEED WITHIN THE INVOICE TEMPLATES

$date = time();
$month = $kga['lang']['months'][date('n', $out) - 1];
$year = date('Y', $out);
$projectObjects = [];
foreach ($_REQUEST['projectID'] as $projectID) {
    $projectObjects[] = $database->project_get_data($projectID);
}
$customer = $database->customer_get_data($projectObjects[0]['customerID']);
$customerName = html_entity_decode($customer['name']);
$beginDate = $in;
$endDate = $out;
$invoiceID = $customer['name'] . '-' . date('y', $in) . '-' . date('m', $in);
$today = time();
$dueDate = mktime(0, 0, 0, date('m') + 1, date('d'), date('Y'));

$round = 0;
// do we have to round the time ?
if (isset($_REQUEST['roundValue']) && (float)$_REQUEST['roundValue'] > 0) {
    $round = (float)$_REQUEST['roundValue'];
    $time_index = 0;
    $amount = count($invoiceArray);

    while ($time_index < $amount) {
        if ($invoiceArray[$time_index]['type'] == 'timeSheet') {
            $rounded = ext_invoice_round_value($invoiceArray[$time_index]['hour'], $round / 10);

            // Write a logfile entry for each value that is rounded.
            Kimai_Logger::logfile('Round ' . $invoiceArray[$time_index]['hour'] . ' to ' . $rounded . ' with ' . $round);

            if ($invoiceArray[$time_index]['hour'] == 0) {
                // make sure we do not raise a "divison by zero" - there might be entries with the zero seconds
                $rate = 0;
            } else {
                $rate = ext_invoice_round_value($invoiceArray[$time_index]['amount'] / $invoiceArray[$time_index]['hour'], 0.05);
            }

            $invoiceArray[$time_index]['hour'] = $rounded;
            $invoiceArray[$time_index]['amount'] = $invoiceArray[$time_index]['hour'] * $rate;
        }
        $time_index++;
    }
}
// calculate invoice sums
$ttltime = 0;
$rawTotalTime = 0;
$total = 0;
foreach ($invoiceArray as $value) {
    $total += $value['amount'];
    $ttltime += $value['hour'];
}
$fttltime = Kimai_Format::formatDuration($ttltime * 3600);

// sort invoice entries
if (isset($_REQUEST['sort_invoice'])) {
    switch ($_REQUEST['sort_invoice']) {
        case 'date_asc':
            uasort($invoiceArray, 'ext_invoice_sort_by_date_asc');
            break;
        case 'date_desc':
            uasort($invoiceArray, 'ext_invoice_sort_by_date_desc');
            break;
        case 'name':
            uasort($invoiceArray, 'ext_invoice_sort_by_name');
            break;
    }
}

$vat_rate = $customer['vat'];
if (!is_numeric($vat_rate)) {
    $vat_rate = $kga->getDefaultVat();
}

$vat = $vat_rate * $total / 100;
$gtotal = $total + $vat;

if (isset($_POST['mark_entries_as_cleared']) && $_POST['mark_entries_as_cleared'] == 1) {
    $database->setTimeEntriesAsCleared($invoiceArray);
}

if (isset($_POST['print'])) {
    $baseFolder = dirname(__FILE__) . '/invoices/';
    $tplFilename = $_REQUEST['ivform_file'];

    if (strpos($tplFilename, '/') !== false) {
        // prevent directory traversal
        header('HTTP/1.0 400 Bad Request');
        die;
    }

    // totally unneccessary
    unset($customer['password']);
    unset($customer['passwordResetHash']);

    $model = new Kimai_Invoice_PrintModel();
    $model->setEntries($invoiceArray);
    $model->setAmount($total);
    $model->setVatRate($vat_rate);
    $model->setTotal($gtotal);
    $model->setVat($vat);
    $model->setCustomer($customer);
    $model->setProjects($projectObjects);
    $model->setInvoiceId($invoiceID);

    $model->setBeginDate($beginDate);
    $model->setEndDate($endDate);
    $model->setInvoiceDate(time());
    $model->setDateFormat($kga->getDateFormat(2));
    $model->setCurrencySign($kga->getCurrencySign());
    $model->setCurrencyName($kga->getCurrencyName());
    $model->setDueDate(mktime(0, 0, 0, date("m") + 1, date("d"), date("Y")));

    // ---------------------------------------------------------------------------
    $renderers = [
        'odt' => new Kimai_Invoice_OdtRenderer(),
        'html' => new Kimai_Invoice_HtmlRenderer(),
        'pdf' => new Kimai_Invoice_HtmlToPdfRenderer()
    ];

    /* @var $renderer Kimai_Invoice_AbstractRenderer */
    foreach ($renderers as $rendererType => $renderer) {
        $renderer->setTemplateDir($baseFolder);
        $renderer->setTemplateFile($tplFilename);
        $renderer->setTemporaryDirectory(APPLICATION_PATH . '/temporary');
        try {
            if ($renderer->canRender()) {
                $renderer->setModel($model);
                $renderer->render();
                return;
            }
        } catch (Exception $ex) {
            die(sprintf($kga['lang']['ext_invoice']['failure'], $ex->getMessage()));
        }
    }

    // no renderer could be found
    die('Template does not exist or is incompatible: ' . $baseFolder . $tplFilename);

} elseif (isset($_POST['vTiger'])) {
    try {
        $client = new Salaros\Vtiger\VTWSCLib\WSClient('http://demo7.vtexperts.com/vtigercrm7demo/', 'demo', 'yhGaR9dENYrJGj6v');

        $projectID = reset($_REQUEST['projectID']);
        $accountId = '11x' . $projectID;
        $product_1_id = '14x1331';
        $service_1_id = '25x7606';
        $CRM_user_id = '19x1';

        $firstTimeEntry = reset($invoiceArray);

        $valuemap = [
            'elementType' => 'Invoice',

            'assigned_user_id' => $CRM_user_id,
            'subject' => (new DateTime())->format('m/y'),
            'currency_id' => '21x1',
            'bill_street' => 'strasse 1',
            'ship_street' => 'strasse 1',
            'description' => 'Beratungseinheit 100',
            'duedate' => '2018-11-06',
            'enable_recurring' => '0',
            'end_period' => null,
            'payment_duration' => null,
            'potential_id' => null,
            'invoicedate' => (new DateTime())->format('Y-m-d'),
            'cf_797' => strftime('%Y-%m-%d', $firstTimeEntry['timestamp']),
            'account_id' => $accountId,
            'invoicestatus' => 'Created',
            'productid' => $product_1_id,
            'discount_type_final' => 'zero',  //  zero/amount/percentage
            'shipping_handling_charge' => 0,
            'shtax1' => 1,   // apply this tax, MUST exist in the application with this internal taxname
            'adjustmentType' => 'add',  //  none/add/deduct
            'hdnTaxType' => 'group', // group or individual  taxes are obtained from the application
        ];
        $counter = 1;
        foreach ($invoiceArray as $entry) {
            /*
            Array (
                [type] => timeSheet
                [desc] => testen
                [start] => 1520579700
                [end] => 1520594100
                [hour] => 4
                [fDuration] => 4:00
                [duration] => 14400
                [timestamp] => 1520579700
                [amount] => 400.00
                [description] => eins
                [rate] => 100.00
                [comment] =>
                [username] => admin
                [useralias] =>
                [location] =>
                [trackingNr] =>
                [projectID] => 1
                [projectName] => TestProjekt
                [projectComment] =>
                [date] => 03/09/2018
            )
            */
            $valuemap['LineItems'][] = [
                'sequence_no' => $counter,
                'productid' => $service_1_id,
                'quantity' => $entry['hour'],
                'listprice' => $entry['rate'],
                'discount_percent' => null,
                'discount_amount' => null,
                'comment' => gmdate('d.m.Y', $entry['start']) . ': ' . $entry['description'],
                'incrementondel' => '0',
                'tax1' => $vat_rate
            ];
            ++$counter;
        }
        $invoice = $client->invokeOperation('create', [
            'elementType' => 'Invoice',
            'element' => json_encode($valuemap)
        ]);
        if ($invoice) {
            echo 'Erfolgreich exportiert';
        }
    } catch (\Salaros\Vtiger\VTWSCLib\WSException $e) {
        die($e->getMessage());
    }
} else {
    die('Invalid action');
}
