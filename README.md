
zapchupload
===========

Server-side chunk uploader.

[![Latest Stable Version](https://poser.pugx.org/bfitech/zapchupload/v/stable)](https://packagist.org/packages/bfitech/zapchupload)
[![Latest Unstable Version](https://poser.pugx.org/bfitech/zapchupload/v/unstable)](https://packagist.org/packages/bfitech/zapchupload)
[![Build Status](https://travis-ci.org/bfitech/zapchupload.svg?branch=master)](https://travis-ci.org/bfitech/zapchupload)
[![Codecov](https://codecov.io/gh/bfitech/zapchupload/branch/master/graph/badge.svg)](https://codecov.io/gh/bfitech/zapchupload)
[![GitHub license](https://img.shields.io/badge/license-MIT-blue.svg)](https://raw.githubusercontent.com/bfitech/zapchupload/master/LICENSE)

----

This package provides PHP the ability to upload possibly ultra-big file
in smaller chunks.

But why? What's wrong with standard HTTP form? Here are some primary
considerations:

pros:

- Server is less affected by `upload_max_filesize` directive. Filesize
  limit may come from disk space or filesystem limitation.
- Consequently, this can circumvent time limit set by
  `max_execution_time`.
- Each chunk can be processed for, e.g. fingerprinting, so there's a
  way to fail early when file is corrupt.

cons:

- Network overhead explodes because multiple requests must be made to
  upload even a single file.
- A special client must be crafted. Standard HTTP upload form will
  generally not work.


## Installation

```txt
$ composer require bfitech/zapchupload
$ vim index.php
```

## Tutorial

### server-side

Quick `index.php` setup:

```php
<?php

require_once __DIR__ . '/vendor/autoload.php';

use BFITech\ZapCore\Router;
use BFITech\ZapCore\Logger;
use BFITech\ZapChupload\ChunkUpload;

// create a logging service
$logger = new Logger;

// create a router
$core = (new Router)->config('logger', $logger);

// instantiate the chunk uploader class
$chup = new ChunkUpload(
    $core, '/tmp/tempdir', '/tmp/destdir',
    null, null, null, $logger);

// uploader route
$core->route('/upload', [$chup, 'upload'], 'POST']);

// downloader route for testing
$core->route('/', function($args) use($core) {
	$file = get['file'] ?? null;
	if ($file)
		$core->static_file('/tmp/destdir/' . $file);
});

// that's it
```

You can run it on your local machine with builtin server as follows:

```txt
$ php -S 0.0.0.0:9999
```

### client-side

Here's a simple client written in Python:

```py
#!/usr/bin/env python3

# chupload-client.py

import os
import sys
import stat
import hashlib

# use pip3 to install this
import requests

# from your toy service
UL_URL = 'http://localhost:9999/upload'

# from your toy service
DL_URL = 'http://localhost:9999/?file='

# ChunkUpload default prefix
PREFIX = "__chupload_"

# ChunkUpload default chunk size
CHUNK_SIZE = 1024 * 100


def upload(path):
    try:
        fst = os.stat(path)
    except FileNotFoundError:
        sys.exit(2)
    if stat.S_ISDIR(fst.st_mode):
        sys.exit(3)
    size = fst.st_size
    base = os.path.basename(path)

    # chupload must have this as _POST data
    data = {
        PREFIX + 'index': 0,
        PREFIX + 'size': size,
        PREFIX + 'name': base,
    }

    # calculate max number of chunks for test
    chunk_max = divmod(size, CHUNK_SIZE)[0]

    index = 0
    with open(path, 'rb') as fhn:

        while True:

            # chupload must have this as uploaded chunk, where the
            # filename doesn't matter
            files = {
                PREFIX + 'blob': ('noop', fhn.read(CHUNK_SIZE)),
            }

            # make request on each chunk
            resp = requests.post(UL_URL, files=files, data=data).json()
            assert(resp['errno'] == 0)
            rdata = resp['data']

            # upload complete
            if rdata['done'] == True:
                assert(index == chunk_max == rdata['index'])
                print("UPLOAD: OK")
                return "%s%s" % (DL_URL, rdata['path'])

            # increment index for next chunk
            index += 1
            data[PREFIX + 'index'] = index

    raise Exception("Oops! Something went wrong.")


def compare(path, url):
    resp = requests.get(url)
    assert(resp.status_code == 200)
    print("DOWNLOAD: OK")

    # compare download with local file
    rhash = hashlib.sha256(resp.content)
    lhash = hashlib.sha256(open(path, 'rb').read())
    assert(rhash.hexdigest() == lhash.hexdigest())
    print("COMPARE: OK")


if __name__ == '__main__':
    try:
        path = sys.argv[1]
    except IndexError:
        sys.exit(1)
    compare(path, upload(path))

```

that you can run from the CLI as follows:

```txt
$ python3 chupload-client.py ~/some-file.dat || echo FAIL
```

To see how it works with an AngularJS
[module](https://github.com/bfitech/angular-chupload) with
HTML5 File API under the hood:

```txt
$ cd /tmp
$ git clone git@github.com:bfitech/zapchupload.git
$ cd zapchupload
$ composer -vvv install -no
$ bower install
$ php -S 0.0.0.0:9999 -t tests/htdocs-test &
$ x-www-browser localhost:9999
```

## Documentation

Complete documentation is available with:

```txt
$ doxygen
$ x-www-browser docs/html/index.html
```

