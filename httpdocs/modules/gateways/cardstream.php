<?php
// *************************************************************************
// *                                                                       *
// * CardStream - WHMCS Direct integration                                 *
// * Release Date: 15th October 2013                                       *
// * Version 1.0.0                                                         *
// *                                                                       *
// *************************************************************************
// *                                                                       *
// * Email: paul.lashbrook@cardstream.com                                  *
// * Website: http://www.cardstream.com                                    *
// *                                                                       *
// *************************************************************************

function cardstream_config()
{
    //Configuration options.
    $configarray = array(
        "FriendlyName" => array("Type" => "System", "Value" => "CardStream"),
        "merchantid" => array("FriendlyName" => "Merchant ID", "Type" => "text", "Size" => "20",),
        "currencycode" => array("FriendlyName" => "Currency Code", "Type" => "text", "Size" => "20",),
        "countrycode" => array("FriendlyName" => "Country Code", "Type" => "text", "Size" => "20",),
        "merchantPwd" => array("FriendlyName" => "Merchant Password", "Type" => "text", "Size" => "20",),
        "merchantPassphrase" => array("FriendlyName" => "Merchant Passphrase", "Type" => "text", "Size" => "100",)
    );
    return $configarray;
}

function cardstream_capture($params)
{

    if ($params['merchantid'] && isset($params['merchantPwd']) && isset($params['merchantPassphrase']) && is_numeric($params['currencycode']) && is_numeric($params['countrycode'])) {
        //Module correctly configured.

        //Calculate and format the amount to minor from major
        $amount = (int)round($params['amount'] * 100);

        //Build the correct address
        if (!$params['clientdetails']['address2']) {
            $formattedstreetaddress = $params['clientdetails']['address1'];
        } else {
            $formattedstreetaddress = $params['clientdetails']['address1'] . "," . $params['clientdetails']['address2'];
        }

        if (isset($params['clientdetails']['city'])) {
            $formattedstreetaddress .= "\n{$params['clientdetails']['city']}";
        }

        if (isset($params['clientdetails']['state'])) {
            $formattedstreetaddress .= "\n{$params['clientdetails']['state']}";
        }


        //Assign data to form fields
        $orderdata = array(
            "merchantID" => $params['merchantid'],
            "cardNumber" => $params['cardnum'],
            "cardCVV" => $params['cccvv'],
            "countryCode" => $params['countrycode'],
            "currencyCode" => $params['currencycode'],
            "amount" => $amount,
            "transactionUnique" => $params['invoiceid'],
            "orderRef" => $params['description'],
            "customerAddress" => $formattedstreetaddress,
            "customerPostCode" => $params['clientdetails']['postcode'],
            "customerEmail" => $params['clientdetails']['email'],
            "customerPhone" => $params['clientdetails']['phonenumber'],
            "threeDSRequired" => 'N',
            "merchantData" => 'WHMCS-Direct-1'

        );

        // If we have card expiry date then assign form fields.
        if (($params['cardexp']) && (is_numeric($params['cardexp']))) {
            $orderdata['cardExpiryMonth'] = substr($params['cardexp'], 0, 2);
            $orderdata['cardExpiryYear'] = substr($params['cardexp'], 2, 4);
        }

        // Construct name
        if (($params['clientdetails']['firstname']) && (!$params['clientdetails']['lastname'])) {
            //Only Firstname
            $orderdata['customerName'] = $params['clientdetails']['firstname'];
        } elseif ((!$params['clientdetails']['firstname']) && ($params['clientdetails']['lastname'])) {
            //Only Lastname
            $orderdata['customerName'] = $params['clientdetails']['lastname'];
        } else {
            // Firstname & Lastname
            $orderdata['customerName'] = $params['clientdetails']['firstname'] . " " . $params['clientdetails']['lastname'];
        }

        if (isset($params['merchantPassphrase'])) {
            $sig_fields = http_build_query($orderdata) . $params['merchantPassphrase'];
            $orderdata['signature'] = hash('SHA512', $sig_fields);
        }

        $cparams = array(
            'http' => array(
                'method' => 'POST',
                'ignore_errors' => true
            )
        );
        if ($orderdata !== null && !empty($orderdata)) {
            $params = http_build_query($orderdata);
            $cparams["http"]['header'] = 'Content-Type: application/x-www-form-urlencoded';
            $cparams['http']['content'] = $params;

        }

        $context = stream_context_create($cparams);
        $fp = fopen('https://gateway.cardstream.com/direct/', 'rb', false, $context);
        if (!$fp) {
            $res = false;
        } else {
            $res = stream_get_contents($fp);
            parse_str($res, $res);
        }


        if (isset($res['signature'])) {
            $check = $res;
            unset($check['signature']);
            ksort($check);
            $sig_check = ($res['signature'] == hash("SHA512", http_build_query($check) . $params['merchantPassphrase']));
        }

        // Detect the outcome of the transaction
        if ($res['responseCode'] == 0) {
            //Sucesfull transaction.
            return array("status" => "success", "transid" => $res['xref'], "rawdata" => $res);

        } elseif(isset($sig_check) && !$sig_check){
            //Failed sig check.
            return array("status" => "error", "rawdata" => 'Response signature check miss match');
        } else {
            //Failed transaction.
            return array("status" => "error", "rawdata" => $res);

        }

    } else {
        //Module incorrectly configured.
        return array("status" => "error", "rawdata" => "CardStream module incorrectly configured.");

    }

}
