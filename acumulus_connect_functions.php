<?php
/**
 * @noinspection PhpMissingStrictTypesDeclarationInspection
 *   Too tricky to add strict_types=1
 * @noinspection JsonEncodingApiUsageInspection
 * @noinspection CurlSslServerSpoofingInspection
 * @noinspection DuplicatedCode
 *   Remove this noinspection at a later stage and extract those duplicates into
 *   helper functions.
 */

use WHMCS\Database\Capsule;

function acumulus_logException(Throwable $e): void
{
    /**
     * @noinspection PhpStrictComparisonWithOperandsOfDifferentTypesInspection
     *   Returns the exception code as int in Exception but possibly as other
     *   type in Exception descendants (for example as string in PDOException).
     */
    if ($e->getCode() !== 'ACUMULUS') {
        $callingFunction = $e->getTrace()[0]['function'];
        $callingLine = $e->getLine();
        $message = get_class($e);
        if (!empty($e->getCode())) {
            $message .= ' ' . $e->getCode() . ': ';
        }
        $message .= ': ';
        $message .= $e->getMessage();
        $message .= " in $callingFunction:$callingLine";
        logActivity($message);
    }
}

/**
 * Wrapper around the WHMCS loadApi() function that adds some error handling.
 *
 * In case of an error:
 * - an error message is logged.
 * - A runtime exception is thrown.
 *
 * @return array
 *  Array with keys:
 *  - 'result': string: success or error.
 *  - 'message': string: optional, error message in case of error.
 *  - Other keys depend on the API function called, see
 *    {@see https://developers.whmcs.com/api/api-index/}.
 *
 * @throws \RuntimeException
 */
function acumulus_localAPI(string $command, array $values): array
{
    $results = localAPI($command, $values);
    if ($results['result'] !== 'success') {
        $callingFunction = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 2)[1]['function'];
        $mainArg = '';
        if (count($values) >= 1) {
            reset($values);
            $value = current($values);
            $mainArg = is_scalar($value) ? key($values) . ': ' . $value : '...';
        }
        $message = "$callingFunction($mainArg): $command failed: {$results['result']}: {$results['message']}";
        logActivity($message);
        throw new RuntimeException($message, 'ACUMULUS');
    }
    return $results;
}

/**
 * Helper function to load the configuration data stored in the Database.
 */
function acumulus_connect_get_config(): array
{
    // @todo: what if table is empty?
    $configRecords = Capsule::table('tbladdonmodules')->where('module', 'acumulus_connect')->get(['setting', 'value']);
    $config = [];
    foreach ($configRecords as $record) {
        $config[$record->setting] = $record->value;
    }
    // Split composite values into their parts.
    $acumulusDefaultCostCenterParts = explode(' ', $config['acumulus_invoice_default_costcenter'], 2);
    $config['acumulus_invoice_default_costcenterid'] = $acumulusDefaultCostCenterParts[0];
    $config['acumulus_invoice_default_costcentername'] = $acumulusDefaultCostCenterParts[1];
    $acumulusInvoiceTemplateParts = explode(' ', $config['acumulus_invoice_template'], 2);
    $config['acumulus_invoice_templateid'] = $acumulusInvoiceTemplateParts[0];
    $config['acumulus_invoice_templatename'] = $acumulusInvoiceTemplateParts[1];
    // Loop through all account numbers in WHMCS and split them as well.
    foreach (acumulus_connect_getWHMCSAccountNumbers() as $accountNumber) {
        $accountParts = explode(' ', $config['acumulus_AccountNumber_' . $accountNumber['module']], 2);
        $config['account_numbers'][$accountNumber['module']]['id'] = $accountParts[0];
        $config['account_numbers'][$accountNumber['module']]['name'] = $accountParts[1];
    }

    // Global WHMCS Settings.
    $config['TaxType'] = Capsule::table('tblconfiguration')->where('setting', 'TaxType')->value('value');

    return $config;
}

/**
 * WHMCS API Call to retrieve custom fields.
 */
function acumulus_connect_getClientCustomFields(): array
{
    $results = [];
    foreach (Capsule::table('tblcustomfields')->where('type', 'client')->orderBy('fieldname')->get('fieldname') as $record) {
        $results[] = $record->fieldname;
    }
    return $results;
}

/**
 * WHMCS API Call to retrieve client details.
 */
function acumulus_connect_getClient(int $clientId): array
{
    // https://developers.whmcs.com/api-reference/getclientsdetails/
    $command = 'GetClientsDetails';
    $values = ['clientid' => $clientId, 'stats' => false];
    $results = acumulus_localAPI($command, $values);

    return $results['client'];
}

/**
 * Retrieves a local WHMCS invoice.
 *
 * @param bool $expand
 *   Whether to expand the WHMCS invoice with customer data and some custom
 *   values, e.g. line totals.
 */
function acumulus_connect_getInvoice(int $invoiceId, bool $expand = true): array
{
    // https://developers.whmcs.com/api-reference/getinvoice/
    $command = 'GetInvoice';
    $values = ['invoiceid' => $invoiceId];
    $results = acumulus_localAPI($command, $values);

    // Add some custom fields to the invoice.
    if ($expand) {
        $client = acumulus_connect_getClient($results['userid']);
        $results = acumulus_connect_expandInvoiceWithCustomValues($results, $client);
    }
    return $results;
}

/**
 * WHMCS API Call to retrieve the account numbers from WHMCS.
 *
 * @return array[]
 */
function acumulus_connect_getWHMCSAccountNumbers(): array
{
    // https://developers.whmcs.com/api-reference/getpaymentmethods/
    $command = 'GetPaymentMethods';
    $values = [];
    $results = acumulus_localAPI($command, $values);
    return $results['paymentmethods']['paymentmethod'];
}

/**
 * Helper function to retrieve the current WHMCS version.
 */
function acumulus_connect_getWHMCSVersion(): string
{
    return Capsule::table('tblconfiguration')->where('setting', 'Version')->value('value');
}

/**
 * Helper function to Extend the invoice with custom values for tax etc.
 *
 * @noinspection PhpHalsteadMetricInspection
 */
