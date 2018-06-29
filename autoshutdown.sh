#!/bin/bash

sleep 10;
shutdown -Ph +$1 --no-wall &>/dev/null &disown;