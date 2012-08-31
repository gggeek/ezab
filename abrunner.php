<?php
/**
 * A script to be of use for load testing scenarios.
 * It uses Apache bench (ab) to test a set of urls, with N iterations, writing
 * both detailed output and a summary file in a dedicated output directory.
 * Every test can be run both with and without keepalives
 *
 * @author G. Giunta
 * @license GNU GPL 2.0
 * @copyright (C) G. Giunta 2012
 *
 * @todo add more cli options: sleep time, output dir and filename, session cookie, basic auth, etc...
 * @todo AB only does http 1.0 requests; it would be nice to use siege, which can do http 1.1 (or even php which uses curl)
 * @todo use -r option for ab to ignore timeout errors (but option does not exist on older ab, have to test version first)
 */

if ( !defined( 'ABRUNNER_AS_LIB' ) )
{
    $ab = new ABRunner();
    if ( php_sapi_name() == 'cli' )
    {
        // parse cli options (die with help msg if needed)
        $ab->parseArgs( $argv );
    }
    else
    {
        die ( "Sorry, web interface not yet developed..." );
        // parse options in array format (die with help msg if needed)
        $ab->parseOpts( $_GET );
    }
    $ab->run();
}

class ABRunner
{
    static $version = '0.1-dev';
    static $defaults = array(
        // 'real' options
        'summary_file' => 'summary.txt',
        'label' => '',
        'server' => 'http://localhost',
        'urls' => 'index.php',
        'repetitions' => 100,
        'concurrencies' => '1 10',
        'dokeepalives' => true,
        'donokeepalives' => true,
        'dognuplot' => false,
        'doaggregategraph' => false,

        // 'internal' options
        'ab' => 'ab',
        'output_dir' => 'test_logs',
        'sleep' => 1,
        'verbosity' => 1,
        'self' => __FILE__,
        'outputformat' => 'text',
        'haltonerrors' => true,
        'command' => 'runtests'
    );
    // config options for this instance
    protected $opts = array();

    function __construct( $opts = array() )
    {
        $this->opts = self::$defaults;
        $this->opts['outputformat'] = ( php_sapi_name() == 'cli' ) ? 'text' : 'html';
        $this->opts['haltonerrors'] = !defined( 'EZAB_AS_LIB' );
        $this->opts = array_merge( $this->opts, $opts );
    }

    /**
     * Actual execution of the test
     * Depending on options, calls runTests or echoes help messages
     */
    public function run()
    {
        switch ( $this->opts['command'] )
        {
            case 'runtests':
                return $this->runTests();
            case 'versionmsg':
                echo $this->versionMsg();
                break;
            case 'helpmsg':
                echo $this->helpMsg();
                break;
            default:
                $this->abort( 1 , 'Unkown running mode: ' . $this->opts['command'] );
        }
    }