function acumulus_connect_expandInvoiceWithCustomValues(array $invoice, array $client): array
{
    $config = acumulus_connect_get_config();

    // Add some custom tax amounts
    $invoice['custom']['subtotal_taxedItems_exclTax'] = 0.0;
    $invoice['custom']['subtotal_taxedItems_inclTax'] = 0.0;
    $invoice['custom']['subtotal_untaxedItems'] = 0.0;
    $invoice['custom']['total_tax_roundedPerItem'] = 0.0;
    $invoice['custom']['total_tax'] = 0.0;

    $convertedVatTax = acumulus_connect_getVatType($config, $invoice, $client);
    $invoice['custom']['vattype'] = $convertedVatTax['vattype'];
    $invoice['custom']['taxrate'] = $convertedVatTax['taxrate'];
    /** @noinspection TypeUnsafeComparisonInspection (property is by its AcumulusPAI definition an int) */
    if ($invoice['custom']['taxrate'] === '-1' || $invoice['custom']['taxrate'] == '0') {
        $invoice['taxrate'] = '0';
    }

    // @todo: optimize (reverse if and foreach => merge inner else branches,
    //   because the upper one contains errors (setting price incl to 0?);
    //   use "... as &item" instead of accessing $item by $counter.
    $counter = 0;
    if ($config['TaxType'] === 'Exclusive') {
        foreach ($invoice['items']['item'] as $item) {
            /** @noinspection TypeUnsafeComparisonInspection  (property is named like a bool value) */
            if ($item['taxed'] == 1) {
                $invoice['items']['item'][$counter]['custom_tax_unrounded'] = round(((float) $item['amount'] / 100) * (float) $invoice['taxrate'],
                    4);  // (amount / 100) * Tax Rate
                $invoice['items']['item'][$counter]['custom_tax_rounded'] = round(((float) $item['amount'] / 100) * (float) $invoice['taxrate'],
                    2);  // (amount / 100) * Tax Rate
                $invoice['items']['item'][$counter]['custom_price_incl_tax_unrounded'] = round(
                    ($item['amount'] + (((float) $item['amount'] / 100) * (float) $invoice['taxrate'])),
                    4);   // amount + ((amount / 100) * Tax Rate)
                $invoice['items']['item'][$counter]['custom_price_incl_tax_rounded'] = round(
                    ($item['amount'] + round(
                            ((float) $item['amount'] / 100) * (float) $invoice['taxrate'],
                            2
                        )), 2); // amount + ((amount / 100) * Tax Rate)
                $invoice['items']['item'][$counter]['custom_price_excl_tax_unrounded'] = round((float) $item['amount'], 4);
                $invoice['items']['item'][$counter]['custom_price_excl_tax_rounded'] = round((float) $item['amount'], 2);
                $invoice['custom']['subtotal_taxedItems_exclTax'] += (float) $item['amount'];
                $invoice['custom']['subtotal_taxedItems_inclTax'] += round(
                    ($item['amount'] + (((float) $item['amount'] / 100) * (float) $invoice['taxrate'])),
                    4);   // amount + ((amount / 100) * Tax Rate)
                $invoice['custom']['total_tax_roundedPerItem'] += round(((float) $item['amount'] / 100) * (float) $invoice['taxrate'],
                    2);   // amount + ((amount / 100) * Tax Rate)
                $invoice['custom']['total_tax'] += round(((float) $item['amount'] / 100) * (float) $invoice['taxrate'],
                    4);   // amount + ((amount / 100) * Tax Rate)
            } else {
                $invoice['items']['item'][$counter]['custom_tax_unrounded'] = 0.0;
                $invoice['items']['item'][$counter]['custom_tax_rounded'] = 0.0;
                $invoice['items']['item'][$counter]['custom_price_incl_tax_unrounded'] = 0.0;
                $invoice['items']['item'][$counter]['custom_price_incl_tax_rounded'] = 0.0;
                $invoice['items']['item'][$counter]['custom_price_excl_tax_unrounded'] = round((float) $item['amount'], 4);
                $invoice['items']['item'][$counter]['custom_price_excl_tax_rounded'] = round((float) $item['amount'], 2);
                $invoice['custom']['subtotal_untaxedItems'] += (float) $item['amount'];
            }
            $counter++;
        }
    } else {
        // Prices are set inclusive.
        foreach ($invoice['items']['item'] as $item) {
            /** @noinspection TypeUnsafeComparisonInspection property is named as if it is a bool */
            if ($item['taxed'] == 1) {
                $invoice['items']['item'][$counter]['custom_tax_unrounded'] = round(((float) $item['amount'] / (100 + (float) $invoice['taxrate'])) * (float) $invoice['taxrate'],
                    4);    // amount / (100 + Tax Rate)
                $invoice['items']['item'][$counter]['custom_tax_rounded'] = round(((float) $item['amount'] / (100 + (float) $invoice['taxrate'])) * (float) $invoice['taxrate'],
                    2);   // amount / (100 + Tax Rate)
                $invoice['items']['item'][$counter]['custom_price_incl_tax_unrounded'] = round((float) $item['amount'], 4);
                $invoice['items']['item'][$counter]['custom_price_incl_tax_rounded'] = round((float) $item['amount'], 2);
                $invoice['items']['item'][$counter]['custom_price_excl_tax_unrounded'] = round(((float) $item['amount'] / (100 + (float) $invoice['taxrate'])) * 100,
                    4);  // (amount / (100 + Tax Rate)) * 100
                $invoice['items']['item'][$counter]['custom_price_excl_tax_rounded'] = round(((float) $item['amount'] / (100 + (float) $invoice['taxrate'])) * 100,
                    2);  // (amount / (100 + Tax Rate)) * 100
                $invoice['custom']['subtotal_taxedItems_exclTax'] += round(((float) $item['amount'] / (100 + (float) $invoice['taxrate'])) * 100,
                    4);  // (amount / (100 + Tax Rate)) * 100 ;
                $invoice['custom']['subtotal_taxedItems_inclTax'] += round((float) $item['amount'], 4);
                $invoice['custom']['total_tax_roundedPerItem'] += round(((float) $item['amount'] / (100 + (float) $invoice['taxrate'])) * (float) $invoice['taxrate'],
                    2);   // amount / (100 + Tax Rate);
                $invoice['custom']['total_tax'] += round(((float) $item['amount'] / (100 + (float) $invoice['taxrate'])) * (float) $invoice['taxrate'],
                    4);
            } else {
                $invoice['items']['item'][$counter]['custom_tax_unrounded'] = 0.0;
                $invoice['items']['item'][$counter]['custom_tax_rounded'] = 0.0;
                $invoice['items']['item'][$counter]['custom_price_incl_tax_unrounded'] = round((float) $item['amount'], 4);
                $invoice['items']['item'][$counter]['custom_price_incl_tax_rounded'] = round((float) $item['amount'], 2);
                $invoice['items']['item'][$counter]['custom_price_excl_tax_unrounded'] = round((float) $item['amount'], 4);
                $invoice['items']['item'][$counter]['custom_price_excl_tax_rounded'] = round((float) $item['amount'], 2);
                $invoice['custom']['subtotal_untaxedItems'] += (float) $item['amount'];
            }
            $counter++;
        }
    }
    $invoice['custom']['subamountTaxRounded'] = round($invoice['custom']['subamountTax'], 2);

    // Calculate rounding corrections.
    if ($config['acumulus_invoice_correction'] === 'on') {
        $invoice = acumulus_connect_estimateTotals($config, $invoice, $client);
    }

    return $invoice;
}

/**
 * Helper function to replace text with dynamic values.
 *
 * @noinspection PhpFunctionCyclomaticComplexityInspection
 */
function acumulus_connect_replaceVarsInText(?string $text, array $invoice, array $client): string
{
    // @todo: A user got a 'TypeError: Argument 1 passed to
    //   acumulus_connect_replaceVarsInText() must be of the type string, null
    //   given'. As it is unknown which call provoked it, I solved it like this.
    if ($text === null) {
        return '';
    }
    $vars = [
        '{USERID}' => $client['userid'] ?? '',
        '{FIRSTNAME}' => $client['firstname'] ?? '',
        '{LASTNAME}' => $client['lastname'] ?? '',
        '{FULLNAME}' => $client['fullname'] ?? '',
        '{COMPANYNAME}' => $client['companyname'] ?? '',
        '{ADDRESS1}' => $client['address1'] ?? '',
        '{ADDRESS2}' => $client['address2'] ?? '',
        '{CITY}' => $client['city'] ?? '',
        '{STATE}' => $client['state'] ?? '',
        '{POSTCODE}' => $client['postcode'] ?? '',
        '{COUNTRYCODE}' => $client['countrycode'] ?? '',
        '{COUNTRY}' => $client['countryname'] ?? '',
        '{PHONENUMBER}' => $client['phonenumber'] ?? '',
        '{CLIENT_CUSTOMFIELD1}' => $client['customfields1'] ?? '',
        '{CLIENT_CUSTOMFIELD2}' => $client['customfields2'] ?? '',
        '{CLIENT_CUSTOMFIELD3}' => $client['customfields3'] ?? '',
        '{CLIENT_CUSTOMFIELD4}' => $client['customfields4'] ?? '',
        '{CLIENT_CURRENCY}' => $client['currency_code'] ?? '',
        '{INVOICEID}' => $invoice['invoiceid'] ?? '',
        '{INVOICENUMBER}' => !empty($invoice['invoicenum']) ? $invoice['invoicenum'] : $invoice['invoiceid'],
        '{INVOICEDATE}' => $invoice['date'] ?? '',
        '{INVOICEDUE}' => $invoice['duedate'] ?? '',
        '{INVOICENOTES}' => $invoice['notes'] ?? '',
        '{INVOICESTATUS}' => $invoice['status'] ?? '',
    ];

    foreach ($vars as $key => $value) {
        $text = str_ireplace($key, $value, $text);
    }

    return $text;
}

