<?php

if (!defined("WHMCS")) {
    die("This file cannot be accessed directly");
}

include_once __DIR__.'/directadmin/LicenseServer.php';

use ReadyDedis\LicenseServer;
use WHMCS\Database\Capsule;

/**
 * Define module related meta data.
 *
 * Values returned here are used to determine module related abilities and
 * settings.
 *
 * @see https://developers.whmcs.com/provisioning-modules/meta-data-params/
 *
 * @return array
 */
function directadmin_licensing_MetaData()
{
    return array(
        'DisplayName' => 'DirectAdmin License Provisioning Module',
        'APIVersion' => '0.3.1', // Use API Version 1.1
        'RequiresServer' => true, // Set true if module requires a server to work
        'DefaultNonSSLPort' => '80', // Default Non-SSL Connection Port
        'DefaultSSLPort' => '443', // Default SSL Connection Port
    );
}

/**
 * Define product configuration options.
 *
 * The values you return here define the configuration options that are
 * presented to a user when configuring a product for use with the module. These
 * values are then made available in all module function calls with the key name
 * configoptionX - with X being the index number of the field from 1 to 24.
 *
 * You can specify up to 24 parameters, with field types:
 * * text
 * * password
 * * yesno
 * * dropdown
 * * radio
 * * textarea
 *
 * Examples of each and their possible configuration parameters are provided in
 * this sample function.
 *
 * @see https://developers.whmcs.com/provisioning-modules/config-options/
 *
 * @return array
 */
function directadmin_licensing_ConfigOptions($params = [])
{

    $servers = Capsule::table('tblservers')->where('type','directadmin_licensing')->where('active',1)->get();
    $server_list[0] = "Select Account";
    $server_data = [];
    foreach($servers as $server){
        $server_list[$server->id] = trim($server->name);
        $server_data[$server->id] = $server;
    }
    if(!isset($server_list) || empty($server_list)){
        echo "<p style='color: red'>No direct admin licensing accounts found!</p>";
        return;
    }
    $options = [
        "DirectAdmin Account" => [
            "Type" => "dropdown",
            "Options" => $server_list
        ]
    ];

    $pid = (int) $_REQUEST['id'];
    $res = Capsule::table('tblproducts')->where('id',$pid)->first();
    if(!empty($res->configoption1) && isset($server_data[$res->configoption1])){
        $server = new LicenseServer($server_data[$res->configoption1]->username,get_server_pass_from_whmcs($server_data[$res->configoption1]->password));
        $products = $server->get_license_products();
        $options['Product'] = [
            'Type' => 'dropdown',
            'Options' => $products,
            'Description' => '',
        ];
    }

    $oslist = [];
    foreach($server->get_os_list() as $id => $name){
        $oslist[] = "$id|$name";
    }
    $oslist = implode(",",$oslist);

    $field = Capsule::table('tblcustomfields')->where('relid',$_REQUEST['id'])->where('fieldname','like','lid%')->first();
    if(!isset($field->id)){
        Capsule::table('tblcustomfields')->insertGetId([
            'relid' => $_REQUEST['id'],
            'fieldname' => "lid|License ID",
            'adminonly' => 'on',
            'showorder' => 'off',
            'fieldtype' => 'text',
            'type' => 'product'
        ]);
    }

    $field = Capsule::table('tblcustomfields')->where('relid',$_REQUEST['id'])->where('fieldname','like','ip%')->first();
    if(!isset($field->id)){
        Capsule::table('tblcustomfields')->insertGetId([
            'relid' => $_REQUEST['id'],
            'fieldname' => "ip|IP Address",
            'adminonly' => 'off',
            'showorder' => 'on',
            'fieldtype' => 'text',
            'type' => 'product',
            'required' => 'on'
        ]);
    }

    $field = Capsule::table('tblcustomfields')->where('relid',$_REQUEST['id'])->where('fieldname','like','os%')->first();
    if(!isset($field->id)){
        Capsule::table('tblcustomfields')->insertGetId([
            'relid' => $_REQUEST['id'],
            'fieldname' => "os|Operating System",
            'adminonly' => 'off',
            'showorder' => 'on',
            'fieldtype' => 'dropdown',
            'fieldoptions' => $oslist,
            'type' => 'product',
            'required' => 'on'
        ]);
    } else {
        Capsule::table('tblcustomfields')->where('id',$field->id)->update(['fieldoptions' => $oslist]);
    }



    return $options;
}

