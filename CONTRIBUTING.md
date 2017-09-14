Contributing to Zap\*
====================


Find a bug? Help us fix it.

0.  Fork from Github.

1.  Clone your fork and load dependencies:

    ```txt
    $ git clone git@github.com:${YOUR_GITHUB_USERNAME}/zapcore.git
    $ cd zapcore
    $ composer -vvv install -no
    ```

2.  Make your changes. Sources are in `./src` and occasionally also
    in `./dev` directories.

    ```txt
    $ # do your thing, e.g.:
    $ vim src/Router.php
    ```

3.  Adjust the tests. Make sure there's no failure. Tests are in
    `./tests` directory.

    ```txt
    $ # adjust tests, e.g.:
    $ vim tests/RouterTest.php
    $ # run tests
    $ phpunit || echo 'Boo!'
    ```

4.  Make sure code coverage is at 100% or close. If you have
    [Xdebug](https://xdebug.org/) installed, coverage report is
    available with:

    ```txt
    $ phpunit
    $ x-www-browser docs/coverage/index.html
    ```

5.  Make sure coding convention is met as much as possible. For
    automated check, use [code sniffer](https://github.com/squizlabs/PHP_CodeSniffer)
    and [mess detector](https://github.com/phpmd/phpmd) rulesets
    that come with [`zapcore`](https://github.com/bfitech/zapcore)
    repository:

    ```txt
    $ export PATH=~/.composer/vendor/bin:$PATH
    $
    $ # coding convention compliance with phpcs
    $ wget https://raw.githubusercontent.com/bfitech/zapcore/master/phpcs.xml
    $ composer global require squizlabs/php_codesniffer
    $ phpcs
    $
    $ # static analysis with phpmd
    $ wget https://raw.githubusercontent.com/bfitech/zapcore/master/phpmd.xml
    $ composer global require phpmd/phpmd
    $ phpmd ./src text ./phpmd.xml
    ```

6.  Push to your fork and submit a Pull Request.