/**
 * Helper function to retrieve the cost centers from Acumulus.
 */
function acumulus_connect_getCostCenters(): array
{
    // Construct the basic xml without email on errors or warnings.
    $xml = acumulus_connect_basicXml(false);
    $xml->addChild('format', 'xml');
    $xml_string = urlencode($xml->asXML());

    // Let's check the credentials against the Acumulus API.
    $url = 'https://api.sielsystems.nl/acumulus/stable/picklists/picklist_costcenters.php';
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, "xmlstring=$xml_string");
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    $response = curl_exec($ch);
    curl_close($ch);

    // Load the xml result.
    $xml = simplexml_load_string($response, null, LIBXML_NOCDATA);
    // Convert XML to json and then convert to Array.
    $costcenters = json_decode(json_encode($xml->costcenters), true);
    // If there are more than 1 cost centers in Acumulus, keep returning the
    // same array construction.
    if (isset($costcenters['costcenter'][1])) {
        $costcenters = $costcenters['costcenter'];
    }

    return $costcenters;
}

/**
 * Helper function to retrieve the bank account numbers from Acumulus.
 */
function acumulus_connect_getAccounts(): array
{
    $xml = acumulus_connect_basicXml(false); //construct the basic xml without email on errors or warnings.
    $xml->addChild('format', 'xml');
    $xml_string = urlencode($xml->asXML());

    // Let's check the credentials against the Acumulus API.
    $url = 'https://api.sielsystems.nl/acumulus/stable/picklists/picklist_accounts.php';
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, "xmlstring=$xml_string");
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    $response = curl_exec($ch);
    curl_close($ch);

    // Load the xml result.
    $xml = simplexml_load_string($response, null, LIBXML_NOCDATA);
    $rawAccounts = $xml->accounts;
    // Convert XML to json and then convert to Array.
    $jsonAccounts = json_encode($rawAccounts);
    $accounts = json_decode($jsonAccounts, true);
    // if there are more than 1 bank account in Acumulus, keep returning the
    // same array construction.
    if (isset($accounts['account'][1])) {
        $accounts = $accounts['account'];
    }

    return $accounts;
}

/**
 * Helper function to retrieve the invoice templates from Acumulus.
 */
function acumulus_connect_getTemplates(): array
{
    // Construct the basic xml without email on errors or warnings.
    $xml = acumulus_connect_basicXml(false);
    $xml->addChild('format', 'xml');
    $xml_string = urlencode($xml->asXML());

    // Let's check the credentials against the Acumulus API.
    $url = 'https://api.sielsystems.nl/acumulus/stable/picklists/picklist_invoicetemplates.php';
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, "xmlstring=$xml_string");
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    $response = curl_exec($ch);
    curl_close($ch);

    // Load the xml result.
    $xml = simplexml_load_string($response, null, LIBXML_NOCDATA);
    $rawTemplates = $xml->invoicetemplates;
    // Convert XML to json and then convert to Array.
    $jsonTemplates = json_encode($rawTemplates);
    $templates = json_decode($jsonTemplates, true);
    // If there are more than 1 template in Acumulus, keep returning the same
    // array construction.
    if (isset($templates['invoicetemplate'][1])) {
        $templates = $templates['invoicetemplate'];
    }

    return $templates;
}

/**
 * Helper function to check if country is an EU member.
 *
 * @todo: replace with Acumulus API call
 */
function acumulus_connect_isCountryInEU(string $countryCode, string $date): bool
{
    // $date is for future use like the brexit
    $eu_countries = [
        'BE',
        'BG',
        'CY',
        'DK',
        'DE',
        'EE',
        'FI',
        'FR',
        'GR',
        'HU',
        'IE',
        'IT',
        'HR',
        'LV',
        'LT',
        'LU',
        'MT',
        'NL',
        'AT',
        'PL',
        'PT',
        'RO',
        'SI',
        'SK',
        'ES',
        'CZ',
        'SE',
        'GB',
        'UK',
    ];
    // Convert string to date.
    /** @noinspection CallableParameterUseCaseInTypeContextInspection */
    $date = strtotime($date);

    // Add countries based on date
    // if ($date >= strtotime('2050-01-15')) {
    //     array_push($eu_countries, 'XX');
    // }

    // Remove countries based on date.
    if ($date >= strtotime('2021-01-01')) {
        $eu_countries = array_diff($eu_countries, ['UK']);
    }
    if ($date >= strtotime('2021-01-01')) {
        $eu_countries = array_diff($eu_countries, ['GB']);
    }

    return in_array($countryCode, $eu_countries);
}

/**
 * Helper function to calculate the vat type and tax rate by country, nature MOSS etc.
 *
 * @noinspection PhpSeparateElseIfInspection
 */
function acumulus_connect_getVatType(array $config, array $invoice, array $client): array
{
    /* Vattypes:
       1 	National 	Gewone nationale factuur 	DEFAULT
       2 	National reverse charge 	Verlegde BTW binnen Nederland
       3 	International reverse charge 	BTW-verlegd naar ondernemer in de EU. Een intracommunautaire levering.
       4 	Export outside EU (export) 	Een factuur voor goederen buiten de EU
       5 	Margin scheme 	Marge regeling voor 2e-hands production
       6 	Foreign VAT 	Buitenlandse BTW voor electronische diensten aan particulieren in de EU. Usage of countrycode mandatory
    */

    if (strtoupper($client['countrycode']) === 'NL') {
        // Invoice is National.
        $vatType = '1';
        $taxRate = $invoice['taxrate'];
    } elseif (acumulus_connect_isCountryInEU(strtoupper($client['countrycode']), $invoice['date'])) {
        // Invoice is EU.
        if (strtotime($invoice['date']) < strtotime('2015-01-01')) {
            // factuur van voor 1 jan 2015 (pre MOSS).
            $vatType = '1';
            if (empty($client['companyname'])) {
                // Particulier.
                $taxRate = $invoice['taxrate'];
            } else {
                // Bedrijf.
                $taxRate = '0.00';
            }
        } elseif (empty($client['tax_id'])) {
            // Particulier.
            // WHMCS zijn digitale diensten.
            $vatType = '6';
            $taxRate = $invoice['taxrate'];
        } else {
            // Bedrijf.
            $vatType = '3';
            $taxRate = '0.00';
        }
    } else {
        // Invoice is Outside EU (WORLD).
        if (strtolower($config['acumulus_invoice_default_nature']) === 'service') {
            // The Default nature is a service (digitale diensten).
            if (empty($client['companyname'])) {
                // particulier
                // whmcs zijn digitale diensten.
                $vatType = '4';
                $taxRate = '0.00';  // btw aangifte moet eventueel worden gedaan in het land van de afnemer.
            } else {
                // bedrijf.
                $vatType = '1';
                $taxRate = '-1'; // btw vrij.
            }
        } else {
            // The Default nature is a product.
            $vatType = '4';
            $taxRate = '0.00'; // btw vrij
        }
    }

    return ['vattype' => $vatType, 'taxrate' => $taxRate];
}

/**
 * Helper function to send the constructed XML to Acumulus with curl.
 */
