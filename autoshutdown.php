<?php

/**
 * This autoshutdown script sets the shutdown countdown if none of the
 * following services are active (being used at the moment): Plex,
 * Transmission client, Samba shares, or SSH. Cancels the shutdown countdown
 * if any one of the above listed services becomes active.
 *
 * @author Jan Havluj <jan@havluj.eu>
 */

/****************************************************************************
 *                          <SCRIPT CONFIGURATION>                          *
 ****************************************************************************/

// Script time intervals
/**
 * How often should the script check for service activity
 * (in seconds).
 */
$SCRIPT_SLEEP_TIME = 60;

/**
 * When no service is active, the script will set up
 * a shutdown countdown - this number configures in how
 * many minutes will the server shutdown if it does not
 * become active.
 */
$SHUTDOWN_COUNTDOWN = 15;

// Plex configuration
$PLEX_PORT = 32400;
$PLEX_TOKEN = "";

// Transmission configuration
$TRANSIMISSION_PORT = 9091;

/****************************************************************************
 *                          </SCRIPT CONFIGURATION>                         *
 ****************************************************************************/


$DEBUG = TRUE;
$SERVER_IP = "localhost";

function log_message($message, $shutdownTermination = FALSE)
{
	if ($GLOBALS["DEBUG"]) {
		print($message);
		file_put_contents("/opt/autoshutdown/log.txt", date("Y-m-d H:i:s") . " - " . $message . "\n", FILE_APPEND);
		if ($shutdownTermination) {
			print(" - shutdown terminated\n");
		} else {
			print("\n");
		}
	}
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
			log_message("there is/are " . $mediaStreamingCnt . " media stream(s)", TRUE);

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
			log_message("there is/are " . $mediaTranscodingCnt . " media transcoding", TRUE);

			return TRUE;
		}
	} catch (Exception $e) {
		log_message("retrieving or parsing active transcodings failed, interpreting as inactive!");
	}

	return FALSE;
}

function isTransmissionActive($serverAddress, $port)
{
	try {
		$url = "http://" . $serverAddress . ":" . $port . "/transmission/rpc";
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
				"header"  => "X-Transmission-Session-Id: " . $transmissionSessionId . "\r\n"
					. "Content-type: application/json\r\n"
					. "Content-Length: " . strlen($data) . "\r\n",
				"method"  => "POST",
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
					log_message("at least one of the downloads is not finished yet", TRUE);

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
		log_message("there is/are " . count($output) . " active samba connection(s)", TRUE);

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
		log_message("there is/are " . count($output) . " user(s) logged in", TRUE);

		return TRUE;
	} else {
		log_message("there is noone logged in");
	}

	return FALSE;
}

// main cycle
$isAutoshutdownSet = FALSE;
while (TRUE) {
	if (isAnybodyLoggedIn() || isSambaActive() || isPlexActive($SERVER_IP, $PLEX_PORT, $PLEX_TOKEN)
		|| isTransmissionActive($SERVER_IP, $TRANSIMISSION_PORT)
	) {
		if ($isAutoshutdownSet) {
			log_message("at least one service became active, canceling the shutdown ...");
			$outputCode = exec("shutdown -c &>/dev/null");
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
			$outputCode = exec("/opt/autoshutdown/autoshutdown.sh " . $SHUTDOWN_COUNTDOWN . " &>/dev/null &");
			if ($outputCode == 0) {
				$isAutoshutdownSet = TRUE;
				log_message("shutdown has been successfully set up");
			} else {
				log_message("an error occurred while setting up shutdown");
			}
		}
	}

	log_message("-------------------------------------------------------------");
	sleep($SCRIPT_SLEEP_TIME);
}
