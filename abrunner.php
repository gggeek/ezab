<?php
/**
 * A script to be used for load testing scenarios.
 * It uses Apache Bench (ab) to test a set of urls for many iterations, with
 * varying concurrency count and/or urls.
 * All ab options are supported (eg. using or not keepalives).
 * It writes in a dedicated output directory both detailed output and a summary file,
 * as well as a csv file that is easy to use for producing graphs.
 *
 * @author G. Giunta
 * @license GNU GPL 2.0
 * @copyright (C) G. Giunta 2012-2019
 *
 * @todo add more cli options: verbosity
 * @todo AB only does http 1.0 requests; it would be nice to use siege, which can do http 1.1 - workaround: use ezab.php
 * @todo for custom options, we should support the user using many times the same option (eg. -H for siege)
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
        die( "Sorry, web interface not yet developed..." );
        // parse options in array format (die with help msg if needed)
        //$ab->parseOpts( $_GET );
    }
    $ab->run();
}

class ABRunner
{
    static $version = '0.1';
    static $defaults = array(
        // 'real' options
        'label' => '',
        'server' => 'http://localhost',
        'urls' => 'index.php',
        'urlsfile' => '',
        'repetitions' => 100,
        'concurrencies' => '1 10',
        'dognuplot' => false,
        'doaggregategraph' => false,
        'ab' => 'ab',
        'summary_file' => 'summary.txt',
        'output_dir' => 'test_logs',
        'sleep' => 1,

        // 'internal' options
        'verbosity' => 1,
        'self' => __FILE__,
        'outputformat' => 'text',
        'haltonerrors' => true,
        'command' => 'runtests',
        'abopts' => array()
    );
    // config options for this instance
    protected $opts = array();
    // command-line switches to ab that we cannot let the user pass on by himself
    static $ignoredabargs = array( 'n', 'c', 'v', 'w', 'V', 'd', 's', 'g', 'h' );

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
     * @throws Exception
     */
    public function run()
    {
        switch ( $this->opts['command'] )
        {
            case 'runtests':
                $this->runTests();
                return;
            case 'versionmsg':
                echo $this->versionMsg();
                break;
            case 'helpmsg':
                echo $this->helpMsg();
                break;
            default:
                $this->abort( 1 , 'Unknown running mode: ' . $this->opts['command'] );
        }
    }

    /**
     * @throws Exception
     */
    public function runTests()
    {
        $opts = $this->opts;

        //$outfile = $opts['output_dir'] . '/' . $opts['summary_file'];
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

        if ( $opts['urlsfile'] != '' )
        {
            if ( !is_readable( $opts['urlsfile'] ) )
            {
                $this->abort( 1, "Can not read urls file {$opts['urlsfile']}" );
            }
            $urls = file( $opts['urlsfile'], FILE_IGNORE_NEW_LINES & FILE_SKIP_EMPTY_LINES );
        }
        else
        {
            $urls = explode( ' ', $opts['urls'] );
        }

        $start = date( DATE_RFC2822 );

        $this->echoMsg( "### Start Time: $start\n" );

        $this->logMsg( $this->versionMsg( true ) );
        $this->logMsg( "### Start Time: $start" );

        if ( $opts['label'] != '' )
        {
            $this->echoMsg( "### Label: {$opts['label']}\n" );
            $this->logMsg( "### Label: {$opts['label']}" );
        }

        $concurrencies = explode( ' ', $opts['concurrencies'] );

        foreach( $urls as $i => $url )
        {
            $url = trim( $url );
            if ( $url == '' )
            {
                continue;
            }

            if ( $this->opts['doaggregategraph'] )
            {
                // in case we change url, but keep concurrency fixed, create a single aggregate graph
                if ( $i == 0 || count( $concurrencies ) > 1 )
                {
                    $aggfilename = $this->opts['output_dir'] . '/' . str_replace( array( '/', '?', '&', '=', '#' ), '_', "{$this->opts['label']}{$url}" ) . '.csv';
                    $header = "Concurrency;Requests per second;Time per request ms (mean);Time per request (90%);Time per request (min);Time per request (max);Time per request (median);Time per request (sd);Transfer rate Kb/s;Time taken;Completed;Failed;Non-2xx;URL\n";
                    file_put_contents( $aggfilename, $header );
                }
            }
            else
            {
                $aggfilename = '';
            }

            foreach( $concurrencies as $concurrency )
            {
                if ( (int) $concurrency <= 0 )
                {
                    continue;
                }
                $logfilename = $this->opts['output_dir'] . '/' . str_replace( array( '/', '?', '&', '=', '#' ), '_', "{$this->opts['label']}{$url}_c{$concurrency}" );
                $this->runABTest( $ab, $url, $concurrency, $logfilename, $aggfilename );
                sleep( $opts['sleep'] );
            }
        }

        $end = date( DATE_RFC2822 );

        $this->logMsg( "" );
        $this->logMsg( "### End Time: $end" );
        $this->logMsg( "" );

        $this->echoMsg( "\n" );
        $this->echoMsg( "### End Time: $end\n" );
        $this->echoMsg( "### Summary available in file: " . $opts['output_dir'] . '/' . $opts['summary_file'] . "\n" );
        if ( $aggfilename != '' )
        {
            $this->echoMsg( "### Aggregate data available in file: $aggfilename\n" );
        }
    }

    protected function runABTest( $ab, $url, $concurrency, $logfilename, $aggfilename='' )
    {
        $gnuplot = '';
        if ( $this->opts['dognuplot'] )
        {
            $gnuplot = '-g ' . escapeshellarg( $logfilename . '.csv' ) . ' ';
        }
        // we scale total requests with concurrency, to always have the same number of requests per client
        $total = $concurrency * $this->opts['repetitions'];
        $uri = rtrim( $this->opts['server'], '/' ) . '/' . ltrim( $url, '/' );
        // rebuild extra options for ab
        $extra = array();
        foreach ( $this->opts['abopts'] as $opt => $val )
        {
            if ( $val === true )
            {
                $extra[] = '-' . $opt;
            }
            else
            {
                $extra[] = '-' . $opt . ' ' . escapeshellarg( $val );
            }
        }
        $extra = !empty( $extra ) ? ( implode( ' ', $extra ) . ' ' ) : '';
        $args =  "-n $total -c $concurrency " . $gnuplot . $extra . escapeshellarg( $uri );
        $msg = "Testing $uri, concurrency: $concurrency, iterations: $total";

        $this->echoMsg( "\n" );
        $this->echoMsg( $msg . "\n" );
        $this->logMsg( "" );
        $this->logMsg( $msg );
        $this->logMsg( "Command: $ab $args" );

        exec( escapeshellcmd( $ab ) . ' ' . $args, $out, $retcode );
        $out = implode( "\n", $out );
        file_put_contents( $logfilename . '.txt', "$ab $args\n\n" );
        file_put_contents( $logfilename . '.txt', $out, FILE_APPEND );

        if ( $retcode !== 0 )
        {
            $this->echoMsg( "WARNING Error in executing ab. Hostname is possibly wrong\n", 0 );
            return;
        }

        if ( !preg_match( '/^Transfer rate:/m', $out ) )
        {
            $this->echoMsg( "WARNING Error in executing ab. Command output unexpected/incomplete - check it in $logfilename.txt\n", 0 );
            return;
        }

        preg_match( '/^Requests per second: +([0-9.]+).*$/m', $out, $matches );
        $this->logMsg( $matches[0] );
        $rps = $matches[1];
        preg_match( '/^Time per request: +([0-9.]+).+\(mean\).*$/m', $out, $matches );
        $this->logMsg( $matches[0] );
        $tpr = $matches[1];
        preg_match( '/^Failed requests: +(\d+).*$/m', $out, $matches );
        $this->logMsg( $matches[0] );
        $failed = $matches[1];
        $non2xx = 0;
        if ( preg_match( '/^Non-2xx responses: +(\d+).*$/m', $out, $matches ) )
        {
            $this->logMsg( $matches[0] );
            $non2xx = $matches[1];
        }

        if ( $non2xx == $total )
        {
            $this->echoMsg( "WARNING All responses received non 200/OK. Url is most likely wrong\n", 0 );
        }
        if ( array_key_exists( 'k', $this->opts['abopts'] ) )
        {
            preg_match( '/^Keep-Alive requests:.+$/m', $out, $matches ) && $this->logMsg( $matches[0] );
            if ( preg_match( '/^Keep-Alive requests: +(\d+).*?$/m', $out, $matches ) && $matches[1] === '0' )
            {
                $this->echoMsg( "WARNING No keep-alive responses received. Keep-alive most likely disabled in server\n", 0 );
            }
        }

        if ( $aggfilename )
        {
            preg_match( '/^Time taken for tests: +([0-9.]+).*$/m', $out, $matches );
            $time = $matches[1];
            preg_match( '/^Complete requests: +(\d+).*$/m', $out, $matches );
            $completed = $matches[1];
            preg_match( '/^Transfer rate: +(\d+).*$/m', $out, $matches );
            $tr = $matches[1];
            preg_match( '/^Total: +([0-9.]+) +([0-9.]+) +([0-9.]+) +([0-9.]+) +([0-9.]+).*$/m', $out, $matches );
            if ( $total == 1 )
            {
                $ninety = $matches[1];
            }
            else
            {
                preg_match( '/^ +90% +([0-9.]+).*$/m', $out, $nmatches );
                $ninety = $nmatches[1];
            }
            $data = array( $concurrency, $rps, $tpr, $ninety, $matches[1], $matches[5], $matches[4], $matches[3], $tr, $time, $completed, $failed, $non2xx, $url );

            file_put_contents( $aggfilename, implode( ';', $data ) . "\n", FILE_APPEND );
        }
    }

    /**
     * @param string $ab
     * @return string
     * @throws Exception
     */
    protected function getABExecutable( $ab='ab' )
    {
        do
        {
            $output = array();
            exec( escapeshellcmd( $ab ) . ' -V', $output );
            if ( count( $output ) && ( strpos( $output[0], 'ApacheBench' ) !== false || strpos( $output[0], 'eZAB' ) !== false ) )
            {
                return $ab;
            }
            if ( php_sapi_name() == 'cli' && function_exists( 'readline' ) )
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
     * Nb: pre-existing options are not reset by this call.
     * @throws Exception
     */
    public function parseArgs( $argv )
    {
        $options = array(
            's', 'u', 'h', 'help', 'c', 'r', 'l', 'g', 'a', 'V', 'ab', 'f', 'm', 'w'
        );
        $singleoptions = array( 'h', 'help', 'g', 'a', 'V' );

        $longoptions = array();
        foreach( $options as $o )
        {
            if ( strlen( $o ) > 1 )
            {
                $longoptions[] = $o;
            }
        }

        $argc = count( $argv );

        // symlinks, etc... trust $argv[0] better than __FILE__
        $this->opts['self'] = @$argv[0];

        // this->opts has already been initialized by constructor
        $opts = $this->opts;

        for ( $i = 1; $i < $argc; $i++ )
        {
            if ( $argv[$i][0] == '-' )
            {
                $opt = ltrim( $argv[$i], '-' );
                $val = null;

                // This option is forwarded directly to ab.
                // For these, we do not try to validate the ones which need a value,
                // like we do for the options to this script
                if ( strpos( $opt, 'ab_' ) === 0 )
                {
                    $opt = substr( $opt, 3 );
                    if ( in_array( $opt, self::$ignoredabargs) )
                    {
                        $this->echoMsg( "WARNING Can not pass to ab directly the option $opt\n", 0 );
                        continue;
                    }
                    if ( $i+1 < $argc && @$argv[$i+1][0] != '-' )
                    {
                        $opts['abopts'][$opt] = $argv[$i+1];
                        $i++;
                    }
                    else
                    {
                        $opts['abopts'][$opt] = true;
                    }
                    continue;
                }

                /// @todo we should also allow long options with no space between opt and val
                if ( !in_array( $opt, $longoptions ) && strpos( $opt, 'ab_' ) !== 0 )
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
                    case 'g':
                        $opts['dognuplot'] = true;
                        break;
                    case 'a':
                        $opts['doaggregategraph'] = true;
                        break;
                    case 'ab':
                        $opts['ab'] = $val;
                        break;
                    case 'f':
                        $opts['urlsfile'] = $val;
                        break;
                    case 'm':
                        $opts['summary_file'] = $val;
                        break;
                    case 'd':
                        $opts['output_dir'] = $val;
                        break;
                    case 'w':
                        $opts['sleep'] = (int)$val;
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
        $out .= "    {$d}f file          File with list of urls to test (alternative to -u)\n";
        $out .= "    {$d}c concurrencies List of concurrent clients to use. Use double quotes around, separate them with spaces. Defaults to \"1 10\"\n";
        $out .= "    {$d}r repetitions   The number of times each client requests each url. Defaults to 100\n";
        $out .= "    {$d}w seconds       The time to wait between each run. Defaults to 1\n";
        $out .= "    {$d}l label         Use a label for this test run. Will be used as prefix for all output filenames except summary\n";
        $out .= "    {$d}m summary_file  Name for summary file. Defaults to summary.txt\n";
        $out .= "    {$d}d output_dir    Name for ouput dir. Defaults to test_logs\n";
        $out .= "    {$d}g               Save gnuplot detail files too (allows graphing results of every ab invocation)\n";
        $out .= "    {$d}a               Save aggregate results in a csv file (one per url)\n";
        $out .= "    {$d}ab path/to/ab   Path to ApacheBench\n";
        $out .= "    {$d}ab_<xx> [val]   Pass to ApacheBench option xx with value \"val\"\n";

        $out .= "    {$d}h               Display usage information (this message)\n";
        if ( $this->opts['outputformat'] == 'html' )
        {
            $out .= '</pre>';
        }
        return $out;
    }

    function versionMsg( $forceplaintext=false )
    {
        $out = '';
        if ( $this->opts['outputformat'] == 'html' && !$forceplaintext )
            $out .= '<pre>';
        $out .=  "This is ABRunner, Version " . self::$version . "\n";
        $out .= "Copyright 2012-2019 G. Giunta, eZ Systems, http://ez.no\n";
        if ( $this->opts['outputformat'] == 'html' && !$forceplaintext )
            $out .= '</pre>';
        return $out;
    }

    /**
     * Either exits or throws an exception
     * @throws Exception
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