function acumulus_connect_sendInvoiceToAcumulus(array $config, array $invoice, SimpleXMLElement $xml)
{
    $url = 'https://api.sielsystems.nl/acumulus/stable/invoices/invoice_add.php';
    $xml_string = urlencode($xml->asXML());
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, "xmlstring=$xml_string");
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);

    $rawData = curl_exec($ch);
    $result = json_decode(json_encode((array) simplexml_load_string($rawData)), 1);
    logModuleCall('acumulus_connect', 'Send Invoice to Acumulus', $xml->asXML(), $rawData, $result, acumulus_connect_getReplaceVars($config));

    if (isset($result['status'])) {
        switch ($result['status']) {
            case '0':  // Success.
                $resultStatus = 'Success';
                $messages = '';
                acumulus_connect_setInvoiceToken($invoice, $result['invoice']['token'], $result['invoice']['entryid']);
                break;
            case '1':  // Error(s).
                $resultStatus = 'error(s)';
                $messages = print_r($result['error'], true);
                break;
            case '2':  // Success with warning(s).
                $resultStatus = 'warning(s)';
                $messages = print_r($result['warning'], true);
                acumulus_connect_setInvoiceToken($invoice, $result['invoice']['token'], $result['invoice']['entryid']);
                break;
            default:
                $resultStatus = 'error';
                $messages = "Unknown status code {$result['status']}";
        }
    } else {
        // Exception/curl error.
        $resultStatus = 'exception';
        $messages = 'Error reaching acumulus API webservice';
    }
    logActivity(__FUNCTION__ . "({$invoice['invoiceid']}): $resultStatus $messages.");
    curl_close($ch);
}

/**
 * Gets the vars that should be hidden in the log
 *
 * @param array $config
 *
 * @return array
 *
 */
function acumulus_connect_getReplaceVars(array $config): array
{
    return [$config['acumulus_code'], $config['acumulus_username'], $config['acumulus_password']];
}

/**
 * Helper function to update the token table for unpaid invoices.
 *
 * @param array $invoice
 * @param string $token
 * @param int $entryId
 *
 * @todo
 *   Always store token and entry-id: we need it when this invoice gets
 *   cancelled and, possible future addition, to have links to the acumulus pdf,
 *   packing slip and to visualise the status like we do in the other plugins.
 * @noinspection PhpSeparateElseIfInspection
 */
function acumulus_connect_setInvoiceToken(array $invoice, string $token, int $entryId)
{
    // Don't save the token to the reference table if paid, no need to store
    // references.
    // ED 2022-11-17, [SIEL #189593]: We do need to store the token for when the
    //   invoice gets cancelled.
//    if ($invoice['status'] === 'Paid') {
//        logActivity(__FUNCTION__ . 'Invoice is already Paid, no need to store invoice token.');
//        return;
//    }

    // Check if invoice id and invoice token are already stored and, if so, update.
    if (Capsule::table('mod_acumulus_connect')->where('id', $invoice['invoiceid'])->exists()) {
        // A token already exists, so lets update the token.
        $updateCount = Capsule::table('mod_acumulus_connect')
            ->where('id', $invoice['invoiceid'])
            ->update([
                'token' => $token,
                'entryid' => $entryId,
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
        if ($updateCount > 0) {
            logActivity(__FUNCTION__ . "({$invoice['invoiceid']}): updated");
        } else {
            logActivity(__FUNCTION__ . "({$invoice['invoiceid']}): not updated");
        }
    } else {
        // No token exists, so let's add the token.
        if (Capsule::table('mod_acumulus_connect')->insert([
            'id' => $invoice['invoiceid'],
            'token' => $token,
            'entryid' => $entryId,
            'created_at' => date('Y-m-d H:i:s'),
        ])) {
            logActivity(__FUNCTION__ . "({$invoice['invoiceid']}): inserted");
        } else {
            logActivity(__FUNCTION__ . "({$invoice['invoiceid']}): not inserted");
        }
    }
}

/**
 * Helper function to estimate the totals like Acumulus would calculate.
 */
function acumulus_connect_estimateTotals(array $config, array $invoice, array $client): array
{
    $totalWhmcs = 0;
    $totalAcumulus = 0;

    foreach ($invoice['items']['item'] as $item) {
        $totalWhmcs += (float) $item ['custom_price_incl_tax_unrounded'];
        $totalAcumulus += (float) $item ['custom_price_incl_tax_rounded'];
    }
    $totalWhmcs = round($totalWhmcs, 2);
    $totalAcumulus = round($totalAcumulus, 2);

    $difference = $totalWhmcs - $totalAcumulus;

    /** @noinspection TypeUnsafeComparisonInspection actually: we compare a float here, which poses other problems as well */
    if ($difference != 0) {
        $correctionLine = [
            'id' => 'n/a',
            'type' => '',
            'relid' => '0',
            'description' => acumulus_connect_replaceVarsInText($config['acumulus_invoice_correction_text'], $invoice, $client),
            'amount' => $difference,
            'taxed' => '0',
            'custom_tax_unrounded' => '0',
            'custom_tax_rounded' => '0',
            'custom_price_incl_tax_unrounded' => $difference,
            'custom_price_incl_tax_rounded' => $difference,
            'custom_price_excl_tax_unrounded' => $difference,
            'custom_price_excl_tax_rounded' => $difference,
        ];

        $invoice['items']['item'][] = $correctionLine;
    }

    return $invoice;
}

/**
 * Helper function to get the current payment status from Acumulus.
 */
function acumulus_connect_getPaymentStatus(array $config, string $token): array
{
    $xml = acumulus_connect_basicXml();
    $xml->addChild('token', $token);
    $url = 'https://api.sielsystems.nl/acumulus/stable/invoices/invoice_paymentstatus_get.php';
    $xml_string = urlencode($xml->asXML());
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, "xmlstring=$xml_string");
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);
    $rawData = curl_exec($ch);
    $result = json_decode(json_encode((array) simplexml_load_string($rawData)), 1);
    logModuleCall('acumulus_connect', 'invoice_paymentstatus_get()', $xml->asXML(), $rawData, $result, acumulus_connect_getReplaceVars($config));
    curl_close($ch);

    return $result['invoice'];
}

/**
 * Helper function to inverse the amounts for a credit invoice.
 */
function acumulus_connect_inverseInvoiceAmounts(array $invoice): array
{
    $negativeItems = [];
    //Set invoice amounts  negative.
    foreach ($invoice['items']['item'] as $item) {
        $item['amount'] = number_format(0 - $item['amount'], 2);
        $negativeItems[] = $item;
    }
    $negativeInvoice = $invoice;
    $negativeInvoice['items']['item'] = $negativeItems;
    $negativeInvoice['subtotal'] = number_format(0 - $negativeInvoice['subtotal'], 2);
    $negativeInvoice['tax'] = number_format(0 - $negativeInvoice['tax'], 2);
    $negativeInvoice['total'] = number_format(0 - $negativeInvoice['total'], 2);
    $negativeInvoice['balance'] = number_format(0 - $negativeInvoice['balance'], 2);

    return ($negativeInvoice);
}

/**
 * Helper function to construct the basic XML.
 */
function acumulus_connect_basicXml(bool $includeWarnings = true): SimpleXMLElement
{
    $config = acumulus_connect_get_config();
    // Create The XML FILE.
    $xml = new SimpleXMLElement('<?xml version="1.0" encoding="utf-8"?><myxml></myxml>');
    // Contract details
    $contract = $xml->addChild('contract');
    if ($contract === null) {
        acumulus_connect_raiseLibxmlError();
    }
    $contract->addChild('contractcode', $config['acumulus_code']);
    $contract->addChild('username', $config['acumulus_username']);
    $contract->addChild('password', $config['acumulus_password']);
    if ($includeWarnings) {
        // Do not include child object if entry is empty.
        if (!empty($config['acumulus_warning_email_address'])) {
            $contract->addChild('emailonerror', $config['acumulus_warning_email_address']);
        }
        // Do not include child object if entry is empty.
        if (!empty($config['acumulus_error_email_address'])) {
            $contract->addChild('emailonwarning', $config['acumulus_error_email_address']);
        }
    }
    // The connector feedback code.
    $connector = $xml->addChild('connector');
    if ($connector === null) {
        acumulus_connect_raiseLibxmlError();
    }
    $connector->addChild('application', 'WHMCS ' . acumulus_connect_getWHMCSVersion());
    $connector->addChild('webkoppel', 'Acumulus ' . $config['version']);
    $connector->addChild('development', 'SIEL - Buro RaDer');
    $connector->addChild('remark', 'PHP ' . phpversion());
    $connector->addChild('sourceuri', 'https://github.com/SIELOnline/acumulus-for-WHMCS');

    return $xml;
}

