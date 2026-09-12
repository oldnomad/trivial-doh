<!--
SPDX-FileCopyrightText: 2026 Alec Kojaev <alec@kojaev.name>
SPDX-License-Identifier:  CC0-1.0
-->
# Simple DoH Server

This is a simple one-file DoH (DNS-over-HTTPS) server that can be
installed on any web server supporting PHP.

The server forwards all incoming requests to a set of "real" DNS
servers specified in the configuration.

The server is fully compliant with [RFC 8484](https://www.rfc-editor.org/info/rfc8484/).

## Installation

There's no special installation needed. Simply copy `doh.php` to
any location where your web server can execute PHP scripts.

If you want to customize the configuration, copy `doh.json` (see below)
to the same location.

If you already have an enabled site, it's better to place the script
in a separate directory. For example (Apache):

```
<VirtualHost *:443>
    ...your existing configuration here...

    Alias /doh /srv/www/doh/doh.php
    <Directory "/srv/www/doh">
        Satisfy Any
    </Directory>
</VirtualHost>
```

## Configuration

Configuration can be specified in a JSON file named `doh.json`,
placed in the same directory as the script itself. This file
should contain a JSON object with following properties:

- `"timeout"`: integer, specifies DNS request timeout to use, in seconds.
  Default is 5.
- `"servers"`: array of strings, specifies DNS servers to forward requests
  to. The servers can be specified by name or by IP address. If IPv6
  address is used, it must be enclosed in square brackets (e.g.
  `"[2001:db8::1]"`). Default set contains all Google public DNS servers,
  both IPv4 and IPv6 addresses.
- `"debug"`: integer, specifies debugging level. Default is 0 (no debug messages).

## Running from command line

The script can be also run from the command line for testing purposes.

If the first argument is `GET`, the second argument is interpreted as a
Base64URL-encoded DNS message:

```
$ php doh.php GET AAABAAABAAAAAAAAA3d3dwdleGFtcGxlA2NvbQAAAQAB
REQUEST: 00000100000100000000000003777777076578616d706c6503636f6d0000010001
SERVER: [2001:4860:4860::8888]:53
RESPONSE: 00008180000100020000000003777777076578616d706c6503636f6d0000010001c00c000100010000012c0004ac4293f3c00c000100010000012c00046814179a
```

If the first argument is `POST`, DNS message is read from the standard input:

```
$ echo '00000100000100000000000003777777076578616d706c6503636f6d0000010001' | xxd -r -p | php doh.php POST
REQUEST: 00000100000100000000000003777777076578616d706c6503636f6d0000010001
SERVER: 8.8.8.8:53
RESPONSE: 00008180000100020000000003777777076578616d706c6503636f6d0000010001c00c000100010000012c0004082f4506c00c000100010000012c000408067006

```
