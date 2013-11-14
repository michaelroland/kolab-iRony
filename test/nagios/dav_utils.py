# Utility functions to be used in CalDAV/CardDAV nagios scripts
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

import sys, argparse

from base64 import b64encode
from httplib import HTTPConnection, HTTPSConnection, HTTPException

# nagios return codes
EXIT_OK = 0
EXIT_WARNING = 1
EXIT_CRITICAL = 2

PORPFIND_PRINCIPAL = "<?xml version='1.0' encoding='utf-8' ?><D:propfind xmlns:D='DAV:'><D:prop><D:principal-collection-set/></D:prop></D:propfind>"

def getopts():
    parser = argparse.ArgumentParser()
    parser.add_argument('-s', '--server', dest='server', required=True)
    parser.add_argument('-u', '--user', dest='user', required=True)
    parser.add_argument('-p', '--pass', dest='passwd', required=True)
    parser.add_argument('-d', '--dir', dest='dir')
    return parser.parse_args()

def get_connection(args):
    if args.server.find("https") == 0:
        conn = HTTPSConnection(re.sub(r'^https://', r'', args.server))
    else:
        conn = HTTPConnection(args.server)
    return conn

def check_header(res, hdr):
    for h,v in res.getheaders():
        if h == hdr:
            return v
    return None

def basic_auth(args):
    return "Basic " + b64encode(args.user + ":" + args.passwd)

def abs_path(path, args):
    base = args.dir.rstrip('/') if args.dir else ''
    return base + path

def test_http_auth(args):
    conn = get_connection(args)
    ret = False

    try:
        # check unauthenticated response
        headers = { "Content-Type": "text/xml" }
        conn.request("PROPFIND", abs_path("/", args), PORPFIND_PRINCIPAL, headers)
        res = conn.getresponse()
        if res.status == 401 and check_header(res, "www-authenticate") and res.read().find('<d:error'):
            # check user authentication
            headers = { "Content-Type": "text/xml", "Authorization": basic_auth(args) }
            conn.request("PROPFIND", abs_path("/", args), PORPFIND_PRINCIPAL, headers)
            res = conn.getresponse()
            if res.status == 207 and check_header(res, "dav"):
                ret = True
    except (HTTPException, Exception) as e:
        print >> sys.stderr, str(e)

    conn.close()
    return ret

