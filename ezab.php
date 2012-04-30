#!/usr/bin/env php
<?php
/**
 * ezab.php: same as Apache Bench tool, but in php
 *
 * @author G. Giunta
 * @license GNU GPL 2.0
 * @copyright (C) G. Giunta 2010-2012
 *
 * @todo allow setting more curl options: keepalive (k), http 1.0 vs 1.1
 * @todo verify if we do proper curl error checking
 * @todo parse more stats from children (same format as ab does)
 * @todo check if our calculation methods are the same as used by ab
 * @todo add some nice graph output, as eg. abgraph does
 */


if( !function_exists( 'curl_init' ) )
{
    echo( 'Missing cURL, cannot run' );
    exit( 1 );
}

$ab = new eZAB();
// parse cli options (die with help msg if needed)
$ab->parseArgs( $argc, $argv, eZAB::$defaults );
// will run in either parent or child mode, depending on cli options
$ab->run();


class eZAB
{
    static $version = '0.3';
    static $defaults = array(
        // 'real' options
        'verbosity' => 1, // -v verbosity    How much troubleshooting info to print
        'clients' => 1, // -c concurrency  Number of multiple requests to make
        'tries' => 1, // -n requests     Number of requests to perform
        'timeout' => 0, // -t timelimit    Seconds to max. wait for responses
        'auth' => false,
        'proxy' => false,
        'proxyauth' => false,
        'target' => '',
        'keepalive' => false,
        // 'internal' options
        // client mode
        'clientnr' => false,
        // the actual script path (as seen by the invoking shell)
        'self' => null,
        'php' => 'php',
    );
    // config options for this instance
    protected $opts = array();

    /**
     * Actual execution of the test. depending on options, calls runparent or runchild
     */
    public function run()
    {
        if ( $this->opts['clientnr'] === false )
        {
            return $this->runparent();
        }
        else
        {
            return $this->runchild();
        }
    }

    public function runparent()
    {
        $opts = $this->opts;

        $this::versionmsg();

        /// @todo shall we do exactly $opts['tries'] tests, making last thread execute more?
        $client_tries = (int) ( $opts['tries'] / $opts['clients'] );
        $php = self::getPHPExecutable( $opts['php'] );

        // != from ab output
        $this->echomsg( "\nRunning {$opts['tries']} requests with {$opts['clients']} paralel clients\n", 2 );
        $this->echomsg( "----------------------------------------\n", 2 );

        $this->echomsg( "Benchmarking {$opts['target']} (please be patient)...\n" );

        /// @todo !important move cli reconstruction to a separate function
        $args = "-n $client_tries -t " . escapeshellarg( $opts['timeout'] );
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
        $args .= " " . escapeshellarg( $opts['target'] );

        //$starttimes = array();
        $pipes = array();
        $childprocs = array();
        $childresults = array();

        //$time = microtime( true );

        // start clients
        for ( $i = 0; $i < $opts['clients']; $i++ )
        {
            $exec = escapeshellcmd( $php ) . " " . escapeshellarg( $opts['self'] ) . " --client $i " . $args;

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
                /// @todo kill all open children, exit!
            }

            $this->echomsg( "Launched client $i [ $exec ]\n", 2 );
            flush();
        }

        // wait for all clients to finish
        /// @todo add a global timeout limit?
        $finished = 0;
        $outputs = array();
        do
        {
            /// @todo !important lower this - use usleep
            sleep( 1 );

            for ( $i = 0; $i < $opts['clients']; $i++ )
            {
                if ( $childprocs[$i] !== false )
                {
                    /// @todo see note from Lachlan Mulcahy on http://it.php.net/manual/en/function.proc-get-status.php:
                    ///       to make sure buffers are not blocking children, we should read rom their pipes every now and then
                    ///       (but not on windows, since pipes are blocking and can not be timeoudt, see https://bugs.php.net/bug.php?id=54717)
                    $status = proc_get_status( $childprocs[$i] );
                    if ( $status['running'] == false )
                    {
                        $childresults[$i] = array(
                            'output' => stream_get_contents( $pipes[$i][1] ),
                            'error' => stream_get_contents( $pipes[$i][2] ),
                            'return' => proc_close( $childprocs[$i] ),
                            'status' => $status
                        );
                        $childprocs[$i] = false;
                        $finished++;
                    }
                }
            }

            $this->echomsg( "." );
            flush();

        } while( $finished < $opts['clients'] );

        //$time = microtime( true ) - $time;

        $this->echomsg( "done\n" );

        // print results

        // != from ab output
        $this->echomsg( "\nResults:\n----------------------------------------\n", 2 );
        for ( $i = 0; $i < $opts['clients']; $i++ )
        {
            /// @todo beautify
            $this->echomsg( $childresults[$i]['output'] . "\n", 2 );
        }
        $this->echomsg( "\nTotals:\n----------------------------------------\n", 2 );