/**
 * Helper function to prepare the customer data for the XML.
 *
 * @noinspection PhpFunctionCyclomaticComplexityInspection
 */
function acumulus_connect_XmlPrepareCustomerDetails(array $config, array $invoice, array $client): array
{
    // Convert country code to country name.
    include_once(__DIR__ . '/assets/ISO3166.php');
    $ISO3166 = new ISO3166();
    try {
        $country = $ISO3166->getByAlpha2($client['countrycode'])['name'];
    } catch (InvalidArgumentException $e) {
        acumulus_logException($e);
        $country = '';
    }

    // Since WHMCS 7.7, WHMCS has a vat id field of its own. Before that, this
    // module supported a custom field to register this information. We still
    // support that custom field if no vat id has been set in the proper
    // WHMCS field and the name of the custom field has been set.
    $vatNr = $client['tax_id'];
    if (empty($vatNr) && !empty($config['acumulus_whmcs_vatfield'])) {
        // Get the id of the custom field corresponding the VAT title and loop
        // through the clients custom fields for the VAT value.
        $customFieldId = Capsule::table('tblcustomfields')->where('fieldname', $config['acumulus_whmcs_vatfield'])->value('id');
        foreach ($client['customfields'] as $val) {
            if ($val['id'] === $customFieldId) {
                $vatNr = $val['value'];
                break;
            }
        }
    }

    // Get the id of the custom field corresponding to the IBAN field and loop
    // through the clients custom fields for the IBAN value.
    $IBAN = '';
    if (!empty($config['acumulus_whmcs_ibanfield'])) {
        $customFieldId = Capsule::table('tblcustomfields')->where('fieldname', $config['acumulus_whmcs_ibanfield'])->first()->id;
        foreach ($client['customfields'] as $val) {
            if ($val['id'] === $customFieldId) {
                $IBAN = $val['value'];
                break;
            }
        }
    }

    $fullName = '';
    $prefix = '';
    if(!empty($client['firstname'])) {
        $fullName .= $client['firstname'];
        $prefix = ' ';
    }
    if(!empty($client['lastname'])) {
        $fullName .= $prefix . $client['lastname'];
    }

    // Set how the customer is being imported into Acumulus
    // 1 = debtor, 2 = creditor, 3 = debtor/creditor (neutral)
    switch ($config['acumulus_customer_type']) {
        case 'Debtor':
            $type = '1';
            break;
        case 'Creditor':
            $type = '2';
            break;
        default:
            // Debtor/Creditor (neutral)
            $type = '3';
    }

    // Set the status of the user to the same status as WHMCS.
    // 0 = Not active / disabled, 1 = Active
    switch ($client['status']) {
        case 'Closed':
        case 'Inactive':
            $contactstatus = '0';
            break;
        default:
            // Active
            $contactstatus = '1';
    }

    // Use Automatic prefill of country name based on supplied country code,
    // Yes with Nederland or Leave the same as in WHMCS?.
    switch ($config['acumulus_customer_countryautoname']) {
        case 'Automatic prefill based on country code':
            $countryautoname = '1';
            break;
        case 'Automatic prefill based on country code including Nederland':
            $countryautoname = '2';
            break;
        default:
            // Use the same country as the customer in WHMCS.
            $countryautoname = '0';
    }

    $customerDetails['type'] = $type;
    $customerDetails['contactid'] = '';
    $customerDetails['contactyourid'] = $client['userid'] ?? '';
    $customerDetails['contactstatus'] = $contactstatus;
    if (!empty($client['companyname'])) {
        $customerDetails['companyname1'] = $client['companyname'];
        $customerDetails['companyname2'] = '';
    }
    $customerDetails['fullname'] = $fullName;
    $customerDetails['salutation'] = '';
    $customerDetails['address1'] = $client['address1'] ?? '';
    $customerDetails['address2'] = $client['address2'] ?? '';
    // Remove any whitespaces from the postcode
    $customerDetails['postalcode'] = (isset($client['postcode']))
        ? preg_replace('/\s+/', '', $client['postcode'])
        : '';
    $customerDetails['city'] = ($client['city'] ?? '') . ((!empty($client['state'])) ? ',  ' . $client['state'] : '');
    $customerDetails['country'] = $country;
    $customerDetails['countrycode'] = $client['countrycode'] ?? '';
    //Automatic prefill of countryname based on supplied countrycode ?
    $customerDetails['countryautoname'] = $countryautoname;
    $customerDetails['vatnumber'] = $vatNr;
    $customerDetails['telephone'] = $client['phonenumber'] ?? '';
    $customerDetails['fax'] = '';
    $customerDetails['email'] = $client['email'] ?? '';
    // 0 = No update made, 1 = Overwrite all customer contact details
    $customerDetails['overwriteifexists'] = (isset($config['acumulus_customer_overwriteifexists']) && $config['acumulus_customer_overwriteifexists'] === 'on') ? '1' : '0';
    $customerDetails['bankaccountnumber'] = $IBAN;
    $customerDetails['mark'] = acumulus_connect_replaceVarsInText($config['acumulus_cusromer_mark'], $invoice, $client);
    // 0 = Leave older duplicate contacts as is, 1 = Mark duplicate contacts as disabled
    $customerDetails['disableduplicates'] = (isset($config['acumulus_customer_disableduplicates']) && $config['acumulus_customer_disableduplicates'] === 'on') ? '1' : '0';

    return $customerDetails;
}

/**
 * Helper function to prepare the invoice data for the XML.
 */