/**
 * Provision a new instance of a product/service.
 *
 * Attempt to provision a new instance of a given product/service. This is
 * called any time provisioning is requested inside of WHMCS. Depending upon the
 * configuration, this can be any of:
 * * When a new order is placed
 * * When an invoice for a new order is paid
 * * Upon manual request by an admin user
 *
 * @param array $params common module parameters
 *
 * @return string "success" or an error message
 * @see https://developers.whmcs.com/provisioning-modules/module-parameters/
 *
 */
function directadmin_licensing_CreateAccount(array $params)
{
    try {
        $server = new LicenseServer($params['serverusername'],$params['serverpassword']);
        $license = $server->create_license($params['customfields']['ip'],$params['configoption2'],$params['domain'],$params["clientsdetails"]["email"],$params['customfields']['os'], $params['domain']);
        if($license['error']){
            throw new \Exception($license['error']);
        }
        $field = Capsule::table('tblcustomfields')->where('relid',$params['pid'])->where('fieldname','like','lid%')->first();
        if(!isset($field->id)){
            $id = Capsule::table('tblcustomfields')->insertGetId([
                'relid' => $params['pid'],
                'fieldname' => "lid|License ID"
            ]);
            Capsule::table('tblcustomfieldsvalues')->insertGetId([
                'fieldid' => $id,
                'relid' => $params['serviceid'],
                'value' => $license['lid']
            ]);
        } else {
            $fieldv = Capsule::table('tblcustomfieldsvalues')->where('fieldid',$field->id)->where('relid',$params['serviceid'])->first();
            if(!isset($fieldv->id)){
                Capsule::table('tblcustomfieldsvalues')->insertGetId([
                    'fieldid' => $field->id,
                    'relid' => $params['serviceid'],
                    'value' => $license['lid']
                ]);
            } else {
                Capsule::table('tblcustomfieldsvalues')->where('id',$fieldv->id)->update([
                    'value' => $license['lid']
                ]);
            }
        }
        $server->set_license_id($license['lid']);
        $server->pay_license();
    } catch (Exception $e) {
        // Record the error in WHMCS's module log.
        logModuleCall(
            'directadmin_licensing',
            __FUNCTION__,
            $params,
            $e->getMessage(),
            $e->getTraceAsString()
        );
        return $e->getMessage();
    }
    return 'success';
}

/**
 * Suspend an instance of a product/service.
 *
 * Called when a suspension is requested. This is invoked automatically by WHMCS
 * when a product becomes overdue on payment or can be called manually by admin
 * user.
 *
 * @param array $params common module parameters
 *
 * @return string "success" or an error message
 * @see https://developers.whmcs.com/provisioning-modules/module-parameters/
 *
 */
function directadmin_licensing_SuspendAccount(array $params)
{
    try {

        $server = new LicenseServer($params['serverusername'],$params['serverpassword']);
        $server->set_license_id($params['customfields']['lid']);
        $server->suspend_license('WHMCS');

    } catch (Exception $e) {
        // Record the error in WHMCS's module log.
        logModuleCall(
            'directadmin_licensing',
            __FUNCTION__,
            $params,
            $e->getMessage(),
            $e->getTraceAsString()
        );
        return $e->getMessage();
    }
    return 'success';
}

/**
 * Un-suspend instance of a product/service.
 *
 * Called when an un-suspension is requested. This is invoked
 * automatically upon payment of an overdue invoice for a product, or
 * can be called manually by admin user.
 *
 * @param array $params common module parameters
 *
 * @return string "success" or an error message
 * @see https://developers.whmcs.com/provisioning-modules/module-parameters/
 *
 */
