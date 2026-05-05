# Contributing

Thanks for taking a look. This file explains how to build, test, and
ship a change against this repo. The [code of conduct][coc] applies in
every interaction here.

## Local setup

You need PHP 8.4 or 8.5 installed with the development headers and a
C compiler (clang on macOS, gcc on Linux).

```bash
git clone git@github.com:webpatser/php-resp3.git
cd php-resp3

# Build the extension
phpize
./configure --enable-resp3
make

# Run the test suite
make test TESTS="tests/"
```

The build writes `modules/resp3.so`. Load it on the command line for
ad-hoc work:

```bash
php -d extension=./modules/resp3.so -r 'echo resp3_version();'
```

If you want to run the integration smoke tests or the benchmark
suite, you also need Composer dev dependencies (Fledge fork and
amphp/redis):

```bash
composer install --ignore-platform-req=ext-resp3
```

The `--ignore-platform-req` flag is needed because Composer cannot see
your hand-built extension. Once you load it via `-d extension=...`,
PHP and Composer both find it.

## Making a change

A typical change touches one or two of these layers.

### Userland API change

If you add a method, a property, or a constructor argument:

1. Edit `resp3.stub.php`.
2. Regenerate `resp3_arginfo.h`:

   ```bash
   php /path/to/php-source/build/gen_stub.php \
     --minimum-php-version=8.4 resp3.stub.php
   ```

   On macOS Homebrew, the path is
   `/opt/homebrew/Cellar/php/<version>/lib/php/build/gen_stub.php`.

3. Implement or update the C method bodies in `resp3.c`.

PHP 8.4 is the project minimum, so the generated arginfo can use
`zend_register_internal_class_with_flags()` directly. No hand tweak
needed.

### State machine change

If you touch `resp3_parser.c`:

1. Add or update `tests/0NN_*.phpt` cases that exercise the new
   behaviour, including the unhappy paths.
2. Verify `make test TESTS="tests/"` is fully green.
3. Run the streaming tests too: they catch state-machine bugs that
   single-feed tests miss.
4. Update `ARCHITECTURE.md` if the state diagram or pause/resume
   contract changes.

### Bench or benchmark change

If you add or modify a scenario in `bench/`:

1. Make the script's header explain exactly what the loop does and
   what it does not. Avoid the "end-to-end" label unless the loop
   genuinely covers an application use case end to end.
2. Refresh `bench/results/SUMMARY.md` and `BENCHMARKS.md` if the
   numbers move.
3. Verify parity via `bench/validate_01_structure_parity.php` if you
   touched anything that could change parsed output structure.

### Fixture refresh

```bash
brew install socat                       # macOS
sudo apt-get install socat               # Debian, Ubuntu
tools/capture_fixtures.sh                # against 127.0.0.1:6379
```

The script captures real wire bytes from a running Redis or Valkey.
Each fixture session sends `HELLO 3` and then the target command, so
each `.bin` holds two messages.

## Tests

### phpt

Standard PHP test format. Each test under `tests/*.phpt` declares its
expected output; `make test` runs them all.

Useful invocations:

```bash
make test TESTS="tests/030_streaming_byte_by_byte.phpt"   # one test
make test TESTS="tests/05*.phpt"                          # the security set
make test TESTS="-m tests/"                               # all under Valgrind
```

The Valgrind run requires Valgrind installed and `USE_ZEND_ALLOC=0`
in the environment so PHP's arena allocator does not hide leaks. CI
runs this on Linux for every push.

### Smoke tests

`examples/fledge_smoke.php` and `examples/amphp_smoke.php` drive a
real Redis round trip through the C parser via the adapter classes.
They need a running Redis or Valkey on `127.0.0.1:6379`.

```bash
php -d extension=./modules/resp3.so examples/fledge_smoke.php
php -d extension=./modules/resp3.so examples/amphp_smoke.php
```

## Pull requests

Open one PR per logical change. Use the PR template; tick what
applies and explain anything you skipped.

Commits in this repo do not include AI attribution (no
`Co-Authored-By: Claude`, `Generated with`, etc). Keep the message
focused on the why, not the what; the diff already shows the what.

CI runs the full suite on Ubuntu 24.04 (x64 and ARM64), macOS 15,
Alpine 3.22, and ZTS variants. A red CI is a blocker. If you can
fix it, push the fix; if you cannot, mention it in the PR so the
reviewer can pair on it.

## Reporting bugs and security issues

Open a GitHub issue using the bug report template. For security
issues, follow `SECURITY.md` and email `oss@downsized.nl` instead
of opening a public issue.

[coc]: ./CODE_OF_CONDUCT.md