    public function runTests()
    {
        $opts = $this->opts;

        $outfile = $opts['output_dir'] . '/' . $opts['summary_file'];
        if ( !is_dir( $opts['output_dir'] ) )
        {
            mkdir( $opts['output_dir'] ) || $this->abort( 1, "can not create directory for output: {$opts['output_dir']}" );
        }

        if ( $opts['verbosity'] > 1 )
        {
            echo $this->versionMsg();
        }
        if ( $opts['outputformat'] == 'html' && $opts['verbosity'] > 1 )
        {
            echo '<pre>';
        }

        $ab = $this->getABExecutable( $opts['ab'] );

        $start = date( DATE_RFC2822 );

        $this->echoMsg( "### Start Time: $start\n" );

        $this->logMsg( $this->versionMsg( true ) );
        $this->logMsg( "### Start Time: $start" );

        if ( $opts['label'] != '' )
        {
            $this->echoMsg( "### Label: {$opts['label']}\n" );
            $this->logMsg( "### Label: {$opts['label']}" );
        }

        foreach( explode( ' ', $opts['urls'] ) as $url )
        {
            if ( $url == '' )
            {
                continue;
            }

            $aggfilename = '';
            if ( $this->opts['doaggregategraph'] )
            {
                $aggfilename = $this->opts['output_dir'] . '/' . str_replace( '/', '_', "{$this->opts['label']}{$url}_" );
                $header = "Concurrency;Requests per second;Time per request ms (mean);Time per request (90%);Time per request (min);Time per request (max);Time per request (median);Time per request (sd);Transfer rate Kb/s;Time taken;Completed;Failed;Non-2xx\n";
                if ( $opts['dokeepalives'] )
                    file_put_contents( $aggfilename . 'k.csv', $header );
                if ( $opts['donokeepalives'] )
                    file_put_contents( $aggfilename . 'nk.csv', $header );
            }

            foreach( explode( ' ', $opts['concurrencies'] ) as $concurrency )
            {
                if ( (int) $concurrency <= 0 )
                {
                    continue;
                }
                if ( $opts['donokeepalives'] )
                {
                    $this->runTest( $ab, $url, $concurrency, false, $aggfilename . 'nk.csv' );
                    sleep( $opts['sleep'] );
                }
                if ( $opts['dokeepalives'] )
                {
                    $this->runTest( $ab, $url, $concurrency, true, $aggfilename . 'k.csv' );
                    sleep( $opts['sleep'] );
                }
            }
        }

        $end = date( DATE_RFC2822 );

        $this->logMsg( "" );
        $this->logMsg( "### End Time: $end" );
        $this->logMsg( "" );

        $this->echoMsg( "\n" );
        $this->echoMsg( "### End Time: $end\n" );
        $this->echoMsg( "### Summary available in " . $opts['output_dir'] . '/' . $opts['summary_file'] );
    }

    protected function runTest( $ab, $url, $concurrency, $keepalive=true, $aggfilename='' )
    {
        $filename = $this->opts['output_dir'] . '/' . str_replace( '/', '_', "{$this->opts['label']}{$url}_c{$concurrency}_" ) . ( $keepalive ? 'k' : 'nk' ) ;
        $gnuplot = '';
        if ( $this->opts['dognuplot'] )
        {
            $gnuplot = ' -g ' . escapeshellarg( $filename . '.csv' ) . ' ';
        }
        $total = $concurrency * $this->opts['repetitions'];
        $uri = rtrim( $this->opts['server'], '/' ) . '/' . ltrim( $url, '/' );
        $args =  "-n $total -c $concurrency" . ( $keepalive ? ' -k ' : ' ' ) . $gnuplot . escapeshellarg( $uri );
        $msg = "Testing $uri, concurrency: $concurrency, iterations: $total (" . ( $keepalive ? '' : 'no ' ) . "keepalive)";

        $this->echoMsg( "\n" );
        $this->echoMsg( $msg . "\n" );
        $this->logMsg( "" );
        $this->logMsg( $msg );
        $this->logMsg( "Command: $ab $args" );

        exec( escapeshellcmd( $ab ) . ' ' . $args . " > " . $filename . '.txt', $resp, $retcode );

        $out = file_get_contents( $filename . '.txt' );

        preg_match( '/^Requests per second: +([0-9.]+).+$/m', $out, $matches );
        $this->logMsg( $matches[0] );
        $rps = $matches[1];
        preg_match( '/^Time per request: +([0-9.]+).+\(mean\).+$/m', $out, $matches );
        $this->logMsg( $matches[0] );
        $tpr = $matches[1];
        preg_match( '/^Failed requests: +(\d+).+$/m', $out, $matches );
        $this->logMsg( $matches[0] );
        $failed = $matches[1];

        $non2xx = 0;
        if ( preg_match( '/^Non-2xx responses: +(\d+).+$/m', $out, $matches ) )
        {
            $this->logMsg( $matches[0] );
            $non2xx = $matches[1];
        }


        if ( $non2xx == $total )
        {
            $this->echoMsg( "WARNING ALL responses received non 200/OK. Url is most likely wrong" );
        }
        if ( $keepalive )
        {
            preg_match( '/^Keep-Alive requests:.+$/m', $out, $matches ) && $this->logMsg( $matches[0] );
            if ( preg_match( '/^Keep-Alive requests: +(\d+).+?$/m', $out, $matches ) && $matches[1] === '0' )
            {
                $this->echoMsg( "WARNING No keep-alive responses received. Keep-alive most likely disabled in server\n" );
            }
        }
        if ( $aggfilename )
        {
            preg_match( '/^Time taken for tests: +([0-9.]+).+$/m', $out, $matches );
            $time = $matches[1];
            preg_match( '/^Complete requests: +(\d+).+$/m', $out, $matches );
            $completed = $matches[1];
            preg_match( '/^Transfer rate: +(\d+).+$/m', $out, $matches );
            $tr = $matches[1];
            preg_match( '/^ +90% +([0-9.]+).+$/m', $out, $matches );
            $ninety = $matches[1];
            preg_match( '/^Total: +([0-9.]+) +([0-9.]+) +([0-9.]+) +([0-9.]+) +([0-9.]+).+$/m', $out, $matches );
            $data = array( $concurrency, $rps, $tpr, $ninety, $matches[1], $matches[5], $matches[4], $matches[3], $tr, $time, $completed, $failed, $non2xx );

            file_put_contents( $aggfilename, implode( ';', $data ) . "\n", FILE_APPEND );
        }
    }