function directadmin_licensing_UnsuspendAccount(array $params)
{
    try {

        $server = new LicenseServer($params['serverusername'],$params['serverpassword']);
        $server->set_license_id($params['customfields']['lid']);
        $server->unsuspend_license();

    } catch (Exception $e) {
        // Record the error in WHMCS's module log.
        logModuleCall(
            'directadmin_licensing',
            __FUNCTION__,
            $params,
            $e->getMessage(),
            $e->getTraceAsString()
        );
        return $e->getMessage();
    }
    return 'success';
}

/**
 * Terminate instance of a product/service.
 *
 * Called when a termination is requested. This can be invoked automatically for
 * overdue products if enabled, or requested manually by an admin user.
 *
 * @param array $params common module parameters
 *
 * @return string "success" or an error message
 * @see https://developers.whmcs.com/provisioning-modules/module-parameters/
 *
 */
function directadmin_licensing_TerminateAccount(array $params)
{
    try {
        $server = new LicenseServer($params['serverusername'],$params['serverpassword']);
        $server->set_license_id($params['customfields']['lid']);
        $server->delete_license();
    } catch (Exception $e) {
        // Record the error in WHMCS's module log.
        logModuleCall(
            'directadmin_licensing',
            __FUNCTION__,
            $params,
            $e->getMessage(),
            $e->getTraceAsString()
        );
        return $e->getMessage();
    }
    return 'success';
}

function directadmin_licensing_ChangePackage(array $params)
{
    try {
        return 'Module does not support change package function!';
    } catch (Exception $e) {
        // Record the error in WHMCS's module log.
        logModuleCall(
            'directadmin_licensing',
            __FUNCTION__,
            $params,
            $e->getMessage(),
            $e->getTraceAsString()
        );
        return $e->getMessage();
    }
    return 'success';
}

/**
 * Test connection with the given server parameters.
 *
 * Allows an admin user to verify that an API connection can be
 * successfully made with the given configuration parameters for a
 * server.
 *
 * When defined in a module, a Test Connection button will appear
 * alongside the Server Type dropdown when adding or editing an
 * existing server.
 *
 * @param array $params common module parameters
 *
 * @return array
 * @see https://developers.whmcs.com/provisioning-modules/module-parameters/
 *
 */
function directadmin_licensing_TestConnection(array $params)
{
    try {
        $server = new LicenseServer($params['serverusername'],$params['serverpassword']);
        $output = $server->test_connection();
        if(isset($output->email)){
            $success = true;
            $errorMsg = '';
        } else {
            $errorMsg = "Failed to connect to DirectAdmin";
            $errorMsg .= $output->error ? ", Reason: {$output->text}":"";
        }

    } catch (Exception $e) {
        // Record the error in WHMCS's module log.
        logModuleCall(
            'directadmin_licensing',
            __FUNCTION__,
            $params,
            $e->getMessage(),
            $e->getTraceAsString()
        );
        $success = false;
        $errorMsg = $e->getMessage();
    }
    return array(
        'success' => $success,
        'error' => $errorMsg,
    );
}

/**
 * Additional actions an admin user can invoke.
 *
 * Define additional actions that an admin user can perform for an
 * instance of a product/service.
 *
 * @return array
 * @see directadmin_licensing_buttonOneFunction()
 *
 */
function directadmin_licensing_AdminCustomButtonArray()
{
    return [];
}

/**
 * Additional actions a client user can invoke.
 *
 * Define additional actions a client user can perform for an instance of a
 * product/service.
 *
 * Any actions you define here will be automatically displayed in the available
 * list of actions within the client area.
 *
 * @return array
 */
function directadmin_licensing_ClientAreaCustomButtonArray()
{
    return [];
}

/**
 * Admin services tab additional fields.
 *
 * Define additional rows and fields to be displayed in the admin area service
 * information and management page within the clients profile.
 *
 * Supports an unlimited number of additional field labels and content of any
 * type to output.
 *
 * @param array $params common module parameters
 *
 * @return array
 * @see directadmin_licensing_AdminServicesTabFieldsSave()
 *
 * @see https://developers.whmcs.com/provisioning-modules/module-parameters/
 */
