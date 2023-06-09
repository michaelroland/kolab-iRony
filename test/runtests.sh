#!/bin/bash
#
# Copyright (C) 2013, Kolab Systems AG <contact@kolabsys.com>
#
# This program is free software: you can redistribute it and/or modify
# it under the terms of the GNU Affero General Public License as
# published by the Free Software Foundation, either version 3 of the
# License, or (at your option) any later version.
#
# This program is distributed in the hope that it will be useful,
# but WITHOUT ANY WARRANTY; without even the implied warranty of
# MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
# GNU Affero General Public License for more details.
#
# You should have received a copy of the GNU Affero General Public License
# along with this program. If not, see <http://www.gnu.org/licenses/>.
##
# Initializes the CalDAVTester environment and runs all test scripts
#

CALDAVTESTER=$1
PYTHONPATH=`$CALDAVTESTER/run.py -p`

export PYTHONPATH="$PYTHONPATH:$CALDAVTESTER/../pycalendar/src"

$CALDAVTESTER/testcaldav.py --print-details-onfail -s serverinfo.xml \
    CardDAV/current-user-principal.xml \
    CardDAV/ab-client.xml \
    CardDAV/propfind.xml \
    CardDAV/put.xml \
    CardDAV/directory-gateway.xml \
    CalDAV/current-user-principal.xml \
    CalDAV/caldavIOP.xml \
    CalDAV/ctag.xml \
    CalDAV/attachments.xml \
    CalDAV/scheduleprops.xml \
    CalDAV/schedulepost.xml \
    CalDAV/implicitimip.xml