    protected function getABExecutable( $ab='ab' )
    {
        $validExecutable = false;
        do
        {
            $output = array();
            exec( escapeshellcmd( $ab ) . ' -V', $output );
            if ( count( $output ) && strpos( $output[0], 'ApacheBench' ) !== false )
            {
                return $ab;
            }
            if ( php_sapi_name() == 'cli' )
            {
                $input = readline( 'Enter path to ApacheBench executable ( or [q] to quit )' );
                if ( $input === 'q' )
                {
                    $this->abort( 0 );
                }
            }
            else
            {
                $this->abort( 1, "Can not run ApacheBench (executable: $ab)" );
            }
        } while( true );
    }

    /**
     * Parses args in argc/argv format (stores them, unless -h or -V are found, in which case only $this->opts['self'] is set)
     * If any unknown option is found, prints help msg and exit.
     * Nb: pre-existing otions are not reset by this call.
     */
    public function parseArgs( $argv )
    {
        $options = array(
            's', 'u', 'h', 'help', 'c', 'r', 'l', 'n', 'k',  'g', 'a', 'V', 'ab'
        );
        $singleoptions = array( 'h', 'help', 'n', 'k', 'g', 'a', 'V' );

        $longoptions = array();
        foreach( $options as $o )
        {
            if ( strlen( $o ) > 1 )
            {
                $longoptions[] = $o;
            }
        }

        $argc = count( $argv );
        /*if ( $argc < 2 )
        {
            echo "ab: wrong number of arguments\n";
            echo $this->helpMsg( @$argv[0] );
            $this->abort( 1 );
        }*/

        // symlinks, etc... trust $argv[0] better than __FILE__
        //$this->opts['self'] = @$argv[0];

        // this->opts has already been initialized by constructor
        $opts = $this->opts;

        for ( $i = 1; $i < $argc; $i++ )
        {
            if ( $argv[$i][0] == '-' )
            {
                $opt = ltrim( $argv[$i], '-' );
                $val = null;

                /// @todo we should also allow long options with no space between opt and val
                if ( !in_array( $opt, $longoptions ) )
                {
                    if ( strlen( $opt ) > 1 )
                    {
                        $val = substr( $opt, 1 );
                        $opt = $opt[0];
                    }
                }

                if ( !in_array( $opt, $options ) )
                {
                    // unknown option
                    echo $this->helpMsg();
                    $this->abort( 1 );
                }

                if ( $val === null && !in_array( $opt, $singleoptions ) )
                {
                    $val = @$argv[$i+1];
                    if ( $val[0] == '-' )
                    {
                        // two options in a row: error
                        echo $this->helpMsg();
                        $this->abort( 1 );
                    }
                    $i++;
                }

                switch( $opt )
                {
                    case 'h':
                    case 'help':
                        $this->opts['command'] = 'helpmsg';
                        return;
                    case 'V':
                        $this->opts['command'] = 'versionmsg';
                        return;

                    case 's':
                        $opts['server'] = $val;
                        break;
                    case 'u':
                        $opts['urls'] = $val;
                        break;
                    case 'c':
                        $opts['concurrencies'] = $val;
                        break;
                    case 'r':
                        $opts['repetitions'] = (int)$val > 0 ? (int)$val : 1;
                        break;
                   case 'l':
                        $opts['label'] = $val;
                        break;
                   case 'n':
                        $opts['donokeepalives'] = false;
                        break;
                    case 'k':
                        $opts['dokeepalives'] = false;
                        break;
                    case 'g':
                        $opts['dognuplot'] = true;
                        break;
                    case 'a':
                        $opts['doaggregategraph'] = true;
                        break;
                    case 'ab':
                        $opts['ab'] = $val;
                        break;
                    default:
                        // unknown option
                        echo $this->helpMsg();
                        $this->abort( 1 );
                }
            }
            else
            {
                // end of options: argument
                echo $this->helpMsg();
                $this->abort( 1 );
            }
        }
        $this->opts = $opts;
    }