function directadmin_licensing_AdminServicesTabFields(array $params)
{
    try {

        $server = new LicenseServer($params['serverusername'],$params['serverpassword']);
        $server->set_license_id($params['customfields']['lid']);
        $license = $server->get_license();
        $oslist = $server->get_os_list();
        $verified = $license['verified'] == 'Y' ? 'Verified' : 'Pending Verification';
        $active = $license['active'] == 'Y' ? 'Active' : $license['suspended'] == 'Y' ? 'Suspended' : 'Inactive';
        $active_label = $license['active'] == 'Y' ? 'success' : $license['suspended'] == 'Y' ? 'warning' : 'danger';
        return [
            'License Information' => "<h3 class=\"text-center\">License Details</h3>
<table class=\"table\">
    <tbody>
    <tr>
        <td>Status</td><td><span class=\"label label-{$active_label}\">{$active}</span></td>
    </tr>
    <tr>
        <td>ID</td><td>{$license['lid']}</td>
    </tr>
    <tr>
        <td>IP</td><td>{$license['ip']}</td>
    </tr>
    <tr>
        <td>Verified</td><td>{$verified}</td>
    </tr>
    <tr>
        <td>Operating System</td><td>{$oslist[$license['os']]}</td>
    </tr>
    <tr>
        <td>Created On</td><td>{$license['start']}</td>
    </tr>
    <tr>
        <td>Expires On</td><td>{$license['expiry']}</td>
    </tr>
    </tbody>
</table>",
            "License Name" => ""
        ];
    } catch (Exception $e) {
        // Record the error in WHMCS's module log.
        logModuleCall(
            'directadmin_licensing',
            __FUNCTION__,
            $params,
            $e->getMessage(),
            $e->getTraceAsString()
        );
        // In an error condition, simply return no additional fields to display.
    }
    return array();
}

/**
 * Execute actions upon save of an instance of a product/service.
 *
 * Use to perform any required actions upon the submission of the admin area
 * product management form.
 *
 * It can also be used in conjunction with the AdminServicesTabFields function
 * to handle values submitted in any custom fields which is demonstrated here.
 *
 * @param array $params common module parameters
 *
 * @see https://developers.whmcs.com/provisioning-modules/module-parameters/
 * @see directadmin_licensing_AdminServicesTabFields()
 */
function directadmin_licensing_AdminServicesTabFieldsSave(array $params)
{
    // Fetch form submission variables.
    $originalFieldValue = isset($_REQUEST['directadmin_licensing_original_uniquefieldname'])
        ? $_REQUEST['directadmin_licensing_original_uniquefieldname']
        : '';
    $newFieldValue = isset($_REQUEST['directadmin_licensing_uniquefieldname'])
        ? $_REQUEST['directadmin_licensing_uniquefieldname']
        : '';
    // Look for a change in value to avoid making unnecessary service calls.
    if ($originalFieldValue != $newFieldValue) {
        try {
            // Call the service's function, using the values provided by WHMCS
            // in `$params`.
        } catch (Exception $e) {
            // Record the error in WHMCS's module log.
            logModuleCall(
                'directadmin_licensing',
                __FUNCTION__,
                $params,
                $e->getMessage(),
                $e->getTraceAsString()
            );
            // Otherwise, error conditions are not supported in this operation.
        }
    }
}

/**
 * Client area output logic handling.
 *
 * This function is used to define module specific client area output. It should
 * return an array consisting of a template file and optional additional
 * template variables to make available to that template.
 *
 * The template file you return can be one of two types:
 *
 * * tabOverviewModuleOutputTemplate - The output of the template provided here
 *   will be displayed as part of the default product/service client area
 *   product overview page.
 *
 * * tabOverviewReplacementTemplate - Alternatively using this option allows you
 *   to entirely take control of the product/service overview page within the
 *   client area.
 *
 * Whichever option you choose, extra template variables are defined in the same
 * way. This demonstrates the use of the full replacement.
 *
 * Please Note: Using tabOverviewReplacementTemplate means you should display
 * the standard information such as pricing and billing details in your custom
 * template or they will not be visible to the end user.
 *
 * @param array $params common module parameters
 *
 * @return array
 * @see https://developers.whmcs.com/provisioning-modules/module-parameters/
 *
 */