function acumulus_connect_XmlPrepareInvoiceDetails(array $config, array $invoice, array $client, bool $isCredit = false): array
{
    // https://github.com/SIELOnline/acumulus-for-WHMCS/issues/2: Sending
    //   invoices manually always uses default account.
    // I'm not sure that this is the right solution but according to the
    // issue poster it works for them. looking at the call tree,
    // acumulus_connect_get_config() will for all possible execution paths
    // already have been called. But perhaps, it is called too early, when not
    // all data of WHMCS itself has been initialised/is readily available???
    // Calling it once more seems to be a quite innocent action...
    $config = array_merge($config, acumulus_connect_get_config());

    // Format: yyyy-mm-dd.
    $invoiceDetails['issuedate'] = $invoice['date'];
    // When omitted, or when no match has been made possible, the first available cost center in the contract will be selected.
    $invoiceDetails['costcenter'] = $config['acumulus_invoice_default_costcenterid'];
    // 1 = Due (default), 2 = Paid
    $invoiceDetails['paymentstatus'] = ($invoice['status'] === 'Paid' ? '2' : '1');
    // Change the format from  yyyy-mm-dd hh:mm:ss   to  yyyy-mm-dd.  and unset var if eq 0000-00-00.
    // Format: yyyy-mm-dd.
    $invoiceDetails['paymentdate'] = explode(' ', $invoice['datepaid'])[0] === '0000-00-00'
        ? null
        : explode(' ', $invoice['datepaid'])[0];
    // When omitted, or when no match has been made possible, the first available template in the contract will be selected.
    $invoiceDetails['template'] = $config['acumulus_invoice_templateid'];

    // If ['acumulus_use_acumulus_invoice_numbering'] is disabled we use the
    // WHMCS invoice number.
    if ($config['acumulus_use_acumulus_invoice_numbering'] !== 'on') {
        // Check if invoice number exists or use the invoice id instead.
        if (empty($invoice['invoicenum'])) {
            $invoice['invoicenum'] = $invoice['invoiceid'];
        }
        $invoiceDetails['number'] = $invoice['invoicenum'];
    }
    // Overall description of the invoice: invoice title.
    $invoiceDetails['description'] = acumulus_connect_replaceVarsInText($config['acumulus_invoice_description'], $invoice, $client);
    // Multiline field for extended description of the invoice. Content will appear on invoice and associated emails. Use \n for newlines. Tabs are not supported.
    $invoiceDetails['descriptiontext'] = str_replace("\n", "\\n",
        acumulus_connect_replaceVarsInText($config['acumulus_invoice_descriptiontext'], $invoice, $client));
    // Multiline field for additional remarks. Use \n for newlines and \t for tabs. Contents is placed in notes/comments section of the invoice. Content will not appear on the actual invoice or associated emails.
    $invoiceDetails['invoicenotes'] = str_replace(["\n", '{TAB}'],
        ["\\n", "\\t"],
        acumulus_connect_replaceVarsInText($config['acumulus_invoice_invoicenotes'], $invoice, $client));

    // When omitted, or when no match has been made possible, the first available account number in the contract will be selected
    $invoiceDetails['accountnumber'] = $config['account_numbers'][$invoice['paymentmethod']]['id'];
    $invoiceDetails['vattype'] = $invoice['custom']['vattype'];

    // Credit Invoice adjustments.
    if ($isCredit) {
        // Overall description of the invoice, invoice title.
        $invoiceDetails['description'] = acumulus_connect_replaceVarsInText($config['acumulus_creditinvoice_description'], $invoice, $client);
        $invoiceDetails['paymentstatus'] = 2;
        $invoiceDetails['paymentdate'] = date('Y-m-d');
    }

    // Invoice Line Variables.
    $invoiceDetails['invoicelines'] = [];
    if ($config['acumulus_summarize_invoice'] === 'on') {
        // Add total taxed items
        if (!empty($invoice['custom']['subtotal_taxedItems_exclTax'])) {
            $invoiceDetails['invoicelines'][] = [
                // non-mandatory, If set, this number will precede the product description (product).
                'itemnumber' => null,
                // non-mandatory, Product or service description.
                'product' => acumulus_connect_replaceVarsInText($config['acumulus_summarization_text_taxed'], $invoice, $client),
                // non-mandatory,  'Product'( default ), 'Service'
                'nature' => $config['acumulus_invoice_default_nature'],
                // non-mandatory,  Unit price without VAT. Decimal separator is a point. No thousand separators. 4 decimals precision. E.g. 12.95 or 1200.50 or 12.6495. Will be rounded if provided with more than 4 decimals.
                'unitprice' => $invoice['custom']['subtotal_taxedItems_exclTax'],
                // mandatory,  Applicable vatrate for the product. Defaults to 21.
                'vatrate' => $invoice['custom']['taxrate'],
                // non-mandatory, Number of products/services. Decimal separator is a point. No thousand separators. 2 decimals precision. E.g. 1 or 1.5 or 12.64. Default is 1.
                'quantity' => '1',
                // non-mandatory, Use in case of margin vat (marge-regeling). Decimal separator is a point. No thousand separators. 2 decimals precision. E.g. 12.95 or 1200.50
                'costprice' => null,
            ];
        }
        // Add total untaxed items
        if (!empty($invoice['custom']['subtotal_untaxedItems'])) {
            $invoiceDetails['invoicelines'][] = [
                // non-mandatory, If set, this number will precede the product description (product).
                'itemnumber' => null,
                // non-mandatory, Product or service description.
                'product' => acumulus_connect_replaceVarsInText($config['acumulus_summarization_text_untaxed'], $invoice, $client),
                // non-mandatory,  'Product'( default ), 'Service'
                'nature' => $config['acumulus_invoice_default_nature'],
                // non-mandatory,  Unit price without VAT. Decimal separator is a point. No thousand separators. 4 decimals precision. E.g. 12.95 or 1200.50 or 12.6495. Will be rounded if provided with more than 4 decimals.
                'unitprice' => $invoice['custom']['subtotal_untaxedItems'],
                // mandatory,  Applicable vatrate for the product. Defaults to 21.
                'vatrate' => '-1',
                // non-mandatory, Number of products/services. Decimal separator is a point. No thousand separators. 2 decimals precision. E.g. 1 or 1.5 or 12.64. Default is 1.
                'quantity' => '1',
                // non-mandatory, Use in case of margin vat (marge-regeling). Decimal separator is a point. No thousand separators. 2 decimals precision. E.g. 12.95 or 1200.50
                'costprice' => null,
            ];
        }
    } else {
        foreach ($invoice['items']['item'] as $item) {
            /** @noinspection TypeUnsafeComparisonInspection */
            $invoiceDetails['invoicelines'][] = [
                // non-mandatory, If set, this number will precede the product
                // description on an invoice line.
                'itemnumber' => null,
                // non-mandatory, Product or service description.
                'product' => preg_replace("/\r\n|\r|\n/", ' ', $item['description']),
                // non-mandatory,  'Product'( default ), 'Service'
                'nature' => $config['acumulus_invoice_default_nature'],
                // non-mandatory,  Unit price without VAT. Decimal separator is a point. No thousand separators. 4 decimals precision. E.g. 12.95 or 1200.50 or 12.6495. Will be rounded if provided with more than 4 decimals.
                'unitprice' => $item['custom_price_excl_tax_unrounded'],
                // mandatory,  Applicable vat rate for the product. Defaults to 21.
                'vatrate' => ($item['taxed'] == 1) ? $invoice['custom']['taxrate'] : '-1',
                // non-mandatory, Number of products/services. Decimal separator is a point. No thousand separators. 2 decimals precision. E.g. 1 or 1.5 or 12.64. Default is 1.
                'quantity' => '1',
                // non-mandatory, Use in case of margin vat (marge-regeling). Decimal separator is a point. No thousand separators. 2 decimals precision. E.g. 12.95 or 1200.50
                'costprice' => null,
            ];
        }
    }

    return $invoiceDetails;
}

/**
 * Helper function to construct the XML that will be sent to Acumulus.
 *
 * @noinspection PhpFunctionCyclomaticComplexityInspection
 */