    protected function echoMsg( $msg, $lvl=1 )
    {
        if ( $lvl <= $this->opts['verbosity'] )
        {
            echo ( $this->opts['outputformat'] == 'html' ) ? htmlspecialchars( $msg ) : $msg;
        }
    }

    protected function logMsg( $msg )
    {
        file_put_contents( $this->opts['output_dir'] . '/' . $this->opts['summary_file'], $msg . "\n", FILE_APPEND );
    }

    /// @todo show different format for help when running from the web
    function helpMsg( $cmd='' )
    {
        if ( $cmd == '' )
        {
            $cmd = $this->opts['self'];
        }
        $out = '';
        if ( $this->opts['outputformat'] == 'html' )
        {
            $out .= '<pre>';
            $out .= "Usage: " . htmlspecialchars( $cmd ) . " ? [option = value &amp;]*\n";
            $d = '';
        }
        else
        {
            $out .= "Usage: $cmd [options]\n";
            $d = '-';
        }
        $out .= "Options are:\n";
        $out .= "    {$d}s server        Server hostname (the prefix for urls below). Defaults to \"http://localhost\"\n";
        $out .= "    {$d}u urls          List of urls to test. Use double quotes around, separate them with spaces. Defaults to \"index.php\"\n";
        $out .= "    {$d}c concurrencies List of concurrent clients to use. Use double quotes around, separate them with spaces. Defaults to \"1 10\"\n";
        $out .= "    {$d}r repetitions   The number of times each client requests each url. Defaults to 100\n";
        $out .= "    {$d}n               do not execute non-keepalive tests\n";
        $out .= "    {$d}k               do not execute keepalive tests\n";
        $out .= "    {$d}l label         Use a label for this test run. Will be used as prefix for output filenames\n";
        $out .= "    {$d}g               Save gnuplot detail files too (allows graphing results of every ab invocation)\n";
        $out .= "    {$d}a               Save aggregate results csv file\n";
        $out .= "    {$d}ab path/to/ab   Path to ApacheBench\n";

        $out .= "    {$d}h               Display usage information (this message)\n";
        /*if ( $this->opts['outputformat'] == 'html' )
        {
            $out .= "    {$d}php            path to php executable\n";
            $out .= '</pre>';
        }*/
        return $out;
    }

    function versionMsg( $forceplaintext=false )
    {
        $out = '';
        if ( $this->opts['outputformat'] == 'html' && !$forceplaintext )
            $out .= '<pre>';
        $out .=  "This is ABRunner, Version " . self::$version . "\n";
        $out .= "Copyright 2012 G. Giunta, eZ Systems, http://ez.no\n";
        if ( $this->opts['outputformat'] == 'html' && !$forceplaintext )
            $out .= '</pre>';
        return $out;
    }

    /**
     * Either exits or throws an exception
     *
     * @todo !important when in web mode, there is little sign that there was an error...
     */
    protected function abort( $errcode=1, $msg='' )
    {
        if ( $this->opts['haltonerrors'] )
        {
            if ( $this->opts['outputformat'] == 'html' )
            {
                echo htmlspecialchars( $msg );
                // in html mode there is little visibility for the fact that this is an error...
                if ( $errcode )
                {
                    echo "<br/><b>ERROR:</b> " . $errcode;
                }
            }
            else
            {
                echo $msg;
            }

            exit( $errcode );
        }
        else
        {
            throw new Exception( $msg, $errcode );
        }
    }
}

?>