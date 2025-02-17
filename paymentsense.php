<?php
/*
* Prestashop Paymentsense Re-Directed Payment Module
* Copyright (C) 2018 Paymentsense.
*
* This program is free software: you can redistribute it and/or modify it under the terms
* of the AFL Academic Free License as published by the Free Software Foundation, either
* version 3 of the License, or (at your option) any later version.
*
* This program is distributed in the hope that it will be useful, but WITHOUT ANY WARRANTY;
* without even the implied warranty of MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.
* See the AFL Academic Free License for more details. You should have received a copy of the
* AFL Academic Free License along with this program. If not, see <http://opensource.org/licenses/AFL-3.0/>.
*
*  @author Paymentsense <devsupport@paymentsense.com>
*  @copyright  2018 Paymentsense
*  @license    http://opensource.org/licenses/afl-3.0.php  Academic Free License (AFL 3.0)
*

File Modified: 12/03/2013 - By Shaun Ponting - Opal Creations.
File Modified: 28/06/2013 - By Shaun Ponting - Opal Creations - Updated Licence, XSS.
File Modified: 16/07/2013 - By Lewis Ayres-Stephens - Paymentsense - Multi Currency
V1.8 - File Modified: 23/07/2013 - By Lewis Ayres-Stephens - Paymentsense - img file structure
V1.9 - File Modified: 17/08/2013 - By Adam Watkins - Opal Creations:
                            -> updated all use of strlen, substr to use Tools::
                            -> Fixed use of (INTVAL) to swap with (int)
                            -> Also added index.php files
                            -> altered folder file structure
                            -> improved code formatting a little
V1.9.1 - File Modified: 28/08/2013 - By Adam Watkins - Opal Creations, see changelog provided to Paymentsense
V1.9.2 - File Modified: 09/10/2013 - By Lewis Ayres-Stephens - Paymentsense - replaced ‘global $smarty' with the context : ‘$this->context->smarty’
V2.0.0 - File Modified: 11/04/2014 - By Paul Moscrop - Opal Creations (Character encodings). Adam Watkins -> Updated base package pricing to 14.95
V2.0.1 - File Modified: 29/03/2016 - By Ryan O'Donnell - Paymentsense - Missing ')'. Removed Regex check for MerchantID and Password, replaced with link to online checker.
V2.1.0 - File Modified: 22/06/2018 - By Alexander Kaltchev - Dev Advisory UK - Added support for PrestaShop 1.7

A complete list of changes can be found in the changelog file (changelog.txt).
*/

if (!defined('_PS_VERSION_'))
    exit;

class Paymentsense extends PaymentModule
{
    /**
     * Transaction Result Codes
     */
    const TRX_RESULT_SUCCESS    = '0';
    const TRX_RESULT_INCOMPLETE = '3';
    const TRX_RESULT_REFERRED   = '4';
    const TRX_RESULT_DECLINED   = '5';
    const TRX_RESULT_DUPLICATE  = '20';
    const TRX_RESULT_FAILED     = '30';

    /**
     * Request Types
     */
    const REQ_NOTIFICATION      = '0';
    const REQ_CUSTOMER_REDIRECT = '1';

    public function __construct()
    {
        $this->name = 'paymentsense';
        $this->tab = 'payments_gateways';
        $this->version = '2.1.0';
        $this->author = 'Paymentsense';
        $this->module_key = '1e631b52ed3d1572df477b9ce182ccf9';

        $this->currencies = true;
        $this->currencies_mode = 'radio';
        parent::__construct();

        $this->displayName = $this->l('Paymentsense');
        $this->description = $this->l('Process transactions through the Paymentsense gateway.');
        
        $this->confirmUninstall = $this->l('Are you sure you want to uninstall?');
    }

    public function install()
    {
        return (parent::install() && Configuration::updateValue('PAYMENTSENSE_GATEWAYID', '')
                                  && Configuration::updateValue('PAYMENTSENSE_GATEWAYPASS', '')
                                  && Configuration::updateValue('PAYMENTSENSE_PSK', '')
                                  && Configuration::updateValue('PAYMENTSENSE_DEBUG', '')
                                  && Configuration::updateValue('PAYMENTSENSE_TRANSACTION_TYPE', '')
                                  && $this->registerHook('payment')
                                  && $this->registerHook('paymentOptions')
                                  && $this->registerHook('paymentReturn'));
    }

    public function uninstall()
    {
        return (Configuration::deleteByName('PAYMENTSENSE_GATEWAYID')
            && Configuration::deleteByName('PAYMENTSENSE_GATEWAYPASS')
            && Configuration::deleteByName('PAYMENTSENSE_PSK')
            && Configuration::deleteByName('PAYMENTSENSE_DEBUG')
            && Configuration::deleteByName('PAYMENTSENSE_TRANSACTION_TYPE')
            && parent::uninstall());
    }

    private function getSetting($name)
    {
        if (array_key_exists($name, $_POST))
        {
            return Tools::getValue($name);
        }
        elseif (Configuration::get($name))
        {
            return Configuration::get($name);
        }
    }

    private function validateTrueFalseString($value)
    {
        if ($value == 'True' || $value == 'False')
        {
            return $value;
        }
    }

    private function trueFalseOption($name, $label, $trueLabel = 'True', $falseLabel = 'False')
    {
        if ($this->getSetting($name) == 'True')
        {
            $trueSelected = ' selected';
            $falseSelected = '';
        }
        else
        {
            $trueSelected = '';
            $falseSelected = ' selected';
        }

        $html = '<strong>'.$this->l(Tools::safeOutput($label)).'&nbsp</strong></td><td><select name="'.Tools::safeOutput($name).'">
        <option'.$trueSelected.' value="True">'.$this->l(Tools::safeOutput($trueLabel)).'</option>
        <option'.$falseSelected.' value="False">'.$this->l(Tools::safeOutput($falseLabel)).'</option>
        </select>';

        return $html;
    }

