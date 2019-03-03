<?php

require_once __DIR__ . '/support/error_handler.php';
require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/src/VECRO.php';

use deemru\WavesKit;
use deemru\VECRO;

if( file_exists( __DIR__ . '/config.php' ) )
    require_once __DIR__ . '/config.php';
else
    require_once __DIR__ . '/config.sample.php';

$VECRO = new VECRO( $wk, $database, $alias, $aliasInit, $aliasRandom, $assetInit, $assetRandom );
$VECRO->run();
