<?php

/**
 * This autoshutdown script sets the shutdown countdown if none of the
 * following services are active (being used at the moment): Plex,
 * Transmission client, Samba shares, or SSH. Cancels the shutdown countdown
 * if any one of the above listed services becomes active.
 *
 * Check README.md for documentation.
 *
 * @author Jan Havluj <jan@havluj.eu>
 */

/****************************************************************************
 *                          <SCRIPT CONFIGURATION>                          *
 ****************************************************************************/

$config_array = parse_ini_file("config.ini", true);

// general configuration
$SCRIPT_SLEEP_TIME = $config_array["general"]["sleep_time"];
$SHUTDOWN_COUNTDOWN = $config_array["general"]["shutdown_countdown"];

// Plex configuration
$PLEX_PORT = $config_array["plex"]["plex_port"];
$PLEX_TOKEN = $config_array["plex"]["plex_token"];

// Transmission configuration
$TRANSIMISSION_PORT = $config_array["transmission"]["transmission_port"];
$TRANSIMISSION_USERNAME = $config_array["transmission"]["transmission_username"];
$TRANSIMISSION_PASSWORD = $config_array["transmission"]["transmission_password"];

// logging config
$DEBUG = $config_array["logging"]["enable_logs"];
$LOG_LIFESPAN = $config_array["logging"]["log_lifespan"];
$LOG_FOLDER_LOCATION = $config_array["logging"]["log_folder"];
$CLEAR_LOG_DIR_EVERY = $config_array["logging"]["clear_logs_duration"];

// meta 
$SCRIPT_DIR = realpath(dirname(__FILE__));
$SERVER_IP = "localhost";

/****************************************************************************
 *                          </SCRIPT CONFIGURATION>                         *
 ****************************************************************************/

function log_message($message)
{
    if ($GLOBALS["DEBUG"]) {
        $location = $GLOBALS["LOG_FOLDER_LOCATION"] . "/" . date("Ymd");
        $filename = "log_" . date("H") . ".txt";

        if (!is_dir($location)) {
            // dir doesn't exist, make it
            mkdir($location, 0750, true);
        }
        file_put_contents($location . "/" . $filename, date("Y-m-d H:i:s") . " - " . $message . "\n", FILE_APPEND);
    }
}

function clean_up_logs()
{
    $log_dirs = glob($GLOBALS["LOG_FOLDER_LOCATION"] . '/*', GLOB_ONLYDIR);
    if ($log_dirs === false) {
        $log_dirs = array();
    }

    foreach ($log_dirs as $log_dir) {
        $date = explode("/", $log_dir);

        $time_diff = (date("Ymd")) - $date[1];
        if ($time_diff > $GLOBALS["LOG_LIFESPAN"]) {
            rrmdir($log_dir);
        }
    }
}

/**
 * Recursively removes a folder along with all its files and directories
 *
 * @param String $path
 */
function rrmdir($path)
{
    // Open the source directory to read in files
    $i = new DirectoryIterator($path);
    foreach ($i as $f) {
        if ($f->isFile()) {
            unlink($f->getRealPath());
        } else if (!$f->isDot() && $f->isDir()) {
            rrmdir($f->getRealPath());
        }
    }
    rmdir($path);
}

function isPlexActive($serverAddress, $port, $token)
{
    // check streaming activity
    $playingMediaUrl = "http://" . $serverAddress . ":" . $port . "/status/sessions/?X-Plex-Token=" . $token;
    log_message("checking " . $playingMediaUrl . " ...");
    try {
        $mediaStreamingCnt = (int)((new SimpleXMLElement(@file_get_contents($playingMediaUrl)))->attributes()->size[0]);
        if ($mediaStreamingCnt < 1) {
            log_message("no media are being streamed");
        } else {
            log_message("there is/are " . $mediaStreamingCnt . " media stream(s)");

            return TRUE;
        }
    } catch (Exception $e) {
        log_message("retrieving or parsing active streams failed, interpretting as inactive!");
    }

    // check transcoding activity (if we get here, it means there are no streams active)
    $transocingMediaUrl = "http://" . $serverAddress . ":" . $port . "/transcode/sessions/?X-Plex-Token=" . $token;
    log_message("checking " . $transocingMediaUrl . " ...");
    try {
        $mediaTranscodingCnt = (int)((new SimpleXMLElement(@file_get_contents($transocingMediaUrl)))->attributes()->size[0]);
        if ($mediaTranscodingCnt < 1) {
            log_message("no media are being transcoded");
        } else {
            log_message("there is/are " . $mediaTranscodingCnt . " media transcoding");

            return TRUE;
        }
    } catch (Exception $e) {
        log_message("retrieving or parsing active transcodings failed, interpreting as inactive!");
    }

    return FALSE;
}