function acumulus_connect_generateXml(array $config, array $invoice, array $client, bool $isCredit = false): SimpleXMLElement
{
    // Create the basic XML.
    $customerDetails = acumulus_connect_XmlPrepareCustomerDetails($config, $invoice, $client);
    $invoiceDetails = acumulus_connect_XmlPrepareInvoiceDetails($config, $invoice, $client, $isCredit);

    // Create The XML file.
    $xml = acumulus_connect_basicXml();

    // Add customer details to the XML.
    $customer = $xml->addChild('customer');
    if ($customer === null) {
        acumulus_connect_raiseLibxmlError();
    }

    // Send non-mandatory customer information when enabled in module config.
    if ($config['acumulus_customer_import_enabled'] === 'on') {
        if (!empty($customerDetails['type'])) {
            $customer->addChild('type', $customerDetails['type']);
        }
        if (!empty($customerDetails['contactid'])) {
            $customer->addChild('contactid', $customerDetails['contactid']);
        }
        if (!empty($customerDetails['contactyourid'])) {
            $customer->addChild('contactyourid', $customerDetails['contactyourid']);
        }
        if (isset($customerDetails['contactstatus'])) {
            $customer->addChild('contactstatus', $customerDetails['contactstatus']);
        }
        if (!empty($customerDetails['companyname1'])) {
            $customer->addChild('companyname1', $customerDetails['companyname1']);
        }
        if (!empty($customerDetails['companyname2'])) {
            $customer->addChild('companyname2', $customerDetails['companyname2']);
        }
        if (!empty($customerDetails['fullname'])) {
            $customer->addChild('fullname', $customerDetails['fullname']);
        }
        if (!empty($customerDetails['salutation'])) {
            $customer->addChild('salutation', $customerDetails['salutation']);
        }
        if (!empty($customerDetails['address1'])) {
            $customer->addChild('address1', $customerDetails['address1']);
        }
        if (!empty($customerDetails['address2'])) {
            $customer->addChild('address2', $customerDetails['address2']);
        }
        if (!empty($customerDetails['postalcode'])) {
            $customer->addChild('postalcode', $customerDetails['postalcode']);
        }
        if (!empty($customerDetails['city'])) {
            $customer->addChild('city', $customerDetails['city']);
        }
        if (!empty($customerDetails['country'])) {
            $customer->addChild('country', $customerDetails['country']);
        }
        if (!empty($customerDetails['countrycode'])) {
            $customer->addChild('countrycode', $customerDetails['countrycode']);
        }
        if (isset($customerDetails['countryautoname'])) {
            $customer->addChild('countryautoname', $customerDetails['countryautoname']);
        }
        if (!empty($customerDetails['vatnumber'])) {
            $customer->addChild('vatnumber', $customerDetails['vatnumber']);
        }
        if (!empty($customerDetails['telephone'])) {
            $customer->addChild('telephone', $customerDetails['telephone']);
        }
        if (!empty($customerDetails['fax'])) {
            $customer->addChild('fax', $customerDetails['fax']);
        }
        if (!empty($customerDetails['email'])) {
            $customer->addChild('email', $customerDetails['email']);
        }
        if (isset($customerDetails['overwriteifexists'])) {
            $customer->addChild('overwriteifexists', $customerDetails['overwriteifexists']);
        }
        if (!empty($customerDetails['bankaccountnumber'])) {
            $customer->addChild('bankaccountnumber', $customerDetails['bankaccountnumber']);
        }
        if (!empty($customerDetails['mark'])) {
            $customer->addChild('mark', $customerDetails['mark']);
        }
        if (isset($customerDetails['disableduplicates'])) {
            $customer->addChild('disableduplicates', $customerDetails['disableduplicates']);
        }
    } else {
        // Only send mandatory info.
        if (!empty($customerDetails['countrycode'])) {
            $customer->addChild('countrycode', $customerDetails['countrycode']);
        }
        if (!empty($customerDetails['vatnumber'])) {
            $customer->addChild('vatnumber', $customerDetails['vatnumber']);
        }
    }

    // Add Invoice details to the XML.
    $xmlInvoice = $customer->addChild('invoice');
    if ($xmlInvoice === null) {
        acumulus_connect_raiseLibxmlError();
    }
    if (!empty($invoiceDetails['number'])) {
        $xmlInvoice->addChild('number', $invoiceDetails['number']);
    }
    if (!empty($invoiceDetails['vattype'])) {
        $xmlInvoice->addChild('vattype', $invoiceDetails['vattype']);
    }
    if (!empty($invoiceDetails['issuedate'])) {
        $xmlInvoice->addChild('issuedate', $invoiceDetails['issuedate']);
    }
    if (!empty($invoiceDetails['costcenter'])) {
        $xmlInvoice->addChild('costcenter', $invoiceDetails['costcenter']);
    }
    if (!empty($invoiceDetails['accountnumber'])) {
        $xmlInvoice->addChild('accountnumber', $invoiceDetails['accountnumber']);
    }
    if (!empty($invoiceDetails['paymentdate'])) {
        $xmlInvoice->addChild('paymentdate', $invoiceDetails['paymentdate']);
    }
    if (!empty($invoiceDetails['paymentstatus'])) {
        $xmlInvoice->addChild('paymentstatus', $invoiceDetails['paymentstatus']);
    }
    if (!empty($invoiceDetails['description'])) {
        $xmlInvoice->addChild('description', $invoiceDetails['description']);
    }
    if (!empty($invoiceDetails['descriptiontext'])) {
        $xmlInvoice->addChild('descriptiontext', $invoiceDetails['descriptiontext']);
    }
    if (!empty($invoiceDetails['template'])) {
        $xmlInvoice->addChild('template', $invoiceDetails['template']);
    }
    if (!empty($invoiceDetails['invoicenotes'])) {
        $xmlInvoice->addChild('invoicenotes', $invoiceDetails['invoicenotes']);
    }

    // Add Invoice lines to the XML
    if (!empty($invoiceDetails['invoicelines'])) {
        foreach ($invoiceDetails['invoicelines'] as $invoiceLine) {
            // Set unitprice when omitted.
            if (empty($invoiceLine['unitprice'])) {
                $invoiceLine['unitprice'] = '0.000';
            }

            $xlmInvoiceLine = $xmlInvoice->addChild('line');
            if ($xlmInvoiceLine === null) {
                acumulus_connect_raiseLibxmlError();
            }
            if (!empty($invoiceLine['itemnumber'])) {
                $xlmInvoiceLine->addChild('itemnumber', $invoiceLine['itemnumber']);
            }
            if (!empty($invoiceLine['product'])) {
                $xlmInvoiceLine->addChild('product', $invoiceLine['product']);
            }
            if (!empty($invoiceLine['nature'])) {
                $xlmInvoiceLine->addChild('nature', $invoiceLine['nature']);
            }
            if (!empty($invoiceLine['unitprice'])) {
                $xlmInvoiceLine->addChild('unitprice', $invoiceLine['unitprice']);
            }
            if (!empty($invoiceLine['vatrate'])) {
                $xlmInvoiceLine->addChild('vatrate', $invoiceLine['vatrate']);
            }
            if (!empty($invoiceLine['quantity'])) {
                $xlmInvoiceLine->addChild('quantity', $invoiceLine['quantity']);
            }
            if (!empty($invoiceLine['costprice'])) {
                $xlmInvoiceLine->addChild('costprice', $invoiceLine['costprice']);
            }
        }
    }

    // Let Acumulus Send invoice to client
    if ($config['acumulus_emailaspdf'] === 'on') {
        $xlmInvoicePdfData = $xmlInvoice->addChild('emailaspdf');    // Imported invoices can be sent as pdf file using email by Acumulus.
        if ($xlmInvoicePdfData === null) {
            acumulus_connect_raiseLibxmlError();
        }
        if (!empty($customerDetails['email'])) {
            $xlmInvoicePdfData->addChild('emailto', $customerDetails['email']);
        }
        if (!empty($config['acumulus_emailaspdf_emailbcc'])) {
            $xlmInvoicePdfData->addChild('emailbcc', $config['acumulus_emailaspdf_emailbcc']);
        }
        if (!empty($config['acumulus_emailaspdf_emailfrom'])) {
            $xlmInvoicePdfData->addChild('emailfrom', $config['acumulus_emailaspdf_emailfrom']);
        }
        if (!empty($config['acumulus_emailaspdf_subject'])) {
            $xlmInvoicePdfData->addChild('subject', acumulus_connect_replaceVarsInText($config['acumulus_emailaspdf_subject'], $invoice, $client));
        }
        if (!empty($config['acumulus_emailaspdf_message'])) {
            $xlmInvoicePdfData->addChild('message',
                str_replace("\n", "\\n", acumulus_connect_replaceVarsInText($config['acumulus_emailaspdf_message'], $invoice, $client)));
        }

        if ($config['acumulus_emailaspdf_confirmreading'] === 'on') {
            $xlmInvoicePdfData->addChild('confirmreading', '1');  // 1 = Ask for confirmation
        } else {
            $xlmInvoicePdfData->addChild('confirmreading', '0');  // 0 = Do not ask for confirmation
        }
    }

    return $xml;
}

/*
 * Functions called by Hooks
 */

/**
 * Sends the data of an invoice to Acumulus.
 */
function acumulus_connect_sendInvoice(array $config, int $invoiceId): void
{
    // Retrieve the invoice and customer.
    $invoice = acumulus_connect_getInvoice($invoiceId);
    $client = acumulus_connect_getClient($invoice['userid']);

    //Make the xml file
    $xml = acumulus_connect_generateXml($config, $invoice, $client);

    //Send xml to Acumulus
    acumulus_connect_sendInvoiceToAcumulus($config, $invoice, $xml);
}

/**
 * Updates the payment status of an invoice entry at Acumulus.
 */
