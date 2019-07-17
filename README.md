# OMV autoshutdown

A bunch of scripts that determine whether your (OpenMediaVault) media server is active.

If the server is determined to be inactive, an autoshutdown sequence is innitiated. If the server is deemed active, all previously initiated sequences will be terminated.

If any one of these is active or running, the server is deemed to be active:

* plex conversions
* plex active streams
* transmission downloads
* active SSH connections

---

For configuration, see `config.ini`.
