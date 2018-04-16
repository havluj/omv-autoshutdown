# omv-autoshutdown

Tutorial will be available soon on my website. 

In short this simple project shuts down my open media vault based on it current usage.


## todo list
- clear logs function
- run first check after couple minutes, not 
- transmission login
- check if plex is running during the first run (if not, stop the service, try to start it again. If that does not work, restart the server and set a flag for the next time - so we do not restart indefinetely)
- accept plex token, transmission username and pwd as cmd arguments

## how to deploy
- change the plex token
- change transmission username and password
- set debug to true
- start the script in omv web-based settings on server start