function isTransmissionActive($serverAddress, $port, $username, $password)
{
    try {
        $url = "http://" . $username . ":" . $password . "@" . $serverAddress . ":" . $port . "/transmission/rpc";
        $transmissionSessionId = NULL;

        // obtain X-Transmission-Session-Id
        log_message("obtaining X-Transmission-Session-Id from " . $url . " ...");
        @file_get_contents($url); // suppress warning, we expect 409 to be returned
        if ($http_response_header) {
            foreach ($http_response_header as $header) {
                if (strpos($header, "X-Transmission-Session-Id") === 0) {
                    $transmissionSessionId = trim(substr($header, 26));
                }
            }
        } else {
            log_message("obtaining X-Transmission-Session-Id failed, interpreting as inactive!");

            return FALSE;
        }

        // get json data
        log_message("checking " . $url . " ...");
        $data = "{\"arguments\": {\"fields\": [ \"name\", \"percentDone\" ]},\"method\": \"torrent-get\"}";
        $options = [
            "http" => [
                "header" => "X-Transmission-Session-Id: " . $transmissionSessionId . "\r\n"
                    . "Content-type: application/json\r\n"
                    . "Content-Length: " . strlen($data) . "\r\n",
                "method" => "POST",
                "content" => $data,
            ],
        ];
        $context = stream_context_create($options);
        $result = @file_get_contents($url, FALSE, $context);
        if (!$result) {
            log_message("retrieving or parsing active transimission downloads failed, interpreting as inactive!");

            return FALSE;
        }
        $decodedJson = json_decode($result);
        $downloadsCnt = count($decodedJson->arguments->torrents);
        if ($downloadsCnt > 0) {
            log_message("there is/are " . $downloadsCnt . " download(s) in the transmission queue");

            foreach ($decodedJson->arguments->torrents as $torrent) {
                if ($torrent->percentDone < 1) {
                    log_message("at least one of the downloads is not finished yet");

                    return TRUE;
                }
            }
        } else {
            log_message("there are no active transimission downloads");
        }
    } catch (Exception $e) {
        log_message("retrieving or parsing active transimission downloads failed, interpreting as inactive!");
    }

    return FALSE;
}

function isSambaActive()
{
    log_message("checking samba connections via `smbstatus --shares` command ...");
    $outputCode = exec("smbstatus --shares | awk 'NR > 3 { print }' | head -n -1", $output);
    if ($outputCode == 0 && count($output) > 0) {
        log_message("there is/are " . count($output) . " active samba connection(s)");

        return TRUE;
    } else {
        log_message("there are no active samba connections");
    }

    return FALSE;
}

function isAnybodyLoggedIn()
{
    log_message("checking logged in users via `who` command ...");
    $outputCode = exec("who", $output);
    if ($outputCode == 0 && count($output) > 0) {
        log_message("there is/are " . count($output) . " user(s) logged in");

        return TRUE;
    } else {
        log_message("there is noone logged in");
    }

    return FALSE;
}


// initial setup
clean_up_logs();
$processUser = posix_getpwuid(posix_geteuid());
log_message("Started autoshutdown script under user: " . $processUser['name']);
log_message("Running from: " . $SCRIPT_DIR);

$isAutoshutdownSet = FALSE;
$cleanLogsThreashold = 0;
$cleanLogsCycle = 0;
if ($CLEAR_LOG_DIR_EVERY >= $SCRIPT_SLEEP_TIME) {
    $cleanLogsThreashold = (int)($CLEAR_LOG_DIR_EVERY / $SCRIPT_SLEEP_TIME);
}

// main cycle
while (TRUE) {
    $isAnybodyLoggedIn = isAnybodyLoggedIn();
    $isSambaActive = isSambaActive();
    $isPlexActive = isPlexActive($SERVER_IP, $PLEX_PORT, $PLEX_TOKEN);
    $isTransmissionActive = isTransmissionActive($SERVER_IP, $TRANSIMISSION_PORT, $TRANSIMISSION_USERNAME, $TRANSIMISSION_PASSWORD);

    if ($isAnybodyLoggedIn || $isSambaActive || $isPlexActive || $isTransmissionActive) {
        if ($isAutoshutdownSet) {
            log_message("at least one service became active, canceling the shutdown ...");
            $outputCode = exec("shutdown -c --no-wall &>/dev/null");
            if ($outputCode == 0) {
                $isAutoshutdownSet = FALSE;
                log_message("shutdown has been successfully terminated");
            } else {
                log_message("an error occurred while terminating the shutdown");
            }
        }
    } else {
        if (!$isAutoshutdownSet) {
            log_message("no services active, setting up shutdown ...");
            $outputCode = exec($SCRIPT_DIR . "/autoshutdown.sh " . $SHUTDOWN_COUNTDOWN . " &>/dev/null &");
            log_message("script result: " . $outputCode);
            if ($outputCode == 0) {
                $isAutoshutdownSet = TRUE;
                log_message("shutdown has been successfully set up");
            } else {
                log_message("an error occurred while setting up shutdown");
            }
        }
    }

    log_message("-------------------------------------------------------------");

    // clear up logs
    if ($cleanLogsCycle == $cleanLogsThreashold) { // clean up logs and reset cycle counter
        clean_up_logs();
        $cleanLogsCycle = 0;
    } else { // increment cycle counter
        $cleanLogsCycle++;
    }

    sleep($SCRIPT_SLEEP_TIME);
}
