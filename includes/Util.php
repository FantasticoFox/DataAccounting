<?php

global $da_config_filename;
$da_config_filename = '/var/www/html/data_accounting_config.json';

function getHashSum($inputStr) {
    if ($inputStr == '') {
        return '';
    }
    return hash("sha3-512", $inputStr, false);
}

function generateDomainId() {
    $randomval = '';
    for( $i=0; $i<64; $i++ ) {
        $randomval .= chr(rand(65, 90));
    }
    $domain_id = getHashSum($randomval);
    //print $domain_id;
    return $domain_id;
}

function getDomainId() {
    global $wgDADomainID;
    return $wgDADomainID;
}

function getRdmHash() {
    $randomval = '';
    for( $i=0; $i<64; $i++ ) {
        $randomval .= chr(rand(65, 90));
    }
    $seed = getHashSum($randomval);
    //print $domain_id;
    return $seed;
}

function setDataAccountingConfig($data) {
    global $da_config_filename;
    if (!file_exists($da_config_filename)) {
        $da_config = $data;
    } else {
        $content = file_get_contents($da_config_filename);
        $da_config = json_decode($content, true);
        $dataArray = json_decode(json_encode($data));
        foreach ($dataArray as $key => $value) {
            $da_config[$key] = $value;
        }
    }
    file_put_contents($da_config_filename, json_encode($da_config));
}

function getDataAccountingConfig() {
    //*todo* validate domain_id
    global $da_config_filename;
    if (!file_exists($da_config_filename)) {
        $domain_id = generateDomainId();
        $da_config = [
            'domain_id' => $domain_id,
            'smartcontractaddress' => '0x45f59310ADD88E6d23ca58A0Fa7A55BEE6d2a611',
            'witnessnetwork' => 'Goerli Test Network',
        ];
        file_put_contents($da_config_filename, json_encode($da_config));
    } else {
        $content = file_get_contents($da_config_filename);
        $da_config = json_decode($content, true);
    }
    return $da_config;
}