function directadmin_licensing_ClientArea(array $params)
{

    $server = new LicenseServer($params['serverusername'], $params['serverpassword']);
    $server->set_license_id($params['customfields']['lid']);
    $license = $server->get_license();
    $oslist = $server->get_os_list();
    try {
        // Call the service's function based on the request action, using the
        // values provided by WHMCS in `$params`.
        return array(
            'overrideDisplayTitle' => 'DirectAdmin License',
            'tabOverviewModuleOutputTemplate' => 'licenseinfo.tpl',
            'templateVariables' => [
                'license' => $license,
                'verified' => $license['verified'] == 'Y' ? 'Verified' : 'Pending Verification',
                'active' => $license['active'] == 'Y' ? 'Active' : $license['suspended'] == 'Y' ? 'Suspended' : 'Inactive',
                'active_label' => $license['active'] == 'Y' ? 'success' : $license['suspended'] == 'Y' ? 'warning' : 'danger',
                'oslist' => $oslist
            ]
        );
    } catch (Exception $e) {
        // Record the error in WHMCS's module log.
        logModuleCall(
            'directadmin_licensing',
            __FUNCTION__,
            $params,
            $e->getMessage(),
            $e->getTraceAsString()
        );
        // In an error condition, display an error page.
        return array(
            'tabOverviewReplacementTemplate' => 'error.tpl',
            'templateVariables' => array(
                'usefulErrorHelper' => $e->getMessage(),
            ),
        );
    }
}

