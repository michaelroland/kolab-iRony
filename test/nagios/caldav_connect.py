#!/usr/bin/python
#
# Copyright 2010-2013 Kolab Systems AG (http://www.kolabsys.com)
#
# Thomas Bruederli <bruederli@kolabsys.com>
#
# This program is free software; you can redistribute it and/or modify
# it under the terms of the GNU General Public License as published by
# the Free Software Foundation; version 3 or, at your option, any later version
#
# This program is distributed in the hope that it will be useful,
# but WITHOUT ANY WARRANTY; without even the implied warranty of
# MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
# GNU Library General Public License for more details.
#
# You should have received a copy of the GNU General Public License
# along with this program; if not, write to the Free Software
# Foundation, Inc., 59 Temple Place - Suite 330, Boston, MA 02111-1307, USA.
#

import sys

from dav_utils import *
from urllib import quote as urlencode
from httplib import HTTPException

PORPFIND_CALENDARS = "<?xml version='1.0' encoding='utf-8' ?><D:propfind xmlns:D='DAV:'><D:prop><D:displayname/><D:resourcetype/></D:prop></D:propfind>"

def main(args):
    exit = EXIT_OK
    if not test_http_auth(args):
        exit = EXIT_CRITICAL
        msg = "FATAL: HTTP Auth"
    if exit == EXIT_OK and not test_propfind_cal(args):
        exit = EXIT_WARNING
        msg = "WARNING: PROPFIND to user calendars failed"

    if exit is not EXIT_OK:
        print >> sys.stderr, msg

    sys.exit(exit)

def test_propfind_cal(args):
    conn = get_connection(args)
    ret = False

    try:
        headers = { "Content-Type": "text/xml", "Authorization": basic_auth(args) }
        conn.request("PROPFIND", abs_path("/calendars/" + urlencode(args.user) + "/", args), PORPFIND_CALENDARS, headers)
        res = conn.getresponse()
        if res.status == 207 and check_header(res, "dav") and res.read().find('<d:multistatus'):
            ret = True
    except (HTTPException, Exception) as e:
        print >> sys.stderr, str(e)

    conn.close()
    return ret


if __name__ == "__main__":
    main(getopts())