    private function salePreauthOption($name, $label, $trueLabel = 'PREAUTH', $falseLabel = 'SALE')
    {
        if ($this->getSetting($name) == 'SALE')
        {
            $trueSelected = ' selected';
            $falseSelected = '';
        }
        else
        {
            $trueSelected = '';
            $falseSelected = ' selected';
        }

        $html = '<strong>'.$this->l(Tools::safeOutput($label)).'</strong></td>
                <td>
                    <select name="'.Tools::safeOutput($name).'">
                        <option'.$trueSelected.' value="SALE">'.$this->l(Tools::safeOutput($trueLabel)).'</option>
                        <option'.$falseSelected.' value="PREAUTH">'.$this->l(Tools::safeOutput($falseLabel)).'</option>
                    </select>';

        return $html;
    }

    public function getContent()
    {
        $this->_html .= '<table width="100%" cellspacing="30"><tr><td colspan="2" align="center">';
        /*Validate + save their input*/
        $errors = '';
        if (Tools::getValue('paymentsense_SUBMIT') != '')
        {
            /*Prestashop's pSQL prevents XSS and SQL injection for us using the pSQL function :) */
            if (Tools::getValue('PAYMENTSENSE_GATEWAYID') == '')
            {
                $errors .= '<li><b>'.$this->l('Gateway ID').'</b> - '.
                $this->l('The Gateway ID field can\'t be left blank. Please check the correct value with Paymentsense if you\'re unsure.').'</li>';
            }
            else
            {
                Configuration::updateValue('PAYMENTSENSE_GATEWAYID', Tools::getValue('PAYMENTSENSE_GATEWAYID'));
                Configuration::updateValue('PAYMENTSENSE_GATEWAYPASS', Tools::getValue('PAYMENTSENSE_GATEWAYPASS'));
                Configuration::updateValue('PAYMENTSENSE_PSK', Tools::getValue('PAYMENTSENSE_PSK'));
                Configuration::updateValue('PAYMENTSENSE_DEBUG', Tools::getValue('PAYMENTSENSE_DEBUG'));
                Configuration::updateValue('PAYMENTSENSE_TRANSACTION_TYPE', Tools::getValue('PAYMENTSENSE_TRANSACTION_TYPE'));
            }
        }
        else
        {
            $errors .= '<li>'.$this->l('Problem updating settings, invalid information. 
            If this problem persists get in touch, devsupport@paymentsense.com').'</li>';
        }
        
        $image_url = 'http://'.$_SERVER['HTTP_HOST'].__PS_BASE_URI__.'modules/'.$this->name.'/img/';
        
        
        $this->_html .= '<table width="800px" cellspacing="10">
        <tr>
            <td valign="top"><img src="'.$image_url.'PaymentsenseLogo.jpg" width="181px" height="88px"></td>
            <td></td>
            <td></td>
            <td colspan="2" height="70" valign="middle">
                <img src="'.$image_url.'Tagline.png" width="221px" height="22px">
            </td>
        </tr>
        <tr>
            <td colspan="5" >
                <table style="width:751px; border-bottom: 1px solid #969aa2;">
                    <tr>
                        <td height="1px"></td>
                    </tr>
                </table>
            </td>
        </tr>
        <tr>
            <td colspan="5" height="70px" style="font-size:16px; padding-top:10px;">
                        '.$this->l('Thank you for choosing Paymentsense for your online payments,').'<br/><br />
                '.$this->l('In order to process payments through your Paymentsense account please enter your gateway details below:').'<br/>
            </td>
        </tr>
        <tr style="width:100%;">
            <td colspan="2" style="width:30%;" align="center">
                <img src="'.$image_url.'ecomPlaceholder.jpg" width="250px" height="181px"></br>
            </td>
            <td colspan="3" style="width:70%;">
                <h2>Gateway Details</h2>
                        <ul style="font-size:14px; ">
                            <li><strong>Merchant ID Format:</strong> ABCDEF-1234567.</li>
                        <li><strong>Password Format:</strong> Contains only Letters and Numbers NO Special Characters.</li>
                        <li><strong>PreSharedKey:</strong> Found in the Merchant Management System under "Account Admin" > "Account Settings".</li>
                        <li><strong>Debug Mode:</strong> only turn on for testing. Do NOT use for live accounts.</li>
                        <li><strong>Transaction Type:</strong> PREAUTH - Only Authorises the transaction, SALE - Authorises and Collects the transaction.</li>
                        <li>Please refer to our <a href="http://developers.paymentsense.co.uk/developers-resources/faq/" target="_blank"><strong>FAQ</strong></a> if you have any issues.</li>
                        </ul>
            </td>
        </tr>
    </table>
     <br />';



        /*Display errors / confirmation*/
        if (Tools::getValue('paymentsense_SUBMIT') != '')
        {            
            $this->_html .= '<div style="width:754px; margin-right:20px; background-color:#8bc954; color:black;" class="conf confirm"><strong>'.
            $this->l('Changes have all been saved').'</strong></div>';
        }

        /*Display the form*/
        $this->_html .= '<form action="'.htmlentities($_SERVER['REQUEST_URI'], ENT_COMPAT, 'UTF-8').'" method="post">
        <table width="945px"><tr><td width="247px" align="right">';

        /*Display options*/
        $this->_html .= '</tr></table>
        <fieldset style="height:360px; width:730px; margin-right:20px;">
            <table width="700px" cellspacing="20" align="center">
            <tr><td colspan="2" style="padding-bottom:10px; font-size:16px;">'.$this->l('Enter your gateway merchant details below and click save to begin taking payments.').' Please check your gateway details <a href="https://www.psdevsupport.co.uk/CheckDetails" target="_blank"><strong>HERE</strong></a> first.</td></tr>
            <tr><td align="right">
                <strong>'.htmlentities($this->l('Gateway MerchantID: '), ENT_COMPAT | ENT_HTML401, 'UTF-8'). '&nbsp;</strong></td><td align="left"><input name="PAYMENTSENSE_GATEWAYID" type="text" value="'.htmlentities($this->getSetting('PAYMENTSENSE_GATEWAYID'), ENT_COMPAT | ENT_HTML401, 'UTF-8').'" /><br/>
            </td></tr><tr><td align="right">
                <strong>'.htmlentities($this->l('Gateway Password: '), ENT_COMPAT | ENT_HTML401, 'UTF-8').'&nbsp</strong></td><td align="left"><input name="PAYMENTSENSE_GATEWAYPASS" type="text" value="'.htmlentities($this->getSetting('PAYMENTSENSE_GATEWAYPASS'), ENT_COMPAT | ENT_HTML401, 'UTF-8').'" />
            </td></tr><tr><td width="50%" align="right">
                <strong>'.htmlentities($this->l('Pre-Shared Key: '), ENT_COMPAT | ENT_HTML401, 'UTF-8').'&nbsp</strong></td><td align="left"><input type="text" name="PAYMENTSENSE_PSK" value="'.htmlentities($this->getSetting('PAYMENTSENSE_PSK'), ENT_COMPAT | ENT_HTML401, 'UTF-8').'"/>
            </td></tr><tr><td colspan="" align="right">'.
                $this->trueFalseOption('PAYMENTSENSE_DEBUG', 'Debug Mode: ', 'On', 'Off').
            '</td></tr><tr><td colspan=""  align="right">'.
            $this->trueFalseOption('PAYMENTSENSE_TRANSACTION_TYPE', 'Transaction Type: ', 'PREAUTH', 'SALE').
            '</td></tr><tr><td colspan="2" align="center" style="padding-top:20px;">
            <input style="background: url('.$image_url.'BlueButtonBackground.png) no-repeat; border: none; cursor:pointer;
            cursor:hand; width:170px; height:38px; color:white; font-weight:bold;" type="submit" name="paymentsense_SUBMIT" id="paymentsense_SUBMIT" value="'.$this->l('Save your changes').'" /></form>
            </td></tr></table></fieldset>';

        $this->_html .= '</td></tr><tr><td colspan="2" align="center">
        <table width="900px">
        <tr>
        <td align="center">
        '.str_replace("copy;",utf8_encode('&copy;'), $this->l('Copyright copy; 2014 Paymentsense Ltd. All rights reserved. Paymentsense, acting as an agent of First Data Europe Limited, trading as First Data Merchant Solutions, is registered with MasterCard / Visa as an Independent Sales Organisation and Member Service Provider.') )
        .'</td>
        </tr></table>';

        $this->_html .= '</td></tr></table>';
        return $this->_html;
    }

    /**
     * Hooks Payment to show Paymentsense on the checkout page
     * (Used by PrestaShop 1.6)
     *
     * @param array $params
     *
     * @return string|false;
     */
    public function hookPayment($params)
    {
        if (!$this->active) {
            return false;
        }

        if (!$this->isConfigured()) {
            return false;
        }

        $this->context->smarty->assign($this->buildHostedFormData($params));
        return $this->display(__FILE__, '/views/templates/front/paymentsense.tpl');
    }

    /**
     * Hooks Payment Options to show Paymentsense on the checkout page
     * (Used by PrestaShop 1.7)
     *
     * @param array $params
     *
     * @return array|false
     *
     * @throws SmartyException
     */
    public function hookPaymentOptions($params)
    {
        if (!$this->active) {
            return false;
        }

        if (!$this->isConfigured()) {
            return false;
        }

        $formData = $this->buildHostedFormData($params);
        $this->context->smarty->assign(
            'paymentsense',
            array(
                'name'       => $this->name,
                'path'       => $this->getPathUri(),
                'formparams' => $formData['parameters']
            ),
            true
        );
        $paymentOption = new PrestaShop\PrestaShop\Core\Payment\PaymentOption();
        $paymentOption->setCallToActionText('Pay by debit or credit card using Paymentsense')
            ->setForm(
                '<form method="post" action="' . $formData['form_target'] .
                '" onsubmit="return onSubmitPaymentsense(this);"></form>'
            )
            ->setAdditionalInformation(
                $this->context->smarty->fetch("module:paymentsense/views/templates/front/payment_option.tpl")
            );

        return array($paymentOption);
    }

    /**
     * Builds the data for the form redirecting to the Hosted Payment Form
     *
     * Common for all PrestaShop versions
     *
     * Uses the code of the original hookPayment class method with following modifications:
     * 1. The code for checking whether the module is active and displaying still resides in hookPayment
     * 2. The values for the CallbackURL and ServerResultURL parameters are set by using the class methods
     * getCustomerRedirectUrl and getNotificationUrl
     * 3. The format of the TransactionDateTime parameter is changed from 'Y-m-d H:i:s O' to 'Y-m-d H:i:s P' as per the
     * current format defined in the Hosted Payment Form Credit & Debit Card Processing document v2.5.01 section
     * Required Input Variables
     *
     * @param array $params
     *
     * @return array
     */
    public function buildHostedFormData($params)
    {
        $address = new Address((int)($params['cart']->id_address_invoice));
        $customer = new Customer((int)($params['cart']->id_customer));
        $currency = $this->getCurrency();

        $psquery = 'SELECT id_currency FROM '._DB_PREFIX_.'module_currency WHERE id_module = '.(int)$this->id;
        $db = Db::getInstance();
        $queryresult = $db->getRow($psquery);
        $id1_currency = array_shift($queryresult);

        /*get currency of current cart.*/
        $psquery1 = 'SELECT iso_code FROM '._DB_PREFIX_.'currency WHERE id_currency = '.(int)$params['cart']->id_currency;
        $queryresult1 = $db->getRow($psquery1);
        $cart_currency = array_shift($queryresult1);

        if (!$id1_currency || $id1_currency == -2)
        {
            $id2_currency = Configuration::get('PS_CURRENCY_DEFAULT');
        }
        elseif ($id1_currency == -1)
        {
            $id2_currency = $params['cart']->id_currency;
        }

        /*get currency of current cart.*/
        $psquery2 = 'SELECT conversion_rate FROM '._DB_PREFIX_.'currency WHERE id_currency = '.(int)$params['cart']->id_currency;
        $queryresult2 = $db->getRow($psquery2);
        $cart_conversion_rate = array_shift($queryresult2);

        /*Grab the order total and format it properly*/
        if ($params['cart']->id_currency != $id2_currency)
        {
            $price = $params['cart']->getOrderTotal(true, 3);
            $amount = number_format($price, 2, '.', '');
            $currencyps = $cart_currency;
        }
        else
        {
            $amount = number_format($params['cart']->getOrderTotal(true, 3), 2, '.', '');
            $currencyps = $cart_currency;
        }

        $amount = sprintf('%0.2f', $amount);
        $amount = preg_replace('/[^\d]+/', '', $amount);

        $orderTotal = $params['cart']->getOrderTotal(true, 3);

        $parameters = array();

        $paymentsense_psk = $this->getSetting('PAYMENTSENSE_PSK');
        $paymentsense_gatewaypass = $this->getSetting('PAYMENTSENSE_GATEWAYPASS');

        if ($this->getSetting('PAYMENTSENSE_TRANSACTION_TYPE') == 'False')
        {
            $paymentsense_transactiontype = 'SALE';
        }
        else
        {
            $paymentsense_transactiontype = 'PREAUTH';
        }

        $datestamp = date('Y-m-d H:i:s P');
        $gatewayorderID = date('Ymd-His').'~'.$params['cart']->id;

        if ($address->phone != '')
        {
            $PhoneNumber = $address->phone;
        }
        else
        {
            $PhoneNumber = $address->phone_mobile;
        }

        switch ($currency->iso_code)
        {
            case 'GBP':
                $currencyISO = '826';
                break;
            case 'USD':
                $currencyISO = '840';
                break;
            case 'EUR':
                $currencyISO = '978';
                break;
            default:
                $currencyISO = htmlentities($currency->iso_code);
                break;
        }

        $HashString = 'PreSharedKey='.$paymentsense_psk;
        $HashString .= '&MerchantID='.$this->getSetting('PAYMENTSENSE_GATEWAYID');
        $HashString .= '&Password='.$paymentsense_gatewaypass;
        $HashString .= '&Amount='.$amount;
        $HashString .= '&CurrencyCode='.$this->getCurrencyISO($currencyps);
        $HashString .= '&EchoAVSCheckResult=True';
        $HashString .= '&EchoCV2CheckResult=True';
        $HashString .= '&EchoThreeDSecureAuthenticationCheckResult=True';
        $HashString .= '&EchoCardType=True';
        $HashString .= '&OrderID='.$gatewayorderID;
        $HashString .= '&TransactionType='.$paymentsense_transactiontype;
        $HashString .= '&TransactionDateTime='.$datestamp;
        $HashString .= '&CallbackURL='.$this->getCustomerRedirectUrl();
        $HashString .= '&OrderDescription='.$gatewayorderID;
        $HashString .= '&CustomerName='.$customer->firstname.' '.$customer->lastname;
        $HashString .= '&Address1='.$address->address1;
        $HashString .= '&Address2='.$address->address2;
        $HashString .= '&Address3=';
        $HashString .= '&Address4=';
        $HashString .= '&City='.$address->city;
        $HashString .= '&State=';
        $HashString .= '&PostCode='.$address->postcode;
        $HashString .= '&CountryCode='.$this->getCountryISO($address->country);
        $HashString .= '&EmailAddress='.$customer->email;
        $HashString .= '&PhoneNumber='.$PhoneNumber;
        $HashString .= '&EmailAddressEditable=False';
        $HashString .= '&PhoneNumberEditable=False';
        $HashString .= '&CV2Mandatory=True';
        $HashString .= '&Address1Mandatory=True';
        $HashString .= '&CityMandatory=True';
        $HashString .= '&PostCodeMandatory=True';
        $HashString .= '&StateMandatory=False';
        $HashString .= '&CountryMandatory=True';
        $HashString .= '&ResultDeliveryMethod=SERVER';
        $HashString .= '&ServerResultURL=' . $this->getNotificationUrl();
        $HashString .= '&PaymentFormDisplaysResult=False';
        $HashString .= '&ServerResultURLCookieVariables='.'';
        $HashString .= '&ServerResultURLFormVariables=orderTotal='.$orderTotal;
        $HashString .= '&ServerResultURLQueryStringVariables=';
        $HashDigest = sha1($HashString);

        $parameters['HashDigest'] = $HashDigest;
        $parameters['MerchantID'] = $this->getSetting('PAYMENTSENSE_GATEWAYID');
        $parameters['Amount'] = $amount;
        $parameters['CurrencyCode'] = $this->getCurrencyISO($currencyps);
        $parameters['EchoAVSCheckResult'] = 'True';
        $parameters['EchoCV2CheckResult'] = 'True';
        $parameters['EchoThreeDSecureAuthenticationCheckResult'] = 'True';
        $parameters['EchoCardType'] = 'True';
        $parameters['OrderID'] = $gatewayorderID;
        $parameters['TransactionType'] = $paymentsense_transactiontype;
        $parameters['TransactionDateTime'] = $datestamp;
        $parameters['CallbackURL'] = $this->getCustomerRedirectUrl();
        $parameters['OrderDescription'] = $gatewayorderID;
        $parameters['CustomerName'] = $customer->firstname.' '.$customer->lastname;
        $parameters['Address1'] = $address->address1;
        $parameters['Address2'] = $address->address2;
        $parameters['Address3'] = '';
        $parameters['Address4'] = '';
        $parameters['City'] = $address->city;
        $parameters['State'] = '';
        $parameters['PostCode'] = $address->postcode;
        $parameters['CountryCode'] = $this->getCountryISO($address->country);
        $parameters['EmailAddress'] = $customer->email;
        $parameters['PhoneNumber'] = $PhoneNumber;
        $parameters['EmailAddressEditable'] = 'False';
        $parameters['PhoneNumberEditable'] = 'False';
        $parameters['CV2Mandatory'] = 'True';
        $parameters['Address1Mandatory'] = 'True';
        $parameters['CityMandatory'] = 'True';
        $parameters['PostCodeMandatory'] = 'True';
        $parameters['StateMandatory'] = 'False';
        $parameters['CountryMandatory'] = 'True';
        $parameters['ResultDeliveryMethod'] = 'SERVER';
        $parameters['ServerResultURL'] = $this->getNotificationUrl();
        $parameters['PaymentFormDisplaysResult'] = 'False';
        $parameters['ServerResultURLCookieVariables'] = '';
        $parameters['ServerResultURLFormVariables'] = 'orderTotal='.$orderTotal;
        $parameters['ServerResultURLQueryStringVariables'] = '';

        $parameters['ThreeDSecureCompatMode'] = 'false';
        $parameters['ServerResultCompatMode'] = 'false';

        $form_target = 'https://mms.paymentsensegateway.com/Pages/PublicPages/PaymentForm.aspx';

        return array('parameters' => $parameters, 'form_target' => $form_target);
    }

    public function generateorderdate()
    {
        $str = date('Ymd-His');
        
        return $str;
    }

    /* Helper functions */
    public function parseBoolString($boolString)
    {
        if (!$boolString || (strcasecmp($boolString, 'false') == 0) || $boolString == '0')
        {
            return false;
        }
        else
        {
            return true;
        }
    }

    public function formatAmount($amount, $minorUnits)
    {
        if (parseBoolString($minorUnits))
        {
            $amount = $amount / 100;
        }
        
        return (float)($amount);
    }

    public function currencySymbol($currencyCode)
    {
        switch ($currencyCode)
        {
            case 'GBP':
                return '&pound;';
                break;
            case 'USD':
                return '$';
                break;
            case 'EUR':
                return '&euro;';
                break;
            default:
                return htmlentities($currencyCode);
                break;
        }
    }

    public function checkParams($params)
    {
        return !((empty($params)) || (!array_key_exists('ps_merchant_reference', $params)) || (!array_key_exists('ps_payment_amount', $params)));
    }

    public function checkChecksum($secretKey, $amount, $currencyCode, $merchantRef, $paymentsenseRef, $paymentsenseChecksum)
    {
        if (empty($secretKey))
        {
            return array(true, 'checksum ignored, no secretkey');
        }
        else
        {
            if (empty($paymentsenseChecksum))
            {
                return array(false, 'checksum expected but missing (check secret key)');
            }
            else
            {
                $checksum = sha1($amount.$currencyCode.$merchantRef.$paymentsenseRef.$secretKey);
                if ($checksum != $paymentsenseChecksum)
                {
                    return array(false, 'checksum mismatch (check secret key)');
                }
                else
                {
                    return array(true, 'checksum matched');
                }
            }
        }
    }

    public function getCurrencyISO($currencyISO)
    {
        $currencies = array('ARS' => 32, 'AUD' => 36, 'BRL' => 986, 'CAD' => 124, 'CHF' => 756, 'CLP' => 152, 'CNY' => 156,
        'COP' => 170, 'CZK' => 203, 'DKK' => 208, 'EUR' => 978, 'GBP' => 826, 'HKD' => 344, 'HUF' => 348, 'IDR' => 360,
        'ISK' => 352, 'JPY' => 392, 'KES' => 404, 'KRW' => 410, 'MXN' => 484, 'MYR' => 458, 'NOK' => 578, 'NZD' => 554,
        'PHP' => 608, 'PLN' => 985, 'SEK' => 752, 'SGD' => 702, 'THB' => 764, 'TWD' => 901, 'USD' => 840, 'VND' => 704, 'ZAR' => 710);

        if ($currencies[strtoupper($currencyISO)])
        {
            return $currencies[strtoupper($currencyISO)];
        }
        return 'error - cannot find currency';
    }

    public function getCountryISO($country_long_name)
    {
        $countries = array('United Kingdom' => 826, 'United States' => 840, 'Australia' => 36, 'Canada' => 124, 'France' => 250, 'Germany' => 276,
        'Afghanistan' => 4, 'Åland Islands' => 248, 'Albania' => 8, 'Algeria' => 12, 'American Samoa' => 16, 'Andorra' => 20, 'Angola' => 24,
        'Anguilla' => 660, 'Antarctica' => 10, 'Antigua and Barbuda' => 28, 'Argentina' => 32, 'Armenia' => 51, 'Aruba' => 533, 'Austria' => 40,
        'Azerbaijan' => 31, 'Bahamas' => 44, 'Bahrain' => 48, 'Bangladesh' => 50, 'Barbados' => 52, 'Belarus' => 112, 'Belgium' => 56, 'Belize' => 84,
        'Benin' => 204, 'Bermuda' => 60, 'Bhutan' => 64, 'Bolivia' => 68, 'Bosnia and Herzegovina' => 70, 'Botswana' => 72, 'Bouvet Island' => 74,
        'Brazil Federative' => 76, 'British Indian Ocean Territory' => 86, 'Brunei' => 96, 'Bulgaria' => 100, 'Burkina Faso' => 854, 'Burundi' => 108,
        'Cambodia' => 116, 'Cameroon' => 120, 'Cape Verde' => 132, 'Cayman Islands' => 136, 'Central African Republic' => 140, 'Chad' => 148, 'Chile' => 152,
        'China' => 156, 'Christmas Island' => 162, 'Cocos (Keeling) Islands' => 166, 'Colombia' => 170, 'Comoros' => 174, 'Congo - Kinshasa' => 180, 'Congo - Brazzaville' => 178,
        'Cook Islands' => 184, 'Costa Rica' => 188, 'Côte d\'Ivoire' => 384, 'Croatia' => 191, 'Cuba' => 192, 'Cyprus' => 196, 'Czech Republic' => 203,
        'Denmark' => 208, 'Djibouti' => 262, 'Dominica' => 212, 'Dominican Republic' => 214, 'East Timor' => 626, 'Ecuador' => 218, 'Egypt' => 818,
        'El Salvador' => 222, 'Equatorial Guinea' => 226, 'Eritrea' => 232, 'Estonia' => 233, 'Ethiopia' => 231, 'Falkland Islands (Malvinas)' => 238,
        'Faroe Islands' => 234, 'Fiji' => 242, 'Finland' => 246, 'French Guiana' => 254, 'French Polynesia' => 258, 'French Southern Territories' => 260,
        'Gabon' => 266, 'Gambia' => 270, 'Georgia' => 268, 'Ghana' => 288, 'Gibraltar' => 292, 'Greece' => 300, 'Greenland' => 304, 'Grenada' => 308,
        'Guadaloupe' => 312, 'Guam' => 316, 'Guatemala' => 320, 'Guernsey' => 831, 'Guinea' => 324, 'Guinea-Bissau' => 624, 'Guyana' => 328, 'Haiti' => 332,
        'Heard Island and McDonald Islands' => 334, 'Honduras' => 340, 'Hong Kong' => 344, 'Hungary' => 348, 'Iceland' => 352, 'India' => 356, 'Indonesia' => 360,
        'Iran' => 364, 'Iraq' => 368, 'Ireland' => 372, 'Isle of Man' => 833, 'Israel' => 376, 'Italy' => 380, 'Jamaica' => 388, 'Japan' => 392,
        'Jersey' => 832, 'Jordan' => 400, 'Kazakhstan' => 398, 'Kenya' => 404, 'Kiribati' => 296, 'South Korea' => 410, 'North Korea' => 408, 'Kuwait' => 414,
        'Kyrgyzstan' => 417, 'Lao' => 418, 'Latvia' => 428, 'Lebanon' => 422, 'Lesotho' => 426, 'Liberia' => 430, 'Libyan Arab Jamahiriya' => 434,
        'Liechtenstein' => 438,    'Lithuania' => 440, 'Luxembourg' => 442, 'Macau' => 446, 'Macedonia' => 807, 'Madagascar' => 450, 'Malawi' => 454,
        'Malaysia' => 458, 'Maldives' => 462, 'Mali' => 466, 'Malta' => 470, 'Marshall Islands' => 584, 'Martinique' => 474, 'Mauritania Islamic' => 478,
        'Mauritius' => 480, 'Mayotte' => 175, 'Mexico' => 484, 'Micronesia' => 583, 'Moldova' => 498, 'Monaco' => 492, 'Mongolia' => 496,
        'Montenegro' => 499, 'Montserrat' => 500, 'Morocco' => 504, 'Mozambique' => 508, 'Myanmar' => 104, 'Namibia' => 516, 'Nauru' => 520, 'Nepal' => 524,
        'Netherlands' => 528, 'Netherlands Antilles' => 530, 'New Caledonia' => 540, 'New Zealand' => 554, 'Nicaragua' => 558, 'Niger' => 562,
        'Nigeria' => 566, 'Niue' => 570, 'Norfolk Island' => 574, 'Northern Mariana Islands' => 580, 'Norway' => 578, 'Oman' => 512, 'Pakistan' => 586,
        'Palau' => 585, 'Palestine' => 275,    'Panama' => 591, 'Papua New Guinea' => 598, 'Paraguay' => 600, 'Peru' => 604, 'Philippines' => 608,
        'Pitcairn' => 612, 'Poland' => 616, 'Portugal' => 620, 'Puerto Rico' => 630, 'Qatar' => 634, 'Réunion' => 638, 'Romania' => 642,
        'Russian Federation' => 643, 'Rwanda' => 646, 'Saint Barthélemy' => 652, 'Saint Helena' => 654, 'Saint Kitts and Nevis' => 659, 'Saint Lucia' => 662,
        'Saint Martin (French part)' => 663, 'Saint Pierre and Miquelon' => 666, 'Saint Vincent and the Grenadines' => 670,    'Samoa' => 882,
        'San Marino' => 674, 'São Tomé and Príncipe Democratic' => 678, 'Saudi Arabia' => 682, 'Senegal' => 686, 'Serbia' => 688, 'Seychelles' => 690,
        'Sierra Leone' => 694, 'Singapore' => 702, 'Slovakia' => 703, 'Slovenia' => 705, 'Solomon Islands' => 90, 'Somalia' => 706, 'South Africa' => 710,
        'South Georgia and the South Sandwich Islands' => 239, 'Spain' => 724,    'Sri Lanka' => 144,    'Sudan' => 736,    'Suriname' => 740,
        'Svalbard and Jan Mayen' => 744, 'Swaziland' => 748, 'Sweden' => 752, 'Switzerland' => 756, 'Syrian Arab Republic' => 760, 'Taiwan' => 158,
        'Tajikistan' => 762, 'Tanzania' => 834, 'Thailand' => 764, 'Togo' => 768, 'Tokelau' => 772, 'Tonga' => 776,    'Trinidad and Tobago' => 780,
        'Tunisia' => 788, 'Turkey' => 792, 'Turkmenistan' => 795, 'Turks and Caicos Islands' => 796, 'Tuvalu' => 798, 'Uganda' => 800, 'Ukraine' => 804,
        'United Arab Emirates' => 784, 'United States Minor Outlying Islands' => 581, 'Uruguay Eastern' => 858, 'Uzbekistan' => 860, 'Vanuatu' => 548,
        'Vatican City State' => 336, 'Venezuela' => 862, 'Vietnam' => 704, 'Virgin Islands, British' => 92, 'Virgin Islands, U.S.' => 850,
        'Wallis and Futuna' => 876,    'Western Sahara' => 732, 'Yemen' => 887, 'Zambia' => 894, 'Zimbabwe' => 716);

        if (isset($countries[$country_long_name]))
            return $countries[$country_long_name];

        return 'error - cannot find country';
    }

    /**
     * Checks whether the PrestaShop Version is 1.6
     *
     * @return bool
     */
    protected function isPrestaShopVersion16()
    {
        return $this->isPrestaShopVersionInRange('1.6.0.0', '1.6.9.9');
    }

    /**
     * Checks whether the PrestaShop Version is 1.7
     *
     * @return bool
     */
    protected function isPrestaShopVersion17()
    {
        return $this->isPrestaShopVersionInRange('1.7.0.0', '1.7.9.9');
    }

    /**
     * Checks whether the PrestaShop Version is in the interval [$minVersion, $maxVersion]
     *
     * @param string $minVersion
     * @param string $maxVersion
     *
     * @return bool
     */
    protected function isPrestaShopVersionInRange($minVersion, $maxVersion)
    {
        return version_compare(_PS_VERSION_, $minVersion, '>=') &&
            version_compare(_PS_VERSION_, $maxVersion, '<=');
    }

    /**
     * Checks whether the payment method is configured
     *
     * @return bool True if gateway merchant ID, password and pre-shared key are non-empty, otherwise false
     */
    public function isConfigured()
    {
        return ((trim(Configuration::get('PAYMENTSENSE_GATEWAYID')) != '') &&
            (trim(Configuration::get('PAYMENTSENSE_GATEWAYPASS')) != '') &&
            (trim(Configuration::get('PAYMENTSENSE_PSK')) != ''));
    }

    /**
     * Gets the URL where the customer will be redirected when returning from the Hosted Payment Form
     *
     * Used to set the CallbackURL parameter for the Hosted Payment Form request
     *
     * @return string|false A string containing a URL or false if the PrestaShop version is not supported
     */
    protected function getCustomerRedirectUrl()
    {
        switch (true) {
            case $this->isPrestaShopVersion16():
                $result = Tools::getShopDomain(true) . __PS_BASE_URI__ . 'modules/paymentsense/success.php';
                break;
            case $this->isPrestaShopVersion17():
                $result = $this->context->link->getModuleLink($this->name, 'customerredirect');
                break;
            default:
                // Unsupported PrestaShop version
                $result = false;
        }
        return $result;
    }

    /**
     * Gets the Notification URL handling the response from the Hosted Payment Form
     *
     * Used to set the ServerResultURL parameter for the Hosted Payment Form request
     *
     * @return string|false A string containing a URL or false if the PrestaShop version is not supported
     */
    protected function getNotificationUrl()
    {
        switch (true) {
            case $this->isPrestaShopVersion16():
                $result = Tools::getShopDomain(true) . __PS_BASE_URI__ . 'modules/paymentsense/callback.php';
                break;
            case $this->isPrestaShopVersion17():
                $result = $this->context->link->getModuleLink($this->name, 'notification');
                break;
            default:
                // Unsupported PrestaShop version
                $result = false;
        }
        return $result;
    }

    /**
     * Calculates the hash digest.
     * Supported hash methods: MD5, SHA1, HMACMD5, HMACSHA1
     *
     * @param string $data Data to be hashed.
     * @param string $hashMethod Hash method.
     * @param string $key Secret key to use for generating the hash.
     * @return string
     */
    public function calculateHashDigest($data, $hashMethod, $key)
    {
        $result     = '';
        $includeKey = in_array($hashMethod, ['MD5', 'SHA1'], true);
        if ($includeKey) {
            $data = 'PreSharedKey=' . $key . '&' . $data;
        }
        switch ($hashMethod) {
            case 'MD5':
                // @codingStandardsIgnoreLine
                $result = md5($data);
                break;
            case 'SHA1':
                $result = sha1($data);
                break;
            case 'HMACMD5':
                $result = hash_hmac('md5', $data, $key);
                break;
            case 'HMACSHA1':
                $result = hash_hmac('sha1', $data, $key);
                break;
        }
        return $result;
    }

    /**
     * Checks whether the hash digest received from the payment gateway is valid
     *
     * @param string $requestType Type of the request (notification or customer redirect)
     *
     * @return bool
     */
    public function isHashDigestValid($requestType)
    {
        $result = false;
        $data   = $this->buildPostString($requestType);
        if ($data) {
            $hashDigestReceived   = Tools::getValue('HashDigest');
            $hashDigestCalculated = $this->calculateHashDigest(
                $data,
                'SHA1', // Hardcoded as per the current plugin implementation
                Configuration::get('PAYMENTSENSE_PSK')
            );
            $result = strToUpper($hashDigestReceived) === strToUpper($hashDigestCalculated);
        }
        return $result;
    }

    /**
     * Builds a string containing the expected fields from the POST request received from the payment gateway
     *
     * @param string $requestType Type of the request (notification or customer redirect)
     *
     * @return bool
     */
    public function buildPostString($requestType)
    {
        $result = false;
        $fields = array(
            // Variables for hash digest calculation for notification requests (excluding configuration variables)
            self::REQ_NOTIFICATION      => array(
                'StatusCode',
                'Message',
                'PreviousStatusCode',
                'PreviousMessage',
                'CrossReference',
                'AddressNumericCheckResult',
                'PostCodeCheckResult',
                'CV2CheckResult',
                'ThreeDSecureAuthenticationCheckResult',
                'CardType',
                'CardClass',
                'CardIssuer',
                'CardIssuerCountryCode',
                'Amount',
                'CurrencyCode',
                'OrderID',
                'TransactionType',
                'TransactionDateTime',
                'OrderDescription',
                'CustomerName',
                'Address1',
                'Address2',
                'Address3',
                'Address4',
                'City',
                'State',
                'PostCode',
                'CountryCode',
                'EmailAddress',
                'PhoneNumber',
            ),
            // Variables for hash digest calculation for customer redirects (excluding configuration variables)
            self::REQ_CUSTOMER_REDIRECT => array(
                'CrossReference',
                'OrderID',
            ),
        );
        if (array_key_exists($requestType, $fields)) {
            $result = 'MerchantID=' . Configuration::get('PAYMENTSENSE_GATEWAYID') .
                '&Password=' . Configuration::get('PAYMENTSENSE_GATEWAYPASS');
            foreach ($fields[$requestType] as $field) {
                $result .= '&' . $field . '=' . Tools::getValue($field);
            }
        }
        return $result;
    }

    /**
     * Gets order state based on transaction status code
     *
     * @param string $statusCode Transaction status code
     * @param string $prevStatusCode Previous transaction status code
     *
     * @return string
     */
    public function getOrderState($statusCode, $prevStatusCode)
    {
        switch ($statusCode) {
            case self::TRX_RESULT_SUCCESS:
                $orderState = Configuration::get('PS_OS_WS_PAYMENT');
                break;
            case self::TRX_RESULT_DUPLICATE:
                if ($prevStatusCode === self::TRX_RESULT_SUCCESS) {
                    $orderState = Configuration::get('PS_OS_WS_PAYMENT');
                } else {
                    $orderState = Configuration::get('PS_OS_ERROR');
                }
                break;
            default:
                $orderState = Configuration::get('PS_OS_ERROR');
        }
        return $orderState;
    }

    /**
     * Retrieves the Cart ID from the OrderID POST variable received by the payment gateway
     *
     * @return int
     */
    public function retrieveCartID()
    {
        $orderId = Tools::getValue('OrderID');
        return (int) Tools::substr($orderId, strpos($orderId, '~') + 1);
    }

    /**
     * Determines whether the order is paid
     *
     * @param OrderCore $order
     *
     * @return bool
     */
    public function isOrderPaid($order)
    {
        $result = false;
        $paidStatuses = array(
            Configuration::get('PS_OS_WS_PAYMENT'),
            Configuration::get('PS_OS_PAYMENT'),
            Configuration::get('PS_OS_OUTOFSTOCK_PAID')
        );
        if (Validate::isLoadedObject($order)) {
            $result = in_array($order->getCurrentState(), $paidStatuses);
        }
        return $result;
    }

    /**
     * Updates the order
     *
     * @param int $cartId
     * @return bool
     *
     * @throws Exception
     */
    public function updateOrder($cartId)
    {
        $orderState = $this->getOrderState(
            Tools::getValue('StatusCode'),
            Tools::getValue('PreviousStatusCode')
        );
        $amountPaid = (float) Tools::getValue('Amount') / 100;
        $message = (Tools::getValue('StatusCode') === self::TRX_RESULT_DUPLICATE)
            ? Tools::getValue('PreviousMessage')
            : Tools::getValue('Message');
        $cart = new Cart($cartId);
        $customer = new Customer((int)$cart->id_customer);
        return $this->validateOrder(
            $cartId,
            $orderState,
            $amountPaid,
            $this->displayName,
            $message,
            array(),
            null,
            false,
            $customer->secure_key
        );
    }
}
