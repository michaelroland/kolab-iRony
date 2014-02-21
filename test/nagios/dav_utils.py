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

import sys, os, argparse

from base64 import b64encode
from ConfigParser import RawConfigParser
from httplib import HTTPConnection, HTTPSConnection, HTTPException

# nagios return codes
EXIT_OK = 0
EXIT_WARNING = 1
EXIT_CRITICAL = 2

PORPFIND_PRINCIPAL = "<?xml version='1.0' encoding='utf-8' ?><D:propfind xmlns:D='DAV:'><D:prop><D:principal-collection-set/></D:prop></D:propfind>"

def getopts():
    parser = argparse.ArgumentParser()
    parser.add_argument('-s', '--server', dest='server')
    parser.add_argument('-u', '--user', dest='user')
    parser.add_argument('-p', '--pass', dest='passwd')
    parser.add_argument('-d', '--path', dest='path')
    parser.add_argument('--extra-opts', '--extra-opts', dest='extraopts')
    opts = parser.parse_args()

    # read arguments from --extra-opts config section
    err = False
    if opts.extraopts:
        extra = opts.extraopts.split('@');
        if len(extra) < 2:
            # check default config file locations
            for path in [ '/etc/nagios/plugins.ini', '/usr/local/nagios/etc/plugins.ini', '/usr/local/etc/nagios/plugins.ini', '/etc/opt/nagios/plugins.ini' ]:
                if os.path.exists(path):
                    extra.append(path)
                    break

        if len(extra) < 2 or not os.path.exists(extra[1]):
            print >> sys.stderr, "--extra-opts error: no nagios plugins.ini file found\n"
            err = True
        else:
            try:
                config = RawConfigParser()
                config.read(extra[1])
                for opt in config.items(extra[0]):
                    (k,v) = opt
                    opts.__dict__[k] = v

            except Exception as e:
                print >> sys.stderr, "--extra-opts error:", e, "\n"
                err = True

    # check required arguments
    for k in [ 'server', 'user', 'passwd' ]:
        if opts.__dict__[k] is None:
            err = True


    if err:
        parser.print_usage()
        sys.exit(EXIT_CRITICAL)

    return opts

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
    base = args.path.rstrip('/') if args.path else ''
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

