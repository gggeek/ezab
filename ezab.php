<?php
/**
 * ezab.php: same as Apache Bench tool, but in php.
 *
 * @author G. Giunta
 * @license GNU GPL 2.0
 * @copyright (C) G. Giunta 2010-2012
 *
 * It uses curl for executing the http requests.
 * It uses a multi-process scheme (tested to be working both on windows and linux):
 * you will need php-cli installed for this to work.
 * It can be run both as command-line script and as web page.
 * It defaults to executing immediately, but you can actually include() it from
 * your app and use it as a plain php class, by defining the EZAB_AS_LIB constant
 * before including this file.
 *
 * @todo allow setting more curl options: timeouts, resp. compression
 * @todo verify if we do proper curl error checking for all cases (404 tested)
 * @todo parse more stats from children (same format as ab does), eg. print connect times, nr. of keepalives etc...
 * @todo check if all our calculation methods are the same as used by ab
 * @todo add some nice graph output, as eg. abgraph does
 * @todo !important add named constants for verbosity levels; review what is ouput at each level (currently used: 1 to 4)
 * @todo !important add an option for a custom dir for traces/logfiles
 * @todo !important raise php timeout if run() is called not from cli
 * @todo !important in web mode, display a form to be filled by user that triggers the request
 */

if ( !defined( 'EZAB_AS_LIB' ) )
{
    if( !function_exists( 'curl_init' ) )
    {
        echo( 'Missing cURL, cannot run' );
        exit( 1 );
    }

    $ab = new eZAB();
    if ( php_sapi_name() == 'cli' )
    {
        // parse cli options (die with help msg if needed)
        $ab->parseArgs( $argv );
    }
    else
    {
        // parse options in array format (die with help msg if needed)
        $ab->parseOpts( $_GET );
    }
    // will run in either parent or child mode, depending on parsed options
    $ab->run();
}

