<?php

require_once __DIR__ . '/vendor/autoload.php';
use deemru\WavesKit;

$chainId = 'T';
$seed = 'manage manual recall harvest series desert melt police rose hollow moral pledge kitten position add vecro';
$database = __DIR__ . '/vecro.sqlite';
$alias = 'vecrotest';
$aliasInit = "init@$alias";
$aliasRandom = "random@$alias";
$assetInitName = "R-$alias";
$assetRandomName = "S-$alias";

// define explicitly to bypass initVECRO() call
//$assetInit = '21eV9L5qsU2H1jwCbDSkzWUuFyYvfNFaycSjLYPaZLHD';
//$assetRandom = '7ZFZjR18tF1gdmN73oKbMo9FSWLrAz6ocDMXsd13v5oJ';

$mainNode = 'https://testnode4.wavesnodes.com';
$backupNodes = [ 'https://testnode4.wavesnodes.com', 'https://testnode4.wavesnodes.com' ];

define( 'IREALLYKNOWWHAT_RSEED_MEANS', true );

$wk = new WavesKit( $chainId );
$wk->setNodeAddress( $mainNode, 1, $backupNodes );
$wk->setSeed( $seed );
$wk->log( 's', 'VECRO @ ' . $wk->getAddress() );

if( !isset( $assetInit ) || !isset( $assetRandom ) )
{
    $assetInit = null;
    $assetRandom = null;
    $script = file_get_contents( 'vecro.ride' );

    initVECRO( $wk, $alias, $aliasInit, $aliasRandom, $assetInitName, $assetRandomName, $assetInit, $assetRandom, $script );
}

function initVECRO( $wk, $alias, $aliasInit, $aliasRandom, $assetInitName, $assetRandomName, &$assetInit, &$assetRandom, $script )
{
    $aliases = [ $alias, $aliasInit, $aliasRandom ];
    foreach( $aliases as $a )
    {
        if( $wk->getAddress() === ( $address = $wk->getAddressByAlias( $a ) ) )
        {
            $wk->log( 's', "alias \"$a\" OK" );
            continue;
        }

        $wk->log( 'i', "alias binding \"$a\"" );
        $tx = $wk->txAlias( $a );
        $tx['fee'] = $wk->calculateFee( $tx );
        $tx = $wk->txSign( $tx );
        $tx = $wk->txBroadcast( $tx );
        if( $tx === false )
            exit( $wk->log( 'e', "alias binding \"$a\"" ) );
        $wk->log( 's', "$a = {$tx['id']}" );
    }

    $balance = $wk->balance();
    $assetInit = null;
    $assetRandom = null;

    foreach( $balance as $asset => $info )
    {
        if( isset( $info['issueTransaction'] ) && $address === $info['issueTransaction']['sender'] )
        {
            $issueTx = $info['issueTransaction'];

            if( $address === $issueTx['sender'] )
            {
                if( $issueTx['name'] === $assetInitName )
                {
                    $assetInit = $issueTx['id'];
                    $wk->log( 's', "$assetInitName = $assetInit" );
                }
                elseif( $issueTx['name'] === $assetRandomName )
                {
                    $assetRandom = $issueTx['id'];
                    $wk->log( 's', "$assetRandomName = $assetRandom" );
                }
            }
        }
    }

    if( !isset( $assetInit ) )
    {
        $tx = $wk->txIssue( $assetInitName, "$alias`s token for R-value transfering via attachment", 100000000, 0, true );
        $tx['fee'] = $wk->calculateFee( $tx );
        $tx = $wk->txSign( $tx );
        $tx = $wk->txBroadcast( $tx );
        if( $tx === false )
            exit( $wk->log( 'e', "token issue \"$assetInitName\"" ) );
        $assetInit = $tx['id'];
        $wk->log( 's', "$assetInitName = $assetInit" );
    }

    if( !isset( $assetRandom ) )
    {
        $tx = $wk->txIssue( $assetRandomName, "$alias`s token for S-value transfering via attachment", 100000000, 0, true );
        $tx['fee'] = $wk->calculateFee( $tx );
        $tx = $wk->txSign( $tx );
        $tx = $wk->txBroadcast( $tx );
        if( $tx === false )
            exit( $wk->log( 'e', "token issue \"$assetRandomName\"" ) );
        $assetRandom = $tx['id'];
        $wk->log( 's', "$assetRandomName = $assetRandom" );
    }

    if( false === ( $addressScript = $wk->getAddressScript() ) )
    {
        $script = sprintf( $script, $aliasInit, $aliasRandom, $assetInit, $assetRandom );
        if( false === ( $script = $wk->compile( $script ) ) )
        {
            $canProceed = false;
            $wk->log( 'e', "compile failed" );
        }

        $tx = $wk->txSetScript( $script['script'] );
        $tx['fee'] = $wk->calculateFee( $tx );
        $tx = $wk->txSign( $tx );
        $tx = $wk->txBroadcast( $tx );
        if( $tx === false )
            exit( $wk->log( 'e', 'txSetScript' ) );
        $wk->log( 's', "script = {$tx['id']}" );
    }

    if( isset( $addressScript['complexity'] ) )
        $wk->log( 's', "script (complexity:{$addressScript['complexity']}) OK" );
}