function acumulus_connect_updateInvoice(array $config, int $invoiceId, string $useDate = null): void
{
    $invoice = acumulus_connect_getInvoice($invoiceId);

    // Retrieve the token from the mod_acumulus_connect table
    $token = Capsule::table('mod_acumulus_connect')->where('id', $invoice['invoiceid'])->value('token');
    // if the token exists update the invoice in Acumulus, else send entire invoice.
    if ($token !== null) {
        // Update payment gateway if 'use last payment method' is enabled and if it differs from the invoice set payment method.
        if ($config['acumulus_invoice_use_last_paymentmethod'] === 'on'
            && !empty($invoice['transactions']['transaction'])
        ) {
            $lastPaymentGateway = end($invoice['transactions']['transaction'])['gateway'];
            if ($invoice['paymentmethod'] !== $lastPaymentGateway) {
                logActivity(__FUNCTION__ . "($invoiceId): updating payment method in WHMCS");
                acumulus_connect_updateInvoicePaymentMethod($config, $invoiceId, $lastPaymentGateway);
                // Update the payment method of the invoice in whmcs.
                // https://developers.whmcs.com/api-reference/updateinvoice/
                $command = 'UpdateInvoice';
                $postData = [
                    'invoiceid' => $invoiceId,
                    'paymentmethod' => $lastPaymentGateway,
                ];
                acumulus_localAPI($command, $postData);
            }
        }

        // Update invoice to paid.
        $xml = acumulus_connect_basicXml();
        $xml->addChild('token', $token);
        $xml->addChild('paymentstatus', '2');
        if (empty($useDate)) {
            $xml->addChild('paymentdate', substr($invoice['datepaid'], 0, 10));
        } else {
            $xml->addChild('paymentdate', $useDate);
        }

        $url = 'https://api.sielsystems.nl/acumulus/stable/invoices/invoice_paymentstatus_set.php';
        $xml_string = urlencode($xml->asXML());
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, "xmlstring=$xml_string");
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);
        $rawData = curl_exec($ch);
        $result = json_decode(json_encode((array) simplexml_load_string($rawData)), 1);
        logModuleCall('acumulus_connect', 'invoice_paymentstatus_set', $xml->asXML(), $rawData, $result, acumulus_connect_getReplaceVars($config));

        if (isset($result['status'])) {
            switch ($result['status']) {
                case '0':  // Success
                    $resultStatus = 'success';
                    $messages = '';
                    break;
                case '1':  // Failed.
                    $resultStatus = 'error(s)';
                    $messages = print_r($result['error'], true);
                    break;
                case '2':  // Success with warnings.
                    $resultStatus = 'warning(s)';
                    $messages = print_r($result['warning'], true);
                    break;
                default: // Unknown code.
                    $resultStatus = 'error';
                    $messages = "Unspecified Error code {$result['status']}";
            }
        } else {
            // Exception/curl error.
            $resultStatus = 'exception';
            $messages = 'Error reaching acumulus API webservice';
        }
        logActivity(__FUNCTION__ . "($invoiceId): $resultStatus $messages.");
        curl_close($ch);
    } else {
        // Token not found make a module log entry and send entire invoice.
        logActivity(__FUNCTION__ . "($invoiceId) not yet sent, sending.");
        acumulus_connect_sendInvoice($config, $invoiceId);
    }
}

/**
 * Updates the account number of an invoice entry in Acumulus.
 */
function acumulus_connect_updateInvoicePaymentMethod(array $config, int $invoiceId, string $paymentMethod): void
{
    $entryId = Capsule::table('mod_acumulus_connect')->where('id', $invoiceId)->value('entryid');

    if (!empty($entryId)) {
        $accountNumber = $config['account_numbers'][$paymentMethod]['id'];
        //Update the entry account number
        $xml = acumulus_connect_basicXml();
        $xml->addChild('entryid', $entryId);
        $xml->addChild('accountnumber', $accountNumber);

        $url = 'https://api.sielsystems.nl/acumulus/stable/entry/entry_update.php';
        $xml_string = urlencode($xml->asXML());
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, "xmlstring=$xml_string");
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);
        $rawData = curl_exec($ch);
        $result = json_decode(json_encode((array) simplexml_load_string($rawData)), 1);
        logModuleCall('acumulus_connect', 'entry_update', $xml->asXML(), $rawData, $result, acumulus_connect_getReplaceVars($config));

        if (isset($result['status'])) {
            switch ($result['status']) {
                case '0':  // Success
                    $resultStatus = 'success';
                    $messages = '';
                    break;
                case '1':  // Failed.
                    $resultStatus = 'error(s)';
                    $messages = print_r($result['error'], true);
                    break;
                case '2':  // Success with warnings.
                    $resultStatus = 'warning(s)';
                    $messages = print_r($result['warning'], true);
                    break;
                default: // Unknown code.
                    $resultStatus = 'error';
                    $messages = "Unspecified Error code {$result['status']}";
            }
        } else {
            // Exception/curl error.
            $resultStatus = 'exception';
            $messages = 'Error reaching acumulus API webservice';
        }
        logActivity(__FUNCTION__ . "($invoiceId): $resultStatus $messages.");
        curl_close($ch);
    }
}

/**
 * Refund an invoice by creating a credit note.
 */
function acumulus_connect_InvoiceCancelled(array $config, int $invoiceId): void
{
    // Run whmcs api to retrieve the invoice and customer.
    $invoice = acumulus_connect_getInvoice($invoiceId, false);
    $client = acumulus_connect_getClient($invoice['userid']);

    // check if acumulus_use_acumulus_invoice_numbering is used, if so, send an invoice with negative amounts (credit invoice).
    if ($config['acumulus_use_acumulus_invoice_numbering'] === 'on') {
        // Check if the token exists, otherwise just create an activity record
        // and do nothing else.
        // Retrieve the token from the mod_acumulus_connect table.
        $token = Capsule::table('mod_acumulus_connect')->where('id', $invoice['invoiceid'])->value('token');
        // If the token exists, update the invoice in Acumulus, else send the
        // entire invoice.
        if ($token !== null) {
            // Set invoice to paid if its unpaid (get status from acumulus api).
            $paymentStatus = acumulus_connect_getPaymentStatus($config, $token);
            /** @noinspection TypeUnsafeComparisonInspection value is an int in the Acumulus API */
            if ($paymentStatus == '0') {
                $paymentDate = date('Y-m-d');
                acumulus_connect_updateInvoice($config, $invoiceId, $paymentDate);
            }

            // Inverse the amounts in the invoice.
            $negativeInvoice = acumulus_connect_inverseInvoiceAmounts($invoice);
            $negativeInvoice = acumulus_connect_expandInvoiceWithCustomValues($negativeInvoice, $client);

            // Make the xml file.
            $xml = acumulus_connect_generateXml($config, $negativeInvoice, $client, true);

            // Send new credit invoice (xml) to Acumulus.
            acumulus_connect_sendInvoiceToAcumulus($config, $negativeInvoice, $xml);
        } else {
            logActivity(__FUNCTION__ . "($invoiceId): no credit invoice created because no invoice was sent.");
        }
    } else {
        logActivity("acumulus - Credit invoice not created not using acumulus sequential invoice numbering. ($invoiceId for User ID: {$client['userid']})");
    }
}

/**
 * Throws an exception with all libxml error messages as message.
 *
 * @throws \RuntimeException
 *   Always.
 */
function acumulus_connect_raiseLibxmlError(): void
{
    $errors = libxml_get_errors();
    $messages = [];
    foreach ($errors as $error) {
        // Overwrite our own code with the 1st code we get from libxml.
        $messages[] = sprintf(
            'Line %d, column: %d: %s %d - %s',
            $error->line,
            $error->column,
            $error->level === LIBXML_ERR_WARNING ? 'warning' : 'error',
            $error->code,
            trim($error->message)
        );
    }
    throw new RuntimeException(implode("\n", $messages));
}
