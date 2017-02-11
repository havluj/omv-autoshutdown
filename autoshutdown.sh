#!/bin/bash

sleep 10;
shutdown -Ph +$1 &>/dev/null &disown;