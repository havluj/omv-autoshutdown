# OMV autoshutdown

A bunch of scripts that determine whether your (OpenMediaVault) media server is active.

If the server is deemed to be inactive, an auto-shutdown sequence is initiated. If the server is deemed to be active, all previously initiated sequences will be terminated.

## Monitored services
* Plex conversions
* Plex active streams
* Transmission downloads
* active SSH connections

## Configuration
For configuration, see `config.ini`.
