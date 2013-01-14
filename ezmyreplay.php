<?php
/**
 * ezmyreplay.php: replay mysql queries taken from the slow query log.
 * Inspired by Percona's percona-playback tool
 *
 * @author G. Giunta
 * @license GNU GPL 2.0
 * @copyright (C) G. Giunta 2012
 *
 * It uses a multi-process scheme (tested to be working both on windows and linux):
 * you will need php-cli installed for this to work.
 * It can be run both as command-line script and as web page.
 * It defaults to executing immediately, but you can actually include() it from
 * your app and use it as a plain php class, by defining the EZMYREPLAY_AS_LIB constant
 * before including this file.
 *
 * @todo add parsing of options from query string for web access
 * @todo support not entering password on cli + hide it from process list
 * @todo allow to replay only 1 user session if there are many in the mysql slow log
 * @todo add support for mysql, pdo libraries
 * @todo at high debug levels print mysql error messages
 */

if ( !defined( 'EZMYREPLAY_AS_LIB' ) )
{
    if( !function_exists( 'mysqli_connect' ) && !function_exists( 'mysql_connect' ) && !defined( 'PDO::MYSQL_ATTR_USE_BUFFERED_QUERY' ) )
    {
        echo( 'Missing mysql client libraries, cannot run' );
        exit( 1 );
    }

    $rp = new eZMyReplay();
    if ( php_sapi_name() == 'cli' )
    {
        // parse cli options (die with help msg if needed)
        $rp->parseArgs( $argv );
    }
    else
    {
        // parse options in array format (die with help msg if needed)
        $rp->parseOpts( $_GET );
    }
    // will run in either parent or child mode, depending on parsed options
    $rp->run();
}

class eZMyReplay
{
    static $version = '0.1-dev';
    static $defaults = array(
        // 'real' options
        'verbosity' => 1, // -v verbosity    How much troubleshooting info to print
        'children' => 1, // -c concurrency  Number of multiple requests to make
        'tries' => 1, // -n requests     Number of times to perform replay

        'user' => '', // u
        'password' => '', // p
        'host' => 'localhost', // h
        'port' => 3306, // P
        'database' => '', // D
        'logfile' => '',
        'client' => 'mysqli',
        'format' => 'slowquerylog',
        'skippercentiles' => false,

        /*'timeout' => 0, // -t timelimit    Seconds to max. wait for responses
        'auth' => false,
        'proxy' => false,
        'proxyauth' => false,
        'target' => '',
        'keepalive' => false,
        'head' => false,
        'interface' => '',
        'respencoding' => false,*/

        // 'internal' options
        'childnr' => false,
        'parentid' => false,
        // the actual script path (self)
        'self' => __FILE__,
        'php' => 'php',
        'outputformat' => 'text',
        'haltonerrors' => true,
        'command' => 'runparent' // allowed: 'helpmsg', 'versionmsg', 'runparent', 'runchild', 'runparse'
    );
    // config options for this instance
    protected $opts = array();

