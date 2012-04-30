<?php
/**
* example app using ezab.php
*/

define( 'EZAB_AS_LIB', true );

include( 'ezab.php' );

$ab = new eZAB( array(
    'verbosity' => 0,
    'target' => 'http://localhost/'
) );

$results = $ab->run();

echo "Requests per second: " . $results['summary_data']['rps'];

?>