        $this->echomsg( "\n\n" );

        $outputs = array();
        foreach( $childresults as $res )
        {
            $outputs[] = $res['output'];
        }
        $data = $this->parseoutputs( $outputs );

        var_dump( $data );

        $sizes = array_keys( $data['sizes'] );
        $url = parse_url( $opts['target'] );
        if ( @$url['port'] == '' )
        {
            $url['port'] = ( $url['scheme'] == 'https' ? '443' : '80' );
        }
        $this->echomsg(
            "Server Software:        [NA]\n" .
            "Server Hostname:        {$url['host']}\n" .
            "Server Port:            {$url['port']}\n" .
            "\n" .
            "Document Path:          {$url['path']}\n" .
            "Document Length:        " . reset( $sizes ) . "\n" .
            "\n" .
            "Concurrency Level:      {$opts['clients']}\n" .
            "Time taken for tests:   " . sprintf( '%.3f', $data['tot_time'] ) . " seconds\n" .
            "Complete requests:      {$data['tries']}\n" . // same as AB: includes failures
            "Failed requests:        {$data['failures']}\n" .
            "Write errors:           [NA]\n" .
            "Non-2xx responses:      [NA]\n" .
            "Total transferred:      " . "[NA]" /*{$data['tot_bytes']}*/ . " bytes\n" .
            "HTML transferred:       {$data['html_bytes']} bytes\n" . // NB: includes failures
            "Requests per second:    " . sprintf( '%.2f', $data['rps'] ) . " [#/sec] (mean)\n" . // NB: includes failures
            "Time per request:       " . sprintf( '%.3f', $data['t_avg'] * 1000 ) . " [ms] (mean)\n" . // NB: excludes failures
            "Time per request:       [NA] [ms] (mean, across all concurrent requests)\n" .
            "Transfer rate:          " . "[NA]" /*sprintf( '%.2f', $data['tot_bytes'] / ( 1024 * $data['tot_time'] ) )*/ . " [Kbytes/sec] received\n"
        );

    }

    public function runchild()
    {
        $opts = $this->opts;
        $resp = array(
            'tries' => $opts['tries'],
            'failures' => 0, // nr of reqs
            'tot_time' => 0.0, // secs (float) - time spent executing calls
            'tot_bytes' => 0.0, // bytes (float) - total resp. sizes
            'html_bytes' => 0.0, // bytes (float) - total resp. sizes
            't_min' => -1,
            't_max' => 0,
            't_avg' => 0,
            'begin' => 0.0, // secs (float)
            'end' => 0.0, // secs (float)
            'sizes' => array() // index: size in bytes, value: nr. of responses received with that size
        );

        //$ttime = microtime( true );
        $curl = curl_init( $opts['target'] );
        if ( $curl )
        {
            curl_setopt( $curl, CURLOPT_RETURNTRANSFER, 1 );
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
                    $size = strlen( $result );
                    /// @todo if resp. size changes, by default it should be flagged as error (unless option specified)
                    $resp['sizes'][$size] = isset( $resp['sizes'][$size] ) ? ( $resp['sizes'][$size] + 1 ) : 1;
                    $resp['html_bytes'] += (float)$size;
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
        $resp['sizes'] = implode( '/', $resp['sizes']);

        foreach( $resp as $key => $val )
        {
            $resp[$key] = $key . ':' . $val;
        }
        echo implode( ';', $resp );

        /*echo
            "  url          : {$opts['target']}\n" .
            "  reqs sent    : {$opts['tries']}\n" .
            "  reqs failed  : $failed\n" .
            "  time spent   : $total\n" .
            //self::fwrite( $log, " $client_num time spent ? : $ttime\n", 'a' ); // there are some differences between the 2 of 10 msecs
            "  req. size(s) : " . implode( ',', $sizes ) . "\n" .
            "  avg req. time: $avg\n" .
            "  max req. time: $max\n" .
            "  min req. time: $min\n" .
            "  reqs. per sec: $rps\n" .
            "  total bytes  : $bytes\n" .
            "  begin        : $begin\n" .
            "  end          : $end\n" .
            "DONE - " . date( 'Y/m/d H:i:s' ) . "\n", 'a';*/
    }

    /**
     * Pares the ouput of children processes to calculate global stats
     */
    protected function parseoutputs( $outputs )
    {
        $resp = array(
            'tries' => 0,
            'failures' => 0, // nr of reqs
            'tot_time' => 0.0, // secs (float) - time spent executing calls
            'tot_bytes' => 0.0, // bytes (float) - total resp. sizes
            'html_bytes' => 0.0, // bytes (float) - total resp. sizes
            't_min' => -1,
            't_max' => 0,
            't_avg' => 0,
            'begin' => -1, // secs (float)
            'end' => 0.0, // secs (float)
            'sizes' => array(), // index: size in bytes, value: nr. of responses received with that size

            'rps' => 0.0
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

            $succesful += ( $data['tries'] - $data['failures'] );
            $combinedtime += $data['tot_time'];
        }

        $resp['tot_time'] = $resp['end'] - $resp['begin'];
        if ( $resp['tot_time'] )
        {
            $resp['rps'] = $resp['tries'] / $resp['tot_time'];
        }
        $resp['t_avg'] = $combinedtime / $succesful;

        return $resp;
    }

    protected function echomsg( $msg, $lvl=1 )
    {
        if ( $lvl <= $this->opts['verbosity'] )
        {
            echo $msg;
        }
    }

    protected static function getPHPExecutable( $php='php' )
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
            $input = readline( 'Enter path to PHP-CLI executable ( or [q] to quit )' );
            if ( $input === 'q' )
            {
                exit();
            }
        } while( true );
    }

    /**
     * Parses args (stores them and returns them as well).
     * If any unknown option is found, prints help msg and exit
     * @return array the parsed options
     * @todo add support for no-arg options options
     */
    public function parseArgs( $argc, $argv, $opts = array() )
    {
        $singleoptions = array( 'k', 'h', 'help', 'V' );
        $options = array(
            'h', 'help', 'V', 'client', 'binaryoutput', 'php', 'c', 'n', 't', 'v', 'A', 'P', 'X'
        );

        $longoptions = array();
        foreach( $options as $o )
        {
            if ( strlen( $o ) > 1 )
            {
                $longoptions[] = $o;
            }
        }

        if ( $argc < 2 )
        {
            echo "ab: wrong number of arguments\n";
            self::helpmsg( @$argv[0] );
            exit( 1 );
        }
        $opts['self'] = $argv[0];

        for ( $i = 1; $i < $argc; $i++ )
        {
            if ( $argv[$i][0] == '-' )
            {
                $opt = ltrim( $argv[$i], '-' );
                $val = null;

                /// @todo we should alos allow long options with no space between opt and val
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
                    self::helpmsg( $argv[0] );
                    exit( 1 );
                }

                if ( $val === null && !in_array( $opt, $singleoptions ) )
                {
                    $val = @$argv[$i+1];
                    if ( $val[0] == '-' )
                    {
                        // two options in a row: error
                        self::helpmsg( $argv[0] );
                        exit( 1 );
                    }
                    $i++;
                }

                switch( $opt )
                {
                    case 'h':
                    case 'help':
                        self::helpmsg( $argv[0] );
                        exit();
                    case 'V':
                        self::versionmsg();
                        exit();
                    case 'client':
                        $opts['clientnr'] = $val;
                        break;
                    case 'php':
                        $opts['php'] = $val;
                        break;
                    case 'c':
                        $opts['clients'] = (int)$val > 0 ? (int)$val : 1;
                        break;
                    case 'n':
                        $opts['tries'] = (int)$val > 0 ? (int)$val : 1;
                        break;
                    case 't':
                        $opts['timeout'] = (int)$val;
                        break;
                    case 'v':
                        $opts['verbosity'] = (int)$val;
                        break;
                    case 'k':
                        $opts['keepalive'] = true;
                        break;
                    case 'A':
                        $opts['auth'] = $val;
                        break;
                    case 'P':
                        $opts['proxyauth'] = $val;
                        break;
                    case 'X':
                        $opts['proxy'] = $val;
                        break;
                    default:
                        self::helpmsg( $argv[0] );
                        exit( 1 );
                }
            }
            else
            {
                // end of options: argument
                $opts['target'] = $argv[$i];
            }
        }
        // mandatory option
        if ( @$opts['target'] == '' )
        {
            self::helpmsg( $argv[0] );
            exit( 1 );
        }
        $this->opts = $opts;
        return $opts;
    }

    static function helpmsg( $cmd )
    {
        echo "Usage: $cmd [options] [http[s]://]hostname[:port]/path\n";
        echo "Options are:\n";
        echo "    -n requests     Number of requests to perform\n";
        echo "    -c concurrency  Number of multiple requests to make\n";
        echo "    -t timelimit    Seconds to max. wait for responses\n";
        echo "    -v verbosity    How much troubleshooting info to print\n";
        echo "    -A attribute    Add Basic WWW Authentication, the attributes\n";
        echo "                    are a colon separated username and password.\n";
        echo "    -P attribute    Add Basic Proxy Authentication, the attributes\n";
        echo "                    are a colon separated username and password.\n";
        echo "    -X proxy:port   Proxyserver and port number to use\n";
        echo "    -V              Print version number and exit\n";
        echo "    -h              Display usage information (this message)\n";
    }

    static function versionmsg()
    {
        echo "This is eZAB, Version " . self::$version . "\n";
        echo "Copyright 2010-2012 G. Giunta, eZ Systems, http://ez.no\n";
    }

}
?>