class eZAB
{
    static $version = '0.3-dev';
    static $defaults = array(
        // 'real' options
        /// How much troubleshooting info to print. According to ab docs:
        /// "4 and above prints information on headers, 3 and above prints response codes (404, 200, etc.), 2 and above prints warnings and info."
        /// Real life testing seem to tell a different story though...
        'verbosity' => 1, // -v verbosity
        'children' => 1, // -c concurrency  Number of multiple requests to make
        'tries' => 1, // -n requests     Number of requests to perform
        'timeout' => 0, // -t timelimit    Seconds to max. wait for responses
        'auth' => false,
        'proxy' => false,
        'proxyauth' => false,
        'target' => '',
        'keepalive' => false,
        'head' => false,
        'interface' => '',
        'respencoding' => false,
        'httpversion' => CURL_HTTP_VERSION_NONE,
        'cookies' => array(),

        // 'internal' options
        'childnr' => false,
        'parentid' => false,
        // the actual script path (self)
        'self' => __FILE__,
        'php' => 'php',
        'outputformat' => 'text',
        'haltonerrors' => true,
        'command' => 'runparent' // allowed: 'helpmsg', 'versionmsg', 'runparent', 'runchild'
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
     * Actual execution of the test, echoes results to stdout.
     * Depending on options, calls runParent or runChild, or echoes help messages
     */
    public function run()
    {
        switch ( $this->opts['command'] )
        {
            case 'runparent':
                return $this->runParent();
            case 'runchild':
                echo $this->runChild();
                break;
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

    /**
     * Runs the test, prints results (unless verbosity option has been set to 0).
     * Note: sets a value to $this->opts['parentid'], too
     * @return array
     */
    public function runParent()
    {

        // mandatory option
        if ( $this->opts['target'] == '' )
        {
            echo $this->helpMsg();
            $this->abort( 1 );
        }

        if ( $this->opts['verbosity'] > 1 )
        {
            echo $this->versionMsg();
        }
        if ( $this->opts['outputformat'] == 'html' && $this->opts['verbosity'] > 1 )
        {
            echo '<pre>';
        }

        $this->opts['parentid'] = time() . "." . getmypid(); // make it as unique as possible
        $opts = $this->opts;
        /// @todo shall we do exactly $opts['tries'] tests, making last thread execute more?
        $child_tries = (int) ( $opts['tries'] / $opts['children'] );
        $php = $this->getPHPExecutable( $opts['php'] );

        $this->echoMsg( "Benchmarking {$opts['target']} (please be patient)...\n" );

        // != from ab output
        $this->echoMsg( "\nRunning {$opts['tries']} requests with {$opts['children']} parallel processes\n", 3 );
        $this->echoMsg( "----------------------------------------\n", 3 );

        /// @todo !important move cli opts reconstruction to a separate function
        $args = "--parent " . $opts['parentid'];
        $args .= " -n $child_tries -t " . escapeshellarg( $opts['timeout'] );
        if ( $opts['auth'] != '' )
        {
            $args .= " -A " . escapeshellarg( $opts['auth'] );
        }
        if ( $opts['proxy'] != '' )
        {
            $args .= " -X ". escapeshellarg( $opts['auth'] );
            if ( $opts['proxyauth'] != '' )
            {
                $args .= " -P ". escapeshellarg( $opts['auth'] );
            }
        }
        if ( $opts['keepalive'] )
        {
            $args .= " -k";
        }
        if ( $opts['head'] )
        {
            $args .= " -i";
        }
        if ( $opts['interface'] != '' )
        {
            $args .= " -B " . $opts['interface'];
        }
        if ( $opts['respencoding'] )
        {
             $args .= " -j";
        }
        if ( $opts['httpversion'] == CURL_HTTP_VERSION_1_0 )
        {
            $args .= " -0";
        }
        else if ( $opts['httpversion'] == CURL_HTTP_VERSION_1_1 )
        {
            $args .= " -1";
        }
        foreach( $opts['cookies'] as $c )
        {
             $args .= " -C " . escapeshellarg( $c );
        }
        $args .= " -v " . $opts['verbosity'];
        $args .= " " . escapeshellarg( $opts['target'] );

        //$starttimes = array();
        $pipes = array();
        $childprocs = array();
        $childresults = array();

        //$time = microtime( true );

        // start children
        for ( $i = 0; $i < $opts['children']; $i++ )
        {
            $exec = escapeshellcmd( $php ) . " " . escapeshellarg( $opts['self'] ) . " --child $i " . $args;

            //$starttimes[$i] = microtime( true );

            $pipes[$i] = null;
            $childprocs[$i] = proc_open(
                $exec,
                array( array( 'pipe','r' ), array( 'pipe','w' ), array( 'pipe', 'a' ) ),
                $pipes[$i]
            );
            fclose( $pipes[$i][0] );

            if ( !$childprocs[$i] )
            {
                /// @todo kill all open children, exit

                // before using this line test that other children are terminated ok...
                //$this->abort( 1, "Child process $i did not start correctly. Exiting" );
            }

            $this->echoMsg( "Launched child $i [ $exec ]\n", 3 );
            flush();
        }

        // wait for all children to finish
        /// @todo add a global timeout limit?
        $finished = 0;
        $outputs = array();
        do
        {
            /// @todo !important lower this - use usleep
            sleep( 1 );

            for ( $i = 0; $i < $opts['children']; $i++ )
            {
                if ( $childprocs[$i] !== false )
                {
                    /// @todo see note from Lachlan Mulcahy on http://it.php.net/manual/en/function.proc-get-status.php:
                    ///       to make sure buffers are not blocking children, we should read rom their pipes every now and then
                    ///       (but not on windows, since pipes are blocking and can not be timedout, see https://bugs.php.net/bug.php?id=54717)
                    $status = proc_get_status( $childprocs[$i] );
                    if ( $status['running'] == false )
                    {
                        $childrensults[$i] = array_merge( $status, array(
                            'output' => stream_get_contents( $pipes[$i][1] ),
                            'error' => stream_get_contents( $pipes[$i][2] ),
                            'return' => proc_close( $childprocs[$i] )
                        ) );
                        $childprocs[$i] = false;
                        $finished++;
                    }
                }
            }

            $this->echoMsg( "." );
            if ( $opts['verbosity'] > 1 )
            {
                flush();
            }

        } while( $finished < $opts['children'] );

        //$time = microtime( true ) - $time;

        $srv = '[NA]';
        if ( $opts['verbosity'] >= 2 )
        {
            $out = file_get_contents( basename( $opts['parentid'] ) . '.0.trc' );
            $out = preg_replace( '/^(\*.+)$/m', '', $out );
            $out = preg_replace( '/[\n]+/', "\n", $out );
            $this->echoMsg( $out, 2 );

            if ( preg_match( '/^< Server: +(.+)$/m', $out, $matches ) )
            {
                $srv = $matches[1];
            }

            // for verbose levels 3 and above, keep trace files on disk
            if ( $opts['verbosity'] == 2 )
            {
                for ( $i = 0; $i < $opts['children']; $i++ )
                {
                    @unlink( basename( $opts['parentid'] ) . ".$i.trc" );
                }
            }
        }

        $this->echoMsg( "done\n" );

        // print results

        // != from ab output
        $this->echoMsg( "\nChildren output:\n----------------------------------------\n", 3 );
        for ( $i = 0; $i < $opts['children']; $i++ )
        {
            /// @todo beautify
            $this->echoMsg( $childrensults[$i]['output'] . "\n", 3 );
        }

        $this->echoMsg( "\nChildren details:\n----------------------------------------\n", 4 );
        $this->echoMsg( var_export( $childrensults, true ), 4 );

        $outputs = array();
        foreach( $childrensults as $i => $res )
        {
            if ( $res['exitcode'] != 0 || $res['output'] == '' )
            {
                $this->abort( 1, "Child process $i did not terminate correctly. Exiting" );
            }
            $outputs[$i] = $res['output'];
        }
        $data = $this->parseOutputs( $outputs );

        $this->echoMsg( "\n\n" );

        $this->echoMsg( "\nSummary:\n----------------------------------------\n", 3 );

        $sizes = array_keys( $data['sizes'] );
        $url = parse_url( $opts['target'] );
        if ( @$url['port'] == '' )
        {
            $url['port'] = ( $url['scheme'] == 'https' ? '443' : '80' );
        }
        if ( @$url['path'] == '' )
        {
            $url['path'] = '/';
        }
        $this->echoMsg(
            "Server Software:        {$srv}\n" .
            "Server Hostname:        {$url['host']}\n" .
            "Server Port:            {$url['port']}\n" .
            "\n" .
            "Document Path:          {$url['path']}\n" .
            "Document Length:        " . reset( $sizes ) . " bytes\n" .
            "\n" .
            "Concurrency Level:      {$opts['children']}\n" .
            "Time taken for tests:   " . sprintf( '%.3f', $data['tot_time'] ) . " seconds\n" .
            "Complete requests:      {$data['tries']}\n" . // same as AB: includes failures
            "Failed requests:        {$data['failures']}\n" .
            "Write errors:           {$data['write_errors']}\n" .
            ( $data['non_2xx'] ?   "Non-2xx responses:      {$data['non_2xx']}\n" : '' ) .
            ( $opts['keepalive'] ? "Keep-Alive requests:    [NA]\n" : '' ) .
            "Total transferred:      {$data['tot_bytes']} bytes\n" .
            "HTML transferred:       {$data['html_bytes']} bytes\n" . // NB: includes failures
            "Requests per second:    " . sprintf( '%.2f', $data['rps'] ) . " [#/sec] (mean)\n" . // NB: includes failures
            "Time per request:       " . sprintf( '%.3f', $data['t_avg'] * 1000 ) . " [ms] (mean)\n" . // NB: excludes failures
            "Time per request:       [NA] [ms] (mean, across all concurrent requests)\n" .
            "Transfer rate:          " . sprintf( '%.2f', $data['tot_bytes'] / ( 1024 * $data['tot_time'] ) ) . " [Kbytes/sec] received\n" .
            "\nConnection Times (ms)\n" .
            "              min  mean[+/-sd] median   max\n" .
            //"Connect:     [NA]  [NA]   [NA]   [NA]  [NA]\n" .
            //"Processing:  [NA]  [NA]   [NA]   [NA]  [NA]\n" .
            //"Waiting:     [NA]  [NA]   [NA]   [NA]  [NA]\n" .
            /// @todo better formatting if numbers go over 5 digits (roughly 2 minutes)
            "Total:      " . sprintf( '%5u', $data['t_min'] * 1000 ) . " " . sprintf( '%5u', $data['t_avg'] * 1000 ) . "  " . sprintf( '%5.1f', $data['t_stdddev'] ). "  ". sprintf( '%5u', $data['t_median'] ) . " " . sprintf( '%5u', $data['t_max'] * 1000 ) . "\n" .
            "\nPercentage of the requests served within a certain time (ms)\n" .
            "  50% " . sprintf( '%6u', $data['t_percentiles'][50] ) . "\n" .
            "  66% " . sprintf( '%6u', $data['t_percentiles'][66] ) . "\n" .
            "  75% " . sprintf( '%6u', $data['t_percentiles'][75] ) . "\n" .
            "  80% " . sprintf( '%6u', $data['t_percentiles'][80] ) . "\n" .
            "  90% " . sprintf( '%6u', $data['t_percentiles'][90] ) . "\n" .
            "  95% " . sprintf( '%6u', $data['t_percentiles'][95] ) . "\n" .
            "  98% " . sprintf( '%6u', $data['t_percentiles'][98] ) . "\n" .
            "  99% " . sprintf( '%6u', $data['t_percentiles'][99] ) . "\n" .
            " 100% " . sprintf( '%6u', $data['t_max'] * 1000 ) . " (longest request)"
        );

        return array(
            'summary_data' => $data,
            'children_details' => $childrensults
        );
    }

    /**
    * Executes the http requests, returns a csv string with the collected data
    * @retrun string
    */
    public function runChild()
    {
        $opts = $this->opts;
        $resp = array(
            'tries' => $opts['tries'],
            'failures' => 0, // nr of reqs
            'non_2xx' => 0,
            'write_errors' => 0,
            'tot_time' => 0.0, // secs (float) - time spent executing calls
            'tot_bytes' => 0.0, // bytes (float) - total resp. sizes
            'html_bytes' => 0.0, // bytes (float) - total resp. sizes
            't_min' => -1,
            't_max' => 0,
            't_avg' => 0,
            'begin' => 0.0, // secs (float)
            'end' => 0.0, // secs (float)
            'sizes' => array(), // index: size in bytes, value: nr. of responses received with that size
            'times' => array() // index: time in msec (int), value: nr. of responses received with that time
        );

        //$ttime = microtime( true );
        $curl = curl_init( $opts['target'] );
        if ( $curl )
        {
            curl_setopt( $curl, CURLOPT_RETURNTRANSFER, true );
            // enbale receiving header too. We will need later to split by ourselves headers from body to calculate correct sizes
            curl_setopt( $curl, CURLOPT_HEADER, true );
            curl_setopt( $curl, CURLOPT_USERAGENT, "eZAB " . self::$version );
            if ( $opts['timeout'] > 0 )
            {
                curl_setopt( $curl, CURLOPT_TIMEOUT, $opts['timeout'] );
            }
            if ( $opts['auth'] != '' )
            {
                curl_setopt( $curl, CURLOPT_USERPWD, $opts['auth'] );
            }
            if ( $opts['proxy'] != '' )
            {
                curl_setopt( $curl, CURLOPT_PROXY, $opts['proxy'] );
                if ( $opts['proxyauth'] != '' )
                {
                    curl_setopt( $curl, CURLOPT_PROXYAUTH, $opts['proxy'] );
                }
            }
            if ( !$opts['keepalive'] )
            {
                curl_setopt( $curl, CURLOPT_HTTPHEADER, array( 'Connection: close' ) );
            }
            if ( $opts['head'] )
            {
                curl_setopt( $curl, CURLOPT_NOBODY, true );
            }
            if ( $opts['interface'] != '' )
            {
                curl_setopt( $curl, CURLOPT_INTERFACE, $opts['interface'] );
            }
            if ( defined( 'CURLOPT_ENCODING' ) && $opts['respencoding'] ) // appeared in curl 7.10
            {
                curl_setopt( $curl, CURLOPT_ENCODING, '' );
            }
            curl_setopt( $curl, CURLOPT_HTTP_VERSION, $opts['httpversion'] );
            if ( count( $opts['cookies'] ) )
            {
                curl_setopt( $curl, CURLOPT_COOKIE, implode( '; ', $opts['cookies'] ) );
            }
            if ( $opts['verbosity'] > 1 )
            {
                // We're writing curl data to files instead of piping it back to the parent because:
                // 1. it might be a lot of data, and there are apparently limited buffers php has for pipes
                // 2. on windows reading from pipes is blocking anyway, so we can not have concurrent children
                $logfp = fopen( basename( $opts['parentid'] ) . '.' . $opts['childnr'] . '.trc', 'w' );
                curl_setopt( $curl, CURLOPT_VERBOSE, true );
                curl_setopt( $curl, CURLOPT_STDERR, $logfp );
            }

            for ( $i = 0; $i < $opts['tries']; $i++ )
            {
                $start = microtime( true );
                $result = curl_exec( $curl );
                $stop = microtime( true );

                $time = $stop - $start;
                $resp['tot_time'] = $resp['tot_time'] + $time;
                if ( $time > $resp['t_max'] )
                {
                    $resp['t_max'] = $time;
                }
                if ( $time < $resp['t_min'] || $resp['t_min'] == -1 )
                {
                    $resp['t_min'] = $time;
                }
                if ( $result === false )
                {
                    $resp['failures']++;
                }
                else
                {
                    $info = curl_getinfo( $curl );
                    if ( (int) ( $info['http_code'] / 100 ) != 2 )
                    {
                        $resp['non_2xx']++;
                    }
                    /// @todo check if AB has other cases that this one counted as "write error"
                    /// @see http://www.php.net/manual/en/function.curl-errno.php
                    if ( curl_errno( $curl ) == 23 )
                    {
                        $resp['write_errors']++;
                    }
                    //$tot_size = strlen( $result ); // uncompressed data: not ok with -j option
                    $html_size =  $info['size_download'];
                    $tot_size = $html_size + $info['header_size'];
                    /// @todo if resp. size changes, by default it should be flagged as error (unless option specified)
                    $resp['sizes'][$html_size] = isset( $resp['sizes'][$html_size] ) ? ( $resp['sizes'][$html_size] + 1 ) : 1;
                    $resp['html_bytes'] += (float)$html_size;
                    $resp['tot_bytes'] += (float)$tot_size;
                    $timemsec = (int) ( $time * 1000 );
                    $resp['times'][$timemsec] = isset( $resp['times'][$timemsec] ) ? ( $resp['times'][$timemsec] + 1 ) : 1;
                }
                if ( $i == 0 )
                {
                    $resp['begin'] = $start;
                }
                if ( $i == $resp['tries'] - 1 )
                {
                    $resp['end'] = $stop;
                }
            }
            curl_close( $curl );
        }
        else
        {
            $resp['failures'] = $resp['tries'];
        }
        //$ttime = microtime( true ) - $ttime;

        if ( $opts['verbosity'] > 1 )
        {
            fclose( $logfp );
        }

        if ( $resp['t_min'] == -1 )
        {
            $resp['t_min'] = 0;
        }
        $succesful = $resp['tries'] - $resp['failures'];
        /// @todo check if ab does the same calculation (excluding failures)
        if ( $succesful > 0 )
        {
            $resp['t_avg'] = $resp['tot_time'] / $succesful;
        }

        // use an "almost readable" csv format - not using dots or commas to avoid float problems
        /// @todo !important move to a separate function
        foreach( $resp['sizes'] as $size => $count )
        {
            $resp['sizes'][$size] = $size . '-' . $count;
        }
        ksort( $resp['sizes'] );
        $resp['sizes'] = implode( '/', $resp['sizes'] );

        foreach( $resp['times'] as $time => $count )
        {
            $resp['times'][$time] = $time . '-' . $count;
        }
        ksort( $resp['times'] );
        $resp['times'] = implode( '/', $resp['times'] );

        foreach( $resp as $key => $val )
        {
            $resp[$key] = $key . ':' . $val;
        }
        return implode( ';', $resp );
    }

    /**
     * Parse the ouput of children processes and calculate global stats
     */
    protected function parseOutputs( $outputs )
    {
        $resp = array(
            'tries' => 0,
            'failures' => 0, // nr of reqs
            'non_2xx' => 0,
            'write_errors' => 0,
            'tot_time' => 0.0, // secs (float) - time spent executing calls
            'tot_bytes' => 0.0, // bytes (float) - total resp. sizes
            'html_bytes' => 0.0, // bytes (float) - total resp. sizes
            't_min' => -1,
            't_max' => 0,
            't_avg' => 0,
            'begin' => -1, // secs (float)
            'end' => 0.0, // secs (float)
            'sizes' => array(), // index: size in bytes, value: nr. of responses received with that size
            'times' => array(), // index: time in msec (int), value: nr. of responses received with that time
            'rps' => 0.0,
            't_stddev' => 0.0,
            't_median' => 0, // msec
            't_percentiles' => array()
        );

        $succesful = 0;
        $combinedtime = 0;
        foreach( $outputs as $i => $output )
        {
            /// @todo !important move the parsing to a separate function
            $parsing = explode( ';',  $output );
            foreach ( $parsing as $item )
            {
                $parsed = explode( ':', $item, 2 );
                $data[$parsed[0]]=$parsed[1];
            }

            $resp['tries'] += $data['tries'];
            $resp['failures'] += $data['failures'];
            $resp['non_2xx'] += $data['non_2xx'];
            $resp['write_errors'] += $data['write_errors'];
            if ( $resp['begin'] == -1 || $resp['begin'] > $data['begin'] )
            {
                $resp['begin'] = $data['begin'];
            }
            if ( $resp['end'] < $data['end'] )
            {
                $resp['end'] = $data['end'];
            }
            if ( $resp['t_min'] == -1 || $resp['t_min'] > $data['t_min'] )
            {
                $resp['t_min'] = $data['t_min'];
            }
            if ( $resp['t_max'] < $data['t_max'] )
            {
                $resp['t_max'] = $data['t_max'];
            }
            $resp['tot_bytes'] += $data['tot_bytes'];
            $resp['html_bytes'] += $data['html_bytes'];
            foreach( explode( '/', $data['sizes'] ) as $size )
            {
                list( $size, $count ) = explode( '-', $size, 2 );
                $resp['sizes'][$size] = @$resp['sizes'][$size] + $count;
            }
            foreach( explode( '/', $data['times'] ) as $time )
            {
                list( $time, $count ) = explode( '-', $time, 2 );
                $resp['times'][$time] = @$resp['times'][$time] + $count;
            }

            $succesful += ( $data['tries'] - $data['failures'] );
            $combinedtime += $data['tot_time'];
        }

        if ( $succesful )
        {
            $resp['t_avg'] = $combinedtime / $succesful;
        }

        // median+percentile+standard deviation calculations
        ksort( $resp['times'] );
        $tot = 0;
        $stddev = 0;
        $percents = array( 1, 0.99, 0.98, 0.95, 0.9, 0.8, 0.75, 0.66, 0.5 );
        $percent = array_pop( $percents );
        foreach( $resp['times'] as $time => $count )
        {
            $tot += $count;
            $stddev += ( pow( $time - $resp['t_avg'], 2 ) * $count );
            while ( $tot >= ( $succesful * $percent ) && count( $percents ) )
            {
                $perc = $percent * 100;
                $resp['t_percentiles'][$perc] = $time;
                $percent = array_pop( $percents );
            }
        }
        $resp['t_median'] =  $resp['t_percentiles'][50];
        $resp['t_stdddev'] = sqrt( $stddev / $tot );

        // double-check for coherence the data
        if ( $succesful != $tot )
        {
            die( "wtf? $succesful != $tot" );
        }

        $resp['tot_time'] = $resp['end'] - $resp['begin'];
        if ( $resp['tot_time'] )
        {
            $resp['rps'] = $resp['tries'] / $resp['tot_time'];
        }


        return $resp;
    }

    protected function getPHPExecutable( $php='php' )
    {
        $validExecutable = false;
        do
        {
            $output = array();
            exec( escapeshellcmd( $php ) . ' -v', $output );
            if ( count( $output ) && strpos( $output[0], 'PHP' ) !== false )
            {
                return $php;
            }
            if ( php_sapi_name() == 'cli' )
            {
                $input = readline( 'Enter path to PHP-CLI executable ( or [q] to quit )' );
                if ( $input === 'q' )
                {
                    $this->abort( 0 );
                }
            }
            else
            {
                $this->abort( 1, "Can not run php subprocesses (executable: $php)" );
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
            'A', 'B', 'h', 'help', 'child', 'c', 'i', 'j', 'k', 'n',  'P', 'parent', 'php', 't', 'V', 'v', 'X', '1', '0', 'C'
        );
        $singleoptions = array( 'h', 'help', 'i', 'j', 'k', 'V', '1', '0' );

        $longoptions = array();
        foreach( $options as $o )
        {
            if ( strlen( $o ) > 1 )
            {
                $longoptions[] = $o;
            }
        }

        $argc = count( $argv );
        if ( $argc < 2 )
        {
            echo "ab: wrong number of arguments\n";
            echo $this->helpMsg( @$argv[0] );
            $this->abort( 1 );
        }

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

                    case 'A':
                        $opts['auth'] = $val;
                        break;
                    case 'B':
                        $opts['interface'] = $val;
                        break;
                    case 'c':
                        $opts['children'] = (int)$val > 0 ? (int)$val : 1;
                        break;
                    case 'child':
                        $opts['childnr'] = (int)$val;
                        $opts['command'] = 'runchild';
                        break;
                   case 'i':
                        $opts['head'] = true;
                        break;
                   case 'j':
                        $opts['respencoding'] = true;
                        break;
                    case 'k':
                        $opts['keepalive'] = true;
                        break;
                    case 'n':
                        $opts['tries'] = (int)$val > 0 ? (int)$val : 1;
                        break;
                    case 'P':
                        $opts['proxyauth'] = $val;
                        break;
                    case 'parent':
                        $opts['parentid'] = $val;
                        break;
                    case 'php':
                        $opts['php'] = $val;
                        break;
                    case 't':
                        $opts['timeout'] = (int)$val;
                        break;
                    case 'v':
                        $opts['verbosity'] = (int)$val;
                        break;
                    case 'X':
                        $opts['proxy'] = $val;
                        break;
                    case '0':
                        $opts['httpversion'] = CURL_HTTP_VERSION_1_0;
                        break;
                    case '1':
                        $opts['httpversion'] = CURL_HTTP_VERSION_1_1;
                        break;
                    case 'C':
                        $opts['cookies'][] = $val;
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
                $opts['target'] = $argv[$i];
            }
        }
        $this->opts = $opts;
    }

    /**
     * Parses args in array format (stores them, unless -h or -V are found)
     * If any unknown option is found, continues.
     * Nb: pre-existing options are not reset by this call.
     */
    public function parseOpts( $opts )
    {
        if ( @$opts['h'] || @$opts['help'] )
        {
            $this->opts['command'] = 'helpmsg';
            return;
        }
        if ( @$opts['V'] )
        {
            $this->opts['command'] = 'versionmsg';
            return;
        }
        foreach( $opts as $key => $val )
        {
            switch( $key )
            {
                case 'A':
                    $opts['auth'] = $val;
                    unset( $opts[$key] );
                    break;
                case 'B':
                    $opts['interface'] = $val;
                    break;
                case 'c':
                    $opts['children'] = (int)$val > 0 ? (int)$val : 1;
                    unset( $opts[$key] );
                    break;
                case 'child':
                    $opts['childnr'] = (int)$val;
                    $opts['command'] = 'runchild';
                    unset( $opts[$key] );
                    break;
                case 'i':
                    $opts['head'] = true;
                    unset( $opts[$key] );
                    break;
                case 'j':
                    $opts['respencoding'] = true;
                    unset( $opts[$key] );
                    break;
                case 'k':
                    $opts['keepalive'] = true;
                    unset( $opts[$key] );
                    break;
                case 'n':
                    $opts['tries'] = (int)$val > 0 ? (int)$val : 1;
                    unset( $opts[$key] );
                    break;
                case 'P':
                    $opts['proxyauth'] = $val;
                    unset( $opts[$key] );
                    break;
                case 'parent':
                    $opts['parentid'] = $val;
                    unset( $opts[$key] );
                    break;
                case 't':
                    $opts['timeout'] = (int)$val;
                    unset( $opts[$key] );
                    break;
                case 'v':
                    $opts['verbosity'] = (int)$val;
                    unset( $opts[$key] );
                    break;
                case 'X':
                    $opts['proxy'] = $val;
                    unset( $opts[$key] );
                    break;
                case '0':
                    $opts['httpversion'] = CURL_HTTP_VERSION_1_0;
                    unset( $opts[$key] );
                    break;
                case '1':
                    $opts['httpversion'] = CURL_HTTP_VERSION_1_1;
                    unset( $opts[$key] );
                    break;
                case 'C':
                    $opts['cookies'] = $val;
                    unset( $opts[$key] );
                    break;
            }
        }
        // $this->opts is initialized by the constructor
        $this->opts = array_merge( $this->opts, $opts );
    }

    protected function echoMsg( $msg, $lvl=1 )
    {
        if ( $lvl <= $this->opts['verbosity'] )
        {
            echo ( $this->opts['outputformat'] == 'html' ) ? htmlspecialchars( $msg ) : $msg;
        }
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
            $out .= "Usage: " . htmlspecialchars( $cmd ) . " ? [option = value &amp;]* target=[http[s]://]hostname[:port]/path\n";
            $d = '';
        }
        else
        {
            $out .= "Usage: $cmd [options] [http[s]://]hostname[:port]/path\n";
            $d = '-';
        }
        $out .= "Options are:\n";
        $out .= "    {$d}n requests     Number of requests to perform\n";
        $out .= "    {$d}c concurrency  Number of multiple requests to make\n";
        $out .= "    {$d}t timelimit    Seconds to max. wait for responses\n";
        $out .= "    {$d}B address      Address to bind to when making outgoing connections\n";
        $out .= "    {$d}v verbosity    How much troubleshooting info to print\n";
        $out .= "    {$d}i              Use HEAD instead of GET\n";
        $out .= "    {$d}A attribute    Add Basic WWW Authentication, the attributes\n";
        $out .= "                    are a colon separated username and password.\n";
        $out .= "    {$d}P attribute    Add Basic Proxy Authentication, the attributes\n";
        $out .= "                    are a colon separated username and password.\n";
        $out .= "    {$d}X proxy:port   Proxyserver and port number to use\n";
        $out .= "    {$d}V              Print version number and exit\n";
        $out .= "    {$d}k              Use HTTP KeepAlive feature\n";
        $out .= "    {$d}1              Use HTTP 1.1 (default)\n";
        $out .= "    {$d}0              Use HTTP 1.0 (nb: keepalive not supported in this case)\n";

        // extra functionality
        if ( defined( 'CURLOPT_ENCODING' ) ) $out .= "    {$d}j              Use HTTP Response Compression feature\n";

        $out .= "    {$d}h              Display usage information (this message)\n";
        if ( $this->opts['outputformat'] == 'html' )
        {
            $out .= "    {$d}php            path to php executable\n";
            $out .= '</pre>';
        }
        return $out;
    }

    function versionMsg()
    {
        $out = '';
        if ( $this->opts['outputformat'] == 'html' )
            $out .= '<pre>';
        $out .=  "This is eZAB, Version " . self::$version . "\n";
        $out .= "Copyright 2010-2012 G. Giunta, eZ Systems, http://ez.no\n";
        if ( $this->opts['outputformat'] == 'html' )
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