    function __construct( $opts = array() )
    {
        $this->opts = self::$defaults;
        $this->opts['outputformat'] = ( php_sapi_name() == 'cli' ) ? 'text' : 'html';
        $this->opts['haltonerrors'] = !defined( 'EZMYREPLAY_AS_LIB' );
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
                return $this->runParent( false );
            case 'runparse':
                echo json_encode( $this->runParent( true ) );
                break;
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
    public function runParent( $only_return_parsed=false )
    {
        // mandatory option
        if ( $this->opts['logfile'] == '' )
        {
            echo $this->helpMsg();
            $this->abort( 1 );
        }

        /// @todo: ask for password (and try to avoid plaintext passwd passed to child procs because it still shows up in ps calls)
        if ( $this->opts['password'] == '' )
        {

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
        //$child_tries = (int) ( $opts['tries'] / $opts['children'] );
        $php = $this->getPHPExecutable( $opts['php'] );

        if ( !is_file( $opts['logfile'] ) || !is_readable( $opts['logfile'] ) )
        {
            $this->abort( 1, "Mysql log file '{$opts['logfile']}' can not be read" );
        }
        $parsed = $this->parseLogFile( $opts['logfile'], $opts['format'] );
        $statements_count = count( $parsed );
        if ( !$statements_count )
        {
            $this->abort( 1, "Mysql log file '{$opts['logfile']}' does not contain any SQL statement" );
        }

        if ( $only_return_parsed )
        {
            return $parsed;
        }

        /// @todo test that we can connect to selected db before launching children?

        // save data from parsed logfile to temporary file, unless it's already done
        if ( $opts['format'] == 'json' )
        {
            $tmplogfile = $opts['logfile'];
        }
        else
        {
            /// @todo use unique filename (see tempnamm() )
            $tmplogfile = 'xxx.tmp';
            if ( !file_put_contents( $tmplogfile, json_encode( $parsed ) ) )
            {
                $this->abort( "Could not create temporary file $tmplogfile" );
            }
        }


        $this->echoMsg( "Replaying $statements_count queries (please be patient)...\n" );

        // != from percona-playback output
        $this->echoMsg( "\nRunning " . $opts['tries'] * $statements_count . " queries with {$opts['children']} parallel processes\n", 2 );
        $this->echoMsg( "----------------------------------------\n", 2 );

        /// @todo !important move cli opts reconstruction to a separate function
        $args = "--parent " . $opts['parentid'];
        $args .= " -n " . $opts['tries'] .
            " -u " . escapeshellarg( $opts['user'] ) .
            " -p " . escapeshellarg( $opts['password'] ) .
            " -h " . escapeshellarg( $opts['host'] ) .
            " -P " . escapeshellarg( $opts['port'] ) .
            " -D " . escapeshellarg( $opts['database'] ) .
            " --client " . escapeshellarg( $opts['client'] ) .
            " -v " . $opts['verbosity'] .
            " --format json";
        /// @todo pass as well "type of mysql lib" param
        $args .= " " . escapeshellarg( $tmplogfile );

        //$starttimes = array();
        $pipes = array();
        $childprocs = array();
        $childresults = array();

        //$time = microtime( true );

        // start children
        for ( $i = 0; $i < $opts['children']; $i++ )
        {
            //var_dump( escapeshellcmd( $php ) . " " . escapeshellarg( $opts['self'] ) . " --child $i " . $args );
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

            $this->echoMsg( "Launched child $i [ $exec ]\n", 2 );
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
                    ///       (but not on windows, since pipes are blocking and can not be timeoudt, see https://bugs.php.net/bug.php?id=54717)
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

        $this->echoMsg( "done\n" );

        if ( $opts['format'] == 'json' )
        {
            unlink( $tmplogfile );
        }

        // print results

        // != from percona-playback output
        $this->echoMsg( "\nChildren output:\n----------------------------------------\n", 2 );
        for ( $i = 0; $i < $opts['children']; $i++ )
        {
            /// @todo beautify
            $this->echoMsg( $childrensults[$i]['output'] . "\n", 2 );
        }

        $this->echoMsg( "\nChildren details:\n----------------------------------------\n", 3 );
        $this->echoMsg( var_export( $childrensults, true ), 3 );

        $outputs = array();
        foreach( $childrensults as $i => $res )
        {
            if ( $res['return'] != 0 || $res['output'] == '' )
            {
                $this->abort( 1, "Child process $i did not terminate correctly. Exiting" );
            }
            $outputs[$i] = $res['output'];
        }
        $data = $this->parseOutputs( $outputs );

        $this->echoMsg( "\n\n" );

        $this->echoMsg( "\nSummary:\n----------------------------------------\n", 2 );

        $hasmeta = $data['rows_expected'] !== false;

        $pcs = '';
        if ( !$opts['skippercentiles'] )
        {
            $pcs = "\nPercentage of the queries executed within a certain time (ms)\n" .
            "  50% " . sprintf( '%6u', $data['t_percentiles'][50] ) . "\n" .
            "  66% " . sprintf( '%6u', $data['t_percentiles'][66] ) . "\n" .
            "  75% " . sprintf( '%6u', $data['t_percentiles'][75] ) . "\n" .
            "  80% " . sprintf( '%6u', $data['t_percentiles'][80] ) . "\n" .
            "  90% " . sprintf( '%6u', $data['t_percentiles'][90] ) . "\n" .
            "  95% " . sprintf( '%6u', $data['t_percentiles'][95] ) . "\n" .
            "  98% " . sprintf( '%6u', $data['t_percentiles'][98] ) . "\n" .
            "  99% " . sprintf( '%6u', $data['t_percentiles'][99] ) . "\n" .
            " 100% " . sprintf( '%6u', $data['t_max'] * 1000 ) . " (longest request)";
        }

        $details = '';
        foreach( array( 'SELECT', 'INSERT', 'UPDATE', 'DELETE', 'REPLACE', 'DROP', 'OTHER' ) as $type )
        {
            $details .= str_pad( $type . 's', 9 ) . ": {$data['queries'][$type]} queries" . ( $hasmeta ? "(" . $data['meta'][$type]['faster'] . " faster, " . $data['meta'][$type]['slower'] . " slower)\n" : "\n" );
        }
        $this->echoMsg(
            "Detailed Report\n" .
            "----------------\n" .
            $details .
            "\nReport\n" .
            "------\n" .
            "Executed {$data['tries']} queries\n" .
            "Spent " . gmstrftime( '%H:%M:%S', (int)$data['tot_time'] ) . substr( strstr( $data['tot_time'], '.' ), 0, 7 ) . " executing queries\n" //. ( $hasmeta ? " versus an expected XX time.\n" : "\n" ) .
            ( $hasmeta ? "{$data['meta']['faster']} queries were quicker than expected, {$data['meta']['slower']} were slower\n" : "" ) .
            "A total of {$data['failures']} queries had errors.\n" .
            ( $hasmeta ? "Expected {$data['rows_expected']} rows, got {$data['tot_rows']} (a difference of " . ( $data['rows_expected'] - $data['tot_rows'] ) . ")\n" : "" ) .
            ( $hasmeta ? "Number of queries where number of rows differed: {$data['rows_differ_queries']}.\n" : "" ) .

            "\nAverage of " . sprintf( '%.2f', $data['tries'] / $opts['children'] )." queries per connection ({$opts['children']} connections).\n" .

            "\nQuery Times (ms)\n" .
            "              min  mean[+/-sd] median   max\n" .
            /// @todo better formatting if numbers go over 5 digits (roughly 2 minutes)
            "Total:      " . sprintf( '%5u', $data['t_min'] * 1000 ) . " " . sprintf( '%5u', $data['t_avg'] * 1000 ) . "  " . sprintf( '%5.1f', $data['t_stdddev'] ). "  ". sprintf( '%5u', $data['t_median'] ) . " " . sprintf( '%5u', $data['t_max'] * 1000 ) . "\n" .
            $pcs
        );

        return array(
            'summary_data' => $data,
            'children_details' => $childrensults
        );
    }

    /**
    * Executes the sql queries, returns a csv string with the collected data
    * @return string
    *
    * @todo add support for pdo, mysql
    */
    public function runChild()
    {
        $opts = $this->opts;

        /// @todo !important code copied here from runParent, could be refactored in single, protected function
        if ( !is_file( $opts['logfile'] ) || !is_readable( $opts['logfile'] ) )
        {
            $this->abort( 1, "Mysql log file '{$opts['logfile']}' can not be read" );
        }
        $parsed = $this->parseLogFile( $opts['logfile'], $opts['format'] );
        $statements_count = count( $parsed );
        if ( !$statements_count )
        {
            $this->abort( 1, "Mysql log file '{$opts['logfile']}' does not contain any SQL statement" );
        }

        $resp = array(
            'tries' => 0, // incremented on every sql call
            'failures' => 0, // nr of reqs
            'tot_time' => 0.0, // secs (float) - time spent executing calls
            't_min' => -1,
            't_max' => 0,
            't_avg' => 0,
            'begin' => -1, // secs (float)
            'end' => 0.0, // secs (float)
            'rows' => array(), // index: gotten rows, value: nr. of responses received with that many
            'tot_rows' => 0,
            'times' => array(), // index: execution time (ms), value: nr. queries taking that time to execute
            'queries' => array(
                'SELECT' => 0,
                'INSERT' => 0,
                'UPDATE' => 0,
                'DELETE' => 0,
                'REPLACE' => 0,
                'DROP' => 0,
                'OTHER' => 0 ),
            'rows_expected' => null, // NB: we later check for null != 0
            'rows_differ_queries' => 0,
            'meta' => array(
                'SELECT' => array( 'faster' => 0, 'slower' => 0 ),
                'INSERT' => array( 'faster' => 0, 'slower' => 0 ),
                'UPDATE' => array( 'faster' => 0, 'slower' => 0 ),
                'DELETE' => array( 'faster' => 0, 'slower' => 0 ),
                'REPLACE' => array( 'faster' => 0, 'slower' => 0 ),
                'DROP' => array( 'faster' => 0, 'slower' => 0 ),
                'OTHER' => array( 'faster' => 0, 'slower' => 0 ) )
        );

        for ( $i = 0; $i < $opts['tries']; $i++ )
        {
            switch( $opts['client'] )
            {
                case 'mysqli':
                    // it seems that a bad $opts['database'] fails silently...
                    $my = @new mysqli( $opts['host'], $opts['user'], $opts['password'], $opts['database'], $opts['port'] );
                    /// @todo this check was broken up to php 5.2.9 / 5.3.0
                    if ( $my->connect_error )
                    {
                        $this->abort( 2, $my->connect_errno . ' ' . $my->connect_error );
                    }

                    foreach( $parsed as $j => $stmt )
                    {
                        // check that 'sql' member exists
                        /// @todo write a warning somewhere?
                        if ( !isset( $stmt['sql'] ) )
                        {
                            continue;
                        }

                        // avoid running USE statements if db is specified on command line
                        if ( $opts['password'] != '' && preg_match( '/^ *USE +/i', $stmt['sql'] ) )
                        {
                            continue;
                        }

                        $resp['tries'] = $resp['tries'] + 1;
                        $fetched = 0;
                        $start = microtime( true );
                        $res = $my->query( $stmt['sql'] );
                        $ar = $my->affected_rows;
                        if ( is_object( $res ) )
                        {
                            // we get all data line by line, not to exhaust php memory by fetching all in 1 array
                            /// @todo test if using MYSQLI_NUM is really the faster way
                            while ( $res->fetch_array( MYSQLI_NUM ) !== null )
                            {
                                $fetched++;
                            }
                            $res->close();
                        }
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
                        if ( $res === false )
                        {
                            $resp['failures']++;
                        }
                        else
                        {
                            if ( $fetched )
                            {
                                $resp['rows'][$fetched] = isset( $resp['rows'][$fetched] ) ? ( $resp['rows'][$fetched] + 1 ) : 1;
                            }
                            $timemsec = (int) ( $time * 1000 );
                            $resp['times'][$timemsec] = isset( $resp['times'][$timemsec] ) ? ( $resp['times'][$timemsec] + 1 ) : 1;
                            $resp['tot_rows'] += $fetched;
                        }

                        if ( $resp['begin'] == -1 )
                        {
                            $resp['begin'] = $start;
                        }

                        /// @todo verify if this classifies correctly all queries based on type
                        if ( preg_match( '/^ *(SELECT|INSERT|UPDATE|DELETE|REPLACE|DROP) +/i', $stmt['sql'], $matches ) )
                        {
                            $type = strtoupper($matches[1]);
                        }
                        else
                        {
                            $type = 'OTHER';
                        }
                        $resp['queries'][$type] = $resp['queries'][$type] + 1;

                        if ( isset( $stmt['meta'] ) )
                        {
                            // compare with "older run" data
                            if ( isset( $stmt['meta']['rows_sent'] ) )
                            {
                                $resp['rows_expected'] += $stmt['meta']['rows_sent'];
                            }
                            if ( $stmt['meta']['rows_sent'] != $fetched )
                            {
                                $resp['rows_differ_queries']++;
                            }
                            if ( isset( $stmt['meta']['query_time'] ) )
                            {
                                /// truncate a bit?
                                if ( $stmt['meta']['query_time'] > $time )
                                {
                                    $resp['meta'][$type]['faster']++;
                                }
                                else if ( $stmt['meta']['query_time'] < $time )
                                {
                                    $resp['meta'][$type]['slower']++;
                                }
                            }
                        }
                    }

                    $my->close();
                    break;

                default:
                    $this->abort( 1, "Support not implemented for client type {$opts['client']}" );
            }
        } // loop on number of passes

        /// @bug this might never even have been set...
        $resp['end'] = $stop;

        if ( $resp['t_min'] == -1 )
        {
            $resp['t_min'] = 0;
        }
        $succesful = $resp['tries'] - $resp['failures'];
        /// @bug $resp['tot_time'] includes time for failed requests as well...
        if ( $succesful > 0 )
        {
            $resp['t_avg'] = $resp['tot_time'] / $succesful;
        }

        // use an "almost readable" csv format - not using dots or commas to avoid float problems
        /// @todo !important move to a separate function
        foreach( $resp['rows'] as $size => $count )
        {
            $resp['rows'][$size] = $size . '-' . $count;
        }
        ksort( $resp['rows'] );
        $resp['rows'] = implode( '/', $resp['rows'] );

        foreach( $resp['times'] as $time => $count )
        {
            $resp['times'][$time] = $time . '-' . $count;
        }
        ksort( $resp['times'] );
        $resp['times'] = implode( '/', $resp['times'] );

        foreach( $resp['queries'] as $type => $count )
        {
            $resp['queries'][$type] = $type . '-' . $count;
        }
        $resp['queries'] = implode( '/', $resp['queries'] );

        foreach( $resp['meta'] as $type => $count )
        {
            $resp['meta'][$type] = $type . '-' . $count['faster'] . '-' . $count['slower'];
        }
        $resp['meta'] = implode( '/', $resp['meta'] );

        foreach( $resp as $key => $val )
        {
            $resp[$key] = $key . ':' . $val;
        }
        return implode( ';', $resp );
    }

    /**
    * Parses a mysql slow query log
    * @parm string $filename
    * @return array
    */
    public function parseLogFile( $filename, $format )
    {
        switch( $format )
        {
            case 'parsed':
            case 'json':
                $parsed = json_decode( file_get_contents( $filename ), true );
                if ( is_array( $parsed ) )
                {
                    return $parsed;
                }
                else
                {
                    /// @todo write some kind of warning
                    return array();
                }

            case 'slowquerylog':
            default:
                $sql = array();
                $meta = array();
                $parsed = array();
                $lines = file( $filename, FILE_IGNORE_NEW_LINES );
                //$headerdone = false;
                /// @todo make sure we always parse correctly (skip) initial lines in log file
                foreach ( $lines as $i => $line )
                {
                    if ( strlen( $line ) && $line[0] == '#' )
                    {
                        // comment line
                        $sql = array();
                        if ( preg_match( '/^# User@Host: (.*)$/', $line, $matches ) )
                        {

                        }
                        else if ( preg_match( '/^# Query_time: +([0-9.]+) +Lock_time: +([0-9.]+) +Rows_sent: +([0-9.]+) +Rows_examined: ([0-9.]+)$/', $line, $matches ) )
                        {
                            $meta['query_time'] = $matches[1];
                            $meta['lock_time'] = $matches[2];
                            $meta['rows_sent'] = $matches[3];
                            $meta['rows_examined'] = $matches[4];
                        }
                    }
                    else
                    {
                        if ( substr( $line, -1 ) == ';' )
                        {
                            // end of sql stmt

                            // ignore these statements, added by mysql
                            if ( substr( $line, 0, 14 ) == 'SET timestamp=' )
                            {
                                /// @todo shall we check that there's no more than 1 stmt on this line?
                                $sql = array();
                                continue;
                            }

                            $sql[] = $line;
                            $pline =  array ( 'sql' => join( "\n", $sql ) );
                            if ( count( $meta ) )
                            {
                                $pline['meta'] = $meta;
                            }
                            $parsed[] = $pline;

                            $sql = array();
                            $meta = array();
                        }
                        else
                        {
                            // unfinished sql
                            $sql[] = $line;
                        }
                    }
                }
                return $parsed;
        }
    }

    /**
     * Parse the ouput of children processes and calculate global stats
     */
    protected function parseOutputs( $outputs )
    {
        $resp = array(
            'tries' => 0, // incremented on every sql call
            'failures' => 0, // nr of reqs
            'tot_time' => 0.0, // secs (float) - time spent executing calls
            't_min' => -1,
            't_max' => 0,
            't_avg' => 0,
            'begin' => -1, // secs (float)
            'end' => 0.0, // secs (float)
            'rows' => array(), // index: gotten rows, value: nr. of responses received with that many rows
            'tot_rows' => 0,
            'times' => array(), // index: execution time (ms), value: nr. queries taking that time to execute
            'queries' => array( // nr. of queries by type
                'SELECT' => 0,
                'INSERT' => 0,
                'UPDATE' => 0,
                'DELETE' => 0,
                'REPLACE' => 0,
                'DROP' => 0,
                'OTHER' => 0 ),
            'rows_expected' => null, // NB: we later check for null != 0
            'rows_differ_queries' => 0,
            'meta' => array( // comparison with original times from slow query log
                'SELECT' => array( 'faster' => 0, 'slower' => 0 ),
                'INSERT' => array( 'faster' => 0, 'slower' => 0 ),
                'UPDATE' => array( 'faster' => 0, 'slower' => 0 ),
                'DELETE' => array( 'faster' => 0, 'slower' => 0 ),
                'REPLACE' => array( 'faster' => 0, 'slower' => 0 ),
                'DROP' => array( 'faster' => 0, 'slower' => 0 ),
                'OTHER' => array( 'faster' => 0, 'slower' => 0 ),
                'faster' => 0, 'slower' => 0 ),
            // calculated data
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
            $resp['tot_rows'] += $data['tot_rows'];
            if ( $data['rows_expected'] !== null )
            {
                $resp['rows_expected'] += $data['rows_expected'];
                $resp['rows_differ_queries'] += $data['rows_differ_queries'];
                foreach( explode( '/', $data['meta'] ) as $exp )
                {
                    list( $type, $faster, $slower ) = explode( '-', $exp, 3 );
                    $resp['meta'][$type]['faster'] += $faster;
                    $resp['meta']['faster'] += $faster;
                    $resp['meta'][$type]['slower'] += $slower;
                    $resp['meta']['slower'] += $slower;
                }
            }
            foreach( explode( '/', $data['rows'] ) as $size )
            {
                list( $size, $count ) = explode( '-', $size, 2 );
                $resp['rows'][$size] = @$resp['rows'][$size] + $count;
            }
            foreach( explode( '/', $data['times'] ) as $time )
            {
                list( $time, $count ) = explode( '-', $time, 2 );
                $resp['times'][$time] = @$resp['times'][$time] + $count;
            }
            foreach( explode( '/', $data['queries'] ) as $type )
            {
                list( $type, $count ) = explode( '-', $type, 2 );
                $resp['queries'][$type] = @$resp['queries'][$type] + $count;
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
            'u', 'p', 'h', 'P','D', 'format',
            'child', 'parent', 'php', 'dump', 'client', 'v', 'V', 'help', 'version', 'n'
        );
        $singleoptions = array( 'V', 'help', 'dump', 'version' );

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
            echo "ezmyreplay: wrong number of arguments\n";
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
                    case 'help':
                        $this->opts['command'] = 'helpmsg';
                        return;
                    case 'version':
                    case 'V':
                        $this->opts['command'] = 'versionmsg';
                        return;

                    case 'u':
                        $opts['user'] = $val;
                        break;
                    case 'p':
                        $opts['password'] = $val;
                        break;
                    case 'h':
                        $opts['host'] = $val;
                        break;
                    case 'P':
                        $opts['port'] = $val;
                        break;
                    case 'D':
                        $opts['database'] = $val;
                        break;

                    case 'c':
                        $opts['children'] = (int)$val > 0 ? (int)$val : 1;
                        break;
                    case 'child':
                        $opts['childnr'] = (int)$val;
                        $opts['command'] = 'runchild';
                        break;
                    case 'dump':
                        $opts['command'] = 'runparse';
                        break;
                    case 'n':
                        $opts['tries'] = (int)$val > 0 ? (int)$val : 1;
                        break;
                    case 'parent':
                        $opts['parentid'] = $val;
                        break;
                    case 'php':
                        $opts['php'] = $val;
                        break;
                    case 'v':
                        $opts['verbosity'] = (int)$val;
                        break;
                    case 'format':
                        $opts['format'] = $val;
                        break;
                    case 'client':
                        $opts['client'] = $val;
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
                $opts['logfile'] = $argv[$i];
            }
        }
        $this->opts = $opts;
    }

    /**
     * Parses args in array format (stores them, unless -h or -V are found)
     * If any unknown option is found, continues.
     * Nb: pre-existing options are not reset by this call.
     *
     * @todo !!!
     */
    public function parseOpts( $opts )
    {
        /*if ( @$opts['h'] || @$opts['help'] )
        {
            $this->opts['command'] = 'helpmsg';
            return;
        }
        if ( @$opts['V'] || @$opts['version'] )
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
            }
        }
        // $this->opts is initialized by the constructor
        $this->opts = array_merge( $this->opts, $opts );*/
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
            $out .= "eZMyReplay\nVersion: " . self::$version . "\n\n";
            $out .= "USAGE: " . htmlspecialchars( $cmd ) . " ? [option = value &amp;]* logfile == logfile\n\n";
            $d = '';
        }
        else
        {
            $out .= "eZMyReplay\nVersion: " . self::$version . "\n\n";
            $out .= "USAGE: $cmd [options] logfile\n\n";
            $d = '-';
        }
        $out .= "General options:\n";
        $out .= "    --help                     Display this message\n";
        $out .= "    {$d}V, --version           Display version information\n";
        $out .= "    {$d}v verbosity            How much troubleshooting info to print\n";
        $out .= "    {$d}c concurrency          Concurrent threads, default is 1\n";
        $out .= "    {$d}n replays              Number of times to replay log (for each thread)\n";

        $out .= "\nMySQL Client Options:\n";
        $out .= "    {$d}h host                 Hostname of MySQL server\n";
        $out .= "    {$d}u user                 Username to connect to MySQL\n";
        $out .= "    {$d}p password             Password for MySQL user\n";
        $out .= "    {$d}D database             MySQL Schema to connect to\n";
        $out .= "    {$d}P port                 MySQL port number\n";


        if ( $this->opts['outputformat'] == 'html' )
        {
            $out .= "    {$d}php                    path to php executable\n";
        }
        $out .= "\n";

        $out .= $this->copyrightMsg();

        if ( $this->opts['outputformat'] == 'html' )
        {
            $out .= '</pre>';
        }
        return $out;
    }

    function versionMsg()
    {
        $out = '';
        if ( $this->opts['outputformat'] == 'html' )
            $out .= '<pre>';
        $out .= "eZMyReplay\nVersion: " . self::$version . "\n\n";
        $out .= $this->copyrightMsg();
        if ( $this->opts['outputformat'] == 'html' )
            $out .= '</pre>';
        return $out;
    }

    protected function copyrightMsg()
    {
        $out = "Copyright (C) 2012 by G. Giunta, eZ Systems, http://ez.no\n";
        $out .= "This is free software; see the source for copying conditions.\n";
        $out .= "There is NO warranty; not even for MERCHANTABILITY or FITNESS\n";
        $out .= "FOR A PARTICULAR PURPOSE.";
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