if(!class_exists('hash_encryption')){
    class hash_encryption {
        /**
         * Hashed value of the user provided encryption key
         * @var string
         **/
        var $hash_key;
        /**
         * String length of hashed values using the current algorithm
         * @var int
         **/
        var $hash_lenth;
        /**
         * Switch base64 enconding on / off
         * @var bool    true = use base64, false = binary output / input
         **/
        var $base64;
        /**
         * Secret value added to randomize output and protect the user provided key
         * @var string  Change this value to add more randomness to your encryption
         **/
        var $salt = 'Change this to any secret value you like. "d41d8cd98f00b204e9800998ecf8427e" might be a good example.';


        /**
         * Constructor method
         *
         * Used to set key for encryption and decryption.
         * @param   string  $key    Your secret key used for encryption and decryption
         * @param   boold   $base64 Enable base64 en- / decoding
         * @return mixed
         */
        function hash_encryption($key, $base64 = true) {

            global $cc_encryption_hash;

            // Toggle base64 usage on / off
            $this->base64 = $base64;

            // Instead of using the key directly we compress it using a hash function
            $this->hash_key = $this->_hash($key);

            // Remember length of hashvalues for later use
            $this->hash_length = strlen($this->hash_key);
        }

        /**
         * Method used for encryption
         * @param   string  $string Message to be encrypted
         * @return string   Encrypted message
         */
        function encrypt($string) {
            $iv = $this->_generate_iv();

            // Clear output
            $out = '';

            // First block of output is ($this->hash_hey XOR IV)
            for($c=0;$c < $this->hash_length;$c++) {
                $out .= chr(ord($iv[$c]) ^ ord($this->hash_key[$c]));
            }

            // Use IV as first key
            $key = $iv;
            $c = 0;

            // Go through input string
            while($c < strlen($string)) {
                // If we have used all characters of the current key we switch to a new one
                if(($c != 0) and ($c % $this->hash_length == 0)) {
                    // New key is the hash of current key and last block of plaintext
                    $key = $this->_hash($key . substr($string,$c - $this->hash_length,$this->hash_length));
                }
                // Generate output by xor-ing input and key character for character
                $out .= chr(ord($key[$c % $this->hash_length]) ^ ord($string[$c]));
                $c++;
            }
            // Apply base64 encoding if necessary
            if($this->base64) $out = base64_encode($out);
            return $out;
        }

        /**
         * Method used for decryption
         * @param   string  $string Message to be decrypted
         * @return string   Decrypted message
         */
        function decrypt($string) {
            // Apply base64 decoding if necessary
            if($this->base64) $string = base64_decode($string);

            // Extract encrypted IV from input
            $tmp_iv = substr($string,0,$this->hash_length);

            // Extract encrypted message from input
            $string = substr($string,$this->hash_length,strlen($string) - $this->hash_length);
            $iv = $out = '';

            // Regenerate IV by xor-ing encrypted IV from block 1 and $this->hashed_key
            // Mathematics: (IV XOR KeY) XOR Key = IV
            for($c=0;$c < $this->hash_length;$c++)
            {
                $iv .= chr(ord($tmp_iv[$c]) ^ ord($this->hash_key[$c]));
            }
            // Use IV as key for decrypting the first block cyphertext
            $key = $iv;
            $c = 0;

            // Loop through the whole input string
            while($c < strlen($string)) {
                // If we have used all characters of the current key we switch to a new one
                if(($c != 0) and ($c % $this->hash_length == 0)) {
                    // New key is the hash of current key and last block of plaintext
                    $key = $this->_hash($key . substr($out,$c - $this->hash_length,$this->hash_length));
                }
                // Generate output by xor-ing input and key character for character
                $out .= chr(ord($key[$c % $this->hash_length]) ^ ord($string[$c]));
                $c++;
            }
            return $out;
        }

        /**
         * Hashfunction used for encryption
         *
         * This class hashes any given string using the best available hash algorithm.
         * Currently support for md5 and sha1 is provided. In theory even crc32 could be used
         * but I don't recommend this.
         *
         * @access  private
         * @param   string  $string Message to hashed
         * @return string   Hash value of input message
         */
        function _hash($string) {
            // Use sha1() if possible, php versions >= 4.3.0 and 5
            if(function_exists('sha1')) {
                $hash = sha1($string);
            } else {
                // Fall back to md5(), php versions 3, 4, 5
                $hash = md5($string);
            }
            $out ='';
            // Convert hexadecimal hash value to binary string
            for($c=0;$c<strlen($hash);$c+=2) {
                $out .= $this->_hex2chr($hash[$c] . $hash[$c+1]);
            }
            return $out;
        }

        /**
         * Generate a random string to initialize encryption
         *
         * This method will return a random binary string IV ( = initialization vector).
         * The randomness of this string is one of the crucial points of this algorithm as it
         * is the basis of encryption. The encrypted IV will be added to the encrypted message
         * to make decryption possible. The transmitted IV will be encoded using the user provided key.
         *
         * @todo    Add more random sources.
         * @access  private
         * @see function    hash_encryption
         * @return string   Binary pseudo random string
         **/
        function _generate_iv() {
            // Initialize pseudo random generator
            srand ((double)microtime()*1000000);

            // Collect random data.
            // Add as many "pseudo" random sources as you can find.
            // Possible sources: Memory usage, diskusage, file and directory content...
            $iv  = $this->salt;
            $iv .= rand(0,getrandmax());
            // Changed to serialize as the second parameter to print_r is not available in php prior to version 4.4
            $iv .= serialize($GLOBALS);
            return $this->_hash($iv);
        }

        /**
         * Convert hexadecimal value to a binary string
         *
         * This method converts any given hexadecimal number between 00 and ff to the corresponding ASCII char
         *
         * @access  private
         * @param   string  Hexadecimal number between 00 and ff
         * @return  string  Character representation of input value
         **/
        function _hex2chr($num) {
            return chr(hexdec($num));
        }
    }
}

if(!function_exists('get_server_pass_from_whmcs')){
    function get_server_pass_from_whmcs($enc_pass){
        global $cc_encryption_hash;
        // Include WHMCS database configuration file
        include_once(dirname(dirname(dirname(dirname(dirname(__FILE__))))).'/configuration.php');
        $key1 = md5 (md5 ($cc_encryption_hash));
        $key2 = md5 ($cc_encryption_hash);
        $key = $key1.$key2;
        $hasher = new hash_encryption($key);
        return $hasher->decrypt($enc_pass);
